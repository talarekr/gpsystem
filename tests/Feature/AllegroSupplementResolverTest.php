<?php

namespace Tests\Feature;

use App\Services\MigrationExport\AllegroSupplementResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Synthetic fixtures only. Resolver must not read live rows, log to SQL or call providers. */
class AllegroSupplementResolverTest extends TestCase
{
    public function test_malformed_parameter_shapes_retain_raw_evidence_and_explicit_incomplete_markers(): void
    {
        $resolver = app(AllegroSupplementResolver::class);
        foreach ([['id' => null, 'values' => ['unknown']], ['id' => ['bad'], 'values' => []], ['id' => '01', 'values' => 'bad'],
            ['id' => '01', 'valuesIds' => [['bad']]], ['id' => '01', 'rangeValue' => 'bad']] as $parameter) {
            $history = $resolver->historical($parameter, 'legacy_offer_payload', '00123', null, null, null);
            $this->assertSame('malformed_historical_parameter', $history[0]['record_issue']);
            $result = $this->resolve([], history: $history, fresh: true);
            $row = collect($result['rows'])->firstWhere('reason_code', 'supplement_preview_record_error');
            $this->assertNotNull($row);
            $this->assertFalse($row['is_preferred']);
            $this->assertTrue($row['readback_unavailable']);
            $this->assertSame('SCHEMA_UNKNOWN', $result['completeness'][0]['status']);
        }
    }

    private function definition(string $id, string $name, array $extra = []): array
    {
        return array_replace_recursive(['id' => $id, 'name' => $name, 'type' => 'dictionary', 'required' => false, 'dictionary' => [['id' => 'v1', 'value' => 'First'], ['id' => 'v2', 'value' => 'Second']], 'options' => ['describesProduct' => false, 'requiredForProduct' => false], 'restrictions' => ['multipleChoices' => true]], $extra);
    }

    private function resolve(array $definitions, array $manual = [], array $history = [], array $rules = [], array $part = [], ?array $car = null, bool $fresh = false): array
    {
        Http::preventStrayRequests();
        DB::connection()->beforeExecuting(fn () => throw new \LogicException('Resolver may not execute any SQL'));
        $cache = ['schema_snapshot_id' => 'schema-1', 'raw_response' => ['parameters' => $definitions]];
        if ($fresh) {
            $cache += ['fresh_schema' => true, 'offer_schema_available' => true, 'product_schema_available' => true, 'product_raw_response' => ['parameters' => []]];
        }
        $result = app(AllegroSupplementResolver::class)->resolve(array_replace(['id' => '0001', 'category_id' => '7', 'part_number' => '0000123'], $part), $car, ['id' => '7', 'name' => 'Category'], [['external_category_id' => '00123', 'channel' => 'allegro_main']], $manual, $history, fn ($category) => $category === '00123' ? $cache : null, fn () => $rules, '2026-01-01T00:00:00+00:00', freshRequired: $fresh);
        Http::assertNothingSent();

        return $result;
    }

    public function test_manual_required_multivalue_and_optional_unresolved_are_preserved(): void
    {
        $manual = array_map(fn ($value) => ['allegro_category_id' => '00123', 'parameter_id' => '01', 'parameter_name' => 'Choice', 'value_id' => $value, 'value_label' => $value], ['v1', 'v2']);
        $result = $this->resolve([$this->definition('01', 'Choice', ['required' => true]), $this->definition('02', 'Optional')], $manual);
        $row = collect($result['rows'])->firstWhere('source', 'manual_selection');
        $this->assertSame(['v1', 'v2'], $row['values_ids']);
        $this->assertSame('0001', $row['part_id']);
        $this->assertSame('00123', $row['allegro_category_id']);
        $this->assertSame('01', $row['parameter_id']);
        $this->assertTrue($row['required_snapshot']);
        $this->assertTrue($row['multiple_choices']);
        $this->assertSame('complete', $row['completeness_status']);
        $this->assertSame([], $result['completeness'][0]['missing_required_parameter_ids']);
        $this->assertSame('partial', collect($result['rows'])->firstWhere('parameter_id', '02')['completeness_status']);
        $this->assertFalse(collect($result['rows'])->firstWhere('parameter_id', '02')['required_snapshot']);
    }

    public function test_part_car_and_mapping_use_frozen_values_without_sql_or_logs(): void
    {
        $result = $this->resolve([
            $this->definition('227345', 'Numer katalogowy części', ['type' => 'string', 'required' => true]),
            $this->definition('129591', 'Typ samochodu', ['dictionary' => [['id' => '129591_1', 'value' => 'Samochody osobowe']]]),
            $this->definition('mapped', 'Special'),
        ], rules: [['local_category_id' => '7', 'allegro_category_id' => '00123', 'parameter_id' => 'mapped', 'fixed_value_id' => 'v2', 'fixed_value_label' => null, 'enabled' => true]], car: ['id' => '8', 'body_type' => 'sedan']);
        $rows = collect($result['rows']);
        $this->assertSame(['0000123'], $rows->firstWhere('parameter_id', '227345')['values']);
        $this->assertSame('computed_from_part', $rows->firstWhere('parameter_id', '227345')['source']);
        $this->assertSame(['129591_1'], $rows->firstWhere('parameter_id', '129591')['values_ids']);
        $this->assertSame('computed_from_car', $rows->firstWhere('parameter_id', '129591')['source']);
        $this->assertSame('parameter_mapping', $rows->firstWhere('parameter_id', 'mapped')['source']);
        $this->assertSame(['v2'], $rows->firstWhere('parameter_id', 'mapped')['values_ids']);
    }

    public function test_conflicting_manual_and_history_are_both_kept_and_required_not_assumed_satisfied(): void
    {
        $history = app(AllegroSupplementResolver::class)->historical(['parameters' => [['id' => '01', 'valuesIds' => ['v2']]]], 'marketplace_listing_payload', '00123', '99', '2', null);
        $result = $this->resolve([$this->definition('01', 'Choice', ['required' => true])], [['allegro_category_id' => '00123', 'parameter_id' => '01', 'value_id' => 'v1']], $history);
        $this->assertCount(2, $result['rows']);
        $this->assertCount(1, $result['conflicts']);
        $this->assertSame(['conflict'], array_values(array_unique(array_column($result['rows'], 'completeness_status'))));
        $this->assertSame([], $result['completeness'][0]['missing_required_parameter_ids']);
        $this->assertSame('99', collect($result['rows'])->firstWhere('source', 'marketplace_listing_payload')['source_listing_id']);
    }

    public function test_missing_schema_retains_historical_values_with_unknown_flags(): void
    {
        $resolver = app(AllegroSupplementResolver::class);
        $history = $resolver->historical(['category' => ['id' => 'other'], 'parameters' => [['id' => 'old', 'values' => ['zero', '0']]]], 'marketplace_listing_payload', null, '99', '2', null);
        $result = $this->resolve([], history: $history);
        $row = collect($result['rows'])->firstWhere('parameter_id', 'old');
        $this->assertSame('schema_unknown', $row['completeness_status']);
        $this->assertSame('schema_unavailable_locally', $row['reason_code']);
        foreach (['required_snapshot', 'required_for_product_snapshot', 'describes_product_snapshot', 'multiple_choices', 'parameter_type', 'schema_snapshot_id', 'valid_for_republication'] as $key) {
            $this->assertNull($row[$key], $key);
        }
        $this->assertSame(['zero', '0'], $row['values']);
    }

    public function test_parameter_absent_from_schema_and_product_set_ranges_survive(): void
    {
        $resolver = app(AllegroSupplementResolver::class);
        $history = $resolver->historical(['category' => ['id' => '00123'], 'productSet' => [
            ['product' => ['parameters' => [['id' => 'old', 'rangeValue' => ['from' => '0', 'to' => '10']]]]],
            ['product' => ['parameters' => [['id' => 'old', 'rangeValue' => ['from' => '20', 'to' => '30']]]]],
        ]], 'marketplace_listing_payload', null, '99', '2', null);
        $result = $this->resolve([], history: $history);
        $rows = array_values(array_filter($result['rows'], fn ($row) => $row['parameter_id'] === 'old'));
        $this->assertCount(2, $rows);
        $this->assertSame([0, 1], array_column($rows, 'product_set_index'));
        $this->assertSame(['product', 'product'], array_column($rows, 'parameter_scope'));
        $this->assertSame('0', $rows[0]['range_value']['from']);
        $this->assertSame('historical_only', $rows[0]['completeness_status']);
        $this->assertSame('parameter_absent_from_local_schema', $rows[0]['reason_code']);
        $this->assertSame([], $result['conflicts']);
    }

    public function test_review_metadata_and_legacy_parameters_are_preserved(): void
    {
        $result = $this->resolve([], part: ['legacy_payload' => ['_allegro_parameters' => [['id' => 'old', 'valuesIds' => ['old_value']]]], 'review_metadata' => ['marketplace_prepare_results' => ['allegro' => ['dynamic_allegro_parameters' => ['category_id' => '00123', 'fields' => [['parameter_id' => 'review', 'parameter_name' => 'Choice', 'saved_value_ids' => ['selected']]]]]]]]);
        $this->assertSame('legacy_offer_payload', collect($result['rows'])->firstWhere('parameter_id', 'old')['source']);
        $this->assertSame('review_metadata', collect($result['rows'])->firstWhere('parameter_id', 'review')['source']);
    }

    public function test_invalid_manual_value_is_preserved_as_partial(): void
    {
        $result = $this->resolve([$this->definition('01', 'Choice', ['required' => true])], [['allegro_category_id' => '00123', 'parameter_id' => '01', 'value_id' => 'removed']]);
        $this->assertSame(['removed'], $result['rows'][0]['values_ids']);
        $this->assertSame('partial', $result['rows'][0]['completeness_status']);
        $this->assertSame(['01'], $result['completeness'][0]['missing_required_parameter_ids']);
    }

    public function test_id_only_summaries_and_unknown_historical_category_are_not_fabricated(): void
    {
        $resolver = app(AllegroSupplementResolver::class);
        $history = $resolver->historical(['request_summary' => ['category_id' => '00123', 'product_parameter_ids' => ['01']]], 'marketplace_listing_payload', null, '99', '2', null);
        $result = $this->resolve([$this->definition('01', 'Choice')], history: $history, part: ['legacy_payload' => ['_allegro_parameters' => [['id' => 'orphan-category', 'valuesIds' => ['old']]]]]);
        $row = collect($result['rows'])->firstWhere('source_listing_id', '99');
        $this->assertSame([], $row['values_ids']);
        $this->assertSame('product', $row['parameter_scope']);
        $this->assertSame('historical_parameter_id_only', $row['reason_code']);
        $this->assertNull(collect($result['rows'])->firstWhere('parameter_id', 'orphan-category')['allegro_category_id']);
    }

    public function test_mapping_cannot_lazy_load_live_relationships(): void
    {
        $result = $this->resolve([$this->definition('mapped', 'Special', ['required' => true])], rules: [['local_category_id' => '7', 'allegro_category_id' => '00123', 'parameter_id' => 'mapped', 'source_field' => 'images.0.path', 'enabled' => true]]);
        $this->assertSame('partial', $result['rows'][0]['completeness_status']);
        $this->assertSame(['mapped'], $result['completeness'][0]['missing_required_parameter_ids']);
    }

    public function test_builder_valid_subset_and_original_manual_values_are_both_kept(): void
    {
        $result = $this->resolve([$this->definition('01', 'Choice', ['required' => true])], array_map(fn ($value) => ['allegro_category_id' => '00123', 'parameter_id' => '01', 'value_id' => $value], ['v1', 'removed']));
        $this->assertCount(2, $result['rows']);
        $this->assertSame(['v1', 'removed'], collect($result['rows'])->firstWhere('resolution_role', 'stored_source')['values_ids']);
        $this->assertSame(['v1'], collect($result['rows'])->firstWhere('resolution_role', 'builder_result')['values_ids']);
        $this->assertCount(1, $result['conflicts']);
    }

    public function test_product_required_flag_and_zero_values_are_not_converted_to_missing(): void
    {
        $result = $this->resolve([
            $this->definition('227345', 'Numer katalogowy części', ['type' => 'string', 'required' => false, 'options' => ['requiredForProduct' => true, 'describesProduct' => true]]),
            $this->definition('mapped', 'Special', ['dictionary' => [['id' => '0', 'value' => 'Zero']]]),
        ], rules: [['local_category_id' => '7', 'allegro_category_id' => '00123', 'parameter_id' => 'mapped', 'fixed_value_id' => '0', 'enabled' => true]], part: ['part_number' => '0']);
        $row = collect($result['rows'])->firstWhere('parameter_id', '227345');
        $this->assertSame(['0'], $row['values']);
        $this->assertFalse($row['required_snapshot']);
        $this->assertTrue($row['required_for_product_snapshot']);
        $this->assertSame('product', $row['parameter_scope']);
        $this->assertSame(['0'], collect($result['rows'])->firstWhere('parameter_id', 'mapped')['values_ids']);
        $this->assertSame([], $result['completeness'][0]['missing_required_parameter_ids']);
    }

    public function test_fresh_conflict_is_not_counted_as_a_resolved_required_value(): void
    {
        $result = $this->resolve([$this->definition('01', 'Choice', ['required' => true])],
            manual: [['allegro_category_id' => '00123', 'parameter_id' => '01', 'value_id' => 'v1']],
            history: [['allegro_category_id' => '00123', 'parameter_id' => '01', 'source' => 'marketplace_listing_payload', 'parameter_scope' => 'offer', 'values_ids' => ['v2']]], fresh: true);
        $this->assertSame('CONFLICT', $result['completeness'][0]['status']);
        $this->assertSame(0, $result['completeness'][0]['required_resolved_count']);
        $this->assertSame(['01'], $result['completeness'][0]['missing_required_parameter_ids']);
    }

    public function test_fresh_conditional_requirements_are_unknown_until_evaluated(): void
    {
        $result = $this->resolve([$this->definition('01', 'Choice', ['required' => true, 'dependencies' => ['other_parameter' => '02']])],
            manual: [['allegro_category_id' => '00123', 'parameter_id' => '01', 'value_id' => 'v1']], fresh: true);
        $this->assertSame('SCHEMA_UNKNOWN', $result['completeness'][0]['status']);
    }
}
