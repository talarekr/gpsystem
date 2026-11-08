<?php

namespace App\Services\MigrationExport;

use App\Models\Car;
use App\Models\MarketplaceCategoryMapping;
use App\Models\Part;
use App\Models\PartCategory;
use App\Services\Marketplace\AllegroOfferParametersBuilder;

/** Explicit provenance candidates, not a publishing payload or an authority about today's Allegro schema. */
final class AllegroSupplementResolver
{
    public const FIELDS = ['part_id', 'allegro_category_id', 'parameter_id', 'parameter_name_snapshot', 'parameter_type', 'multiple_choices', 'parameter_scope', 'product_set_index', 'values_ids', 'values', 'range_value', 'raw_value', 'required_snapshot', 'required_for_product_snapshot', 'describes_product_snapshot', 'schema_snapshot_id', 'source', 'source_field', 'source_listing_id', 'marketplace_account_id', 'captured_at', 'source_observed_at', 'completeness_status', 'reason_code', 'local_validation_status', 'resolution_role', 'candidate_id', 'conflict_id', 'valid_for_republication'];

    public function __construct(private AllegroOfferParametersBuilder $builder) {}

    /** Only known parameter-bearing structures are traversed; unrelated listing/customer data is not copied. */
    public function historical(array $payload, string $source, ?string $category, ?string $listing, ?string $account, ?string $observed, string $path = '', ?string $scope = null, ?int $productIndex = null, int $depth = 0): array
    {
        if ($depth > 8) {
            return [];
        }
        $category = self::text(data_get($payload, 'category.id') ?? $payload['_allegro_category_id'] ?? $payload['allegro_category_id'] ?? $payload['category_id'] ?? $category);
        $out = [];
        if (isset($payload['parameter_id']) && ! empty($payload['selected_value_ids'] ?? $payload['saved_value_ids'] ?? [])) {
            $payload['id'] = (string) $payload['parameter_id'];
            $payload['name'] = $payload['parameter_name'] ?? $payload['name'] ?? null;
            $payload['valuesIds'] = (array) ($payload['selected_value_ids'] ?? $payload['saved_value_ids']);
        }
        if (isset($payload['id']) && (isset($payload['valuesIds']) || isset($payload['values_ids']) || isset($payload['values']) || isset($payload['rangeValue']) || isset($payload['range_value']))) {
            $out[] = ['allegro_category_id' => $category, 'parameter_id' => (string) $payload['id'], 'parameter_name_snapshot' => self::text($payload['name'] ?? null),
                'values_ids' => array_map('strval', (array) ($payload['valuesIds'] ?? $payload['values_ids'] ?? [])), 'values' => (array) ($payload['values'] ?? []), 'range_value' => $payload['rangeValue'] ?? $payload['range_value'] ?? null,
                'raw_value' => array_intersect_key($payload, array_flip(['valuesIds', 'values_ids', 'values', 'rangeValue', 'range_value'])),
                'source' => $source, 'source_field' => $path, 'source_listing_id' => $listing, 'marketplace_account_id' => $account, 'source_observed_at' => $observed, 'parameter_scope' => $scope, 'product_set_index' => $productIndex];

            return $out;
        }
        if (array_is_list($payload)) {
            foreach ($payload as $i => $item) {
                if (is_array($item)) {
                    $out = array_merge($out, $this->historical($item, $source, $category, $listing, $account, $observed, $path.'.'.$i, $scope, $productIndex, $depth + 1));
                }
            }

            return $out;
        }
        foreach (['payload_parameters_ids' => 'offer', 'offer_parameter_ids' => 'offer', 'product_parameter_ids' => 'product', 'productSet_0_product_parameters_ids' => 'product'] as $key => $location) {
            if (! is_array($payload[$key] ?? null)) {
                continue;
            }
            foreach ($payload[$key] as $id) {
                if (! is_scalar($id)) {
                    continue;
                }
                $out[] = ['allegro_category_id' => $category, 'parameter_id' => (string) $id, 'values_ids' => [], 'values' => [], 'range_value' => null, 'raw_value' => ['parameter_id_only' => (string) $id],
                    'source' => $source, 'source_field' => $path.'.'.$key, 'source_listing_id' => $listing, 'marketplace_account_id' => $account, 'source_observed_at' => $observed, 'parameter_scope' => $location, 'product_set_index' => $location === 'product' ? 0 : null];
            }
        }
        foreach (['parameters', '_allegro_parameters', 'allegro_parameters', 'offer_parameters', 'payload_parameters', 'product_parameters', 'allegro_offer_parameters', 'allegro_product_parameters'] as $key) {
            if (! is_array($payload[$key] ?? null)) {
                continue;
            }
            $location = str_contains($key, 'product_parameters') ? 'product' : ($key === 'parameters' ? ($scope ?? 'offer') : (in_array($key, ['offer_parameters', 'payload_parameters', 'allegro_offer_parameters'], true) ? 'offer' : $scope));
            $out = array_merge($out, $this->historical($payload[$key], $source, $category, $listing, $account, $observed, $path.'.'.$key, $location, $location === 'product' ? ($productIndex ?? 0) : $productIndex, $depth + 1));
        }
        foreach (is_array($payload['productSet'] ?? null) ? $payload['productSet'] : [] as $i => $product) {
            if (is_array($product['product'] ?? null)) {
                $out = array_merge($out, $this->historical($product['product'], $source, $category, $listing, $account, $observed, $path.'.productSet.'.$i.'.product', 'product', (int) $i, $depth + 1));
            }
        }
        foreach (['payload', 'request', 'response', 'body', 'json', 'request_summary', 'response_summary', 'legacy_payload_json', 'marketplace_prepare_results', 'allegro', 'prepared_payload_preview_safe', 'dynamic_allegro_parameters', 'fields', 'parameter_source_diagnostics', 'product_parameter_diagnostics', 'offer_parameter_diagnostics'] as $key) {
            if (is_array($payload[$key] ?? null)) {
                $out = array_merge($out, $this->historical($payload[$key], $source, $category, $listing, $account, $observed, $path.'.'.$key, $scope, $productIndex, $depth + 1));
            }
        }

        return $out;
    }

    /** resolve one frozen Part; lookup reads only supplement indexes or frozen parent shards. */
    public function resolve(array $part, ?array $car, ?array $localCategory, array $categoryMappings, array $manual, array $historical, callable $schema, callable $rules, string $capturedAt): array
    {
        $category = self::text(data_get($part, 'review_metadata.marketplace_category_overrides.allegro.external_category_id'));
        usort($categoryMappings, fn ($a, $b) => (($a['channel'] ?? '') === 'allegro_main' ? 0 : 1) <=> (($b['channel'] ?? '') === 'allegro_main' ? 0 : 1) ?: ((string) ($a['id'] ?? '') <=> (string) ($b['id'] ?? '')));
        $category ??= self::text($categoryMappings[0]['external_category_id'] ?? null);
        $observed = self::text($part['updated_at'] ?? null);
        $historical = array_merge($historical,
            $this->historical((array) ($part['legacy_payload'] ?? []), 'legacy_offer_payload', null, null, null, $observed, 'parts.legacy_payload'),
            $this->historical((array) ($part['review_metadata'] ?? []), 'review_metadata', null, null, null, $observed, 'parts.review_metadata'));
        $candidates = $historical;
        $grouped = [];
        foreach ($manual as $selection) {
            $key = (string) $selection['allegro_category_id'].'/'.(string) $selection['parameter_id'];
            $grouped[$key][] = $selection;
        }
        foreach ($grouped as $selections) {
            $first = $selections[0];
            $candidates[] = ['allegro_category_id' => (string) $first['allegro_category_id'], 'parameter_id' => (string) $first['parameter_id'], 'parameter_name_snapshot' => $first['parameter_name'] ?? null,
                'values_ids' => array_values(array_unique(array_map(fn ($row) => (string) $row['value_id'], $selections))), 'values' => [], 'range_value' => null,
                'raw_value' => array_map(fn ($row) => array_intersect_key($row, array_flip(['value_id', 'value_label'])), $selections),
                'source' => 'manual_selection', 'source_field' => 'allegro_parameter_selections', 'source_observed_at' => $first['updated_at'] ?? null];
        }
        $categories = array_unique(array_filter([$category, ...array_column($candidates, 'allegro_category_id')], fn ($id) => $id !== null && $id !== ''));
        $model = (new Part)->forceFill($part);
        $model->setRelation('car', $car ? (new Car)->forceFill($car) : null);
        $model->setRelation('category', $localCategory ? (new PartCategory)->forceFill($localCategory) : null);
        foreach ($categories as $categoryId) {
            $cache = $schema((string) $categoryId);
            if ($cache === null) {
                continue;
            }
            $definitions = array_map(fn ($def) => $def + ['category_id' => (string) $categoryId], $cache['raw_response']['parameters'] ?? []);
            $mapping = (new MarketplaceCategoryMapping)->forceFill(['external_category_id' => (string) $categoryId]);
            $categoryRules = $rules((string) $categoryId);
            $result = $this->builder->buildFromSnapshot($model, $mapping, ['ok' => true, 'source' => 'frozen_local_cache', 'parameters' => $definitions], $manual, $categoryRules);
            foreach ($result['parameter_source_diagnostics'] ?? [] as $diagnostic) {
                if (! isset($diagnostic['id'])) {
                    continue;
                }
                $scope = ($diagnostic['parameter_location'] ?? null) === 'productSet[0].product.parameters' ? 'product' : 'offer';
                $manualResult = ($diagnostic['source'] ?? '') === 'allegro_parameter_selections.value_id';
                if ($manualResult) {
                    $validIds = $diagnostic['valuesIds'] ?? [];
                    if ($validIds === []) {
                        continue;
                    }
                    $matched = false;
                    foreach ($candidates as &$stored) {
                        if (($stored['source'] ?? null) !== 'manual_selection' || ($stored['allegro_category_id'] ?? null) !== (string) $categoryId || ($stored['parameter_id'] ?? null) !== (string) $diagnostic['id']) {
                            continue;
                        }
                        $saved = $stored['values_ids'];
                        $valid = $validIds;
                        sort($saved, SORT_STRING);
                        sort($valid, SORT_STRING);
                        if ($saved === $valid) {
                            $stored['resolution_role'] = 'stored_source_and_builder_result';
                            $stored['parameter_scope'] = $scope;
                            $matched = true;
                        }
                    }
                    unset($stored);
                    if ($matched) {
                        continue;
                    }
                }
                $field = (string) ($diagnostic['source_field'] ?? $diagnostic['source'] ?? '');
                $source = $manualResult ? 'manual_selection' : (($diagnostic['source'] ?? '') === 'category_mapping' ? 'parameter_mapping' : ((str_contains($field, 'car.') || str_contains($field, 'vehicle_snapshot.')) ? 'computed_from_car' : (($diagnostic['source'] ?? '') === 'not_resolved' ? 'unknown' : 'computed_from_part')));
                $sourceObserved = $source === 'computed_from_car' ? ($car['updated_at'] ?? $observed) : $observed;
                if ($manualResult) {
                    $sourceObserved = null;
                }
                if ($source === 'parameter_mapping') {
                    $rule = collect($categoryRules)->first(fn ($row) => (string) ($row['parameter_id'] ?? '') === (string) $diagnostic['id'] && (string) ($row['local_category_id'] ?? '') === (string) ($part['category_id'] ?? '') && ($row['enabled'] ?? true));
                    $sourceObserved = $rule['updated_at'] ?? $rule['source_observed_at'] ?? null;
                }
                $candidates[] = ['allegro_category_id' => (string) $categoryId, 'parameter_id' => (string) $diagnostic['id'], 'parameter_name_snapshot' => $diagnostic['name'] ?? null,
                    'values_ids' => $diagnostic['valuesIds'] ?? [], 'values' => $diagnostic['values'] ?? [], 'range_value' => null, 'raw_value' => $diagnostic['raw_local_value'] ?? null,
                    'source' => $source, 'source_field' => $field, 'source_observed_at' => $sourceObserved, 'parameter_scope' => $scope, 'resolution_role' => 'builder_result'];
            }
        }
        if ($candidates === []) {
            $candidates[] = ['allegro_category_id' => $category, 'parameter_id' => null, 'source' => 'unknown'];
        }

        return $this->finish(self::text($part['id']), $candidates, $categories ?: [$category], $schema, $capturedAt, $manual !== []);
    }

    public function orphan(?string $partId, array $candidates, callable $schema, string $capturedAt): array
    {
        return $this->finish($partId, $candidates, array_unique(array_column($candidates, 'allegro_category_id')), $schema, $capturedAt, false);
    }

    private function finish(?string $partId, array $candidates, array $categories, callable $schema, string $capturedAt, bool $hasManual): array
    {
        $rows = [];
        $conflicts = [];
        $reports = [];
        foreach ($candidates as $candidate) {
            $category = self::text($candidate['allegro_category_id'] ?? null);
            $cache = $category === null ? null : $schema($category);
            $definition = null;
            foreach ($cache['raw_response']['parameters'] ?? [] as $item) {
                if ((string) ($item['id'] ?? '') === (string) ($candidate['parameter_id'] ?? '')) {
                    $definition = $item;
                    break;
                }
            }
            $scope = $candidate['parameter_scope'] ?? null;
            if ($scope === null && isset($definition['options']['describesProduct'])) {
                $scope = $definition['options']['describesProduct'] ? 'product' : 'offer';
            }
            $row = array_replace(array_fill_keys(self::FIELDS, null), $candidate, ['part_id' => $partId, 'allegro_category_id' => $category,
                'parameter_name_snapshot' => $candidate['parameter_name_snapshot'] ?? $definition['name'] ?? null,
                'parameter_type' => $definition['type'] ?? null, 'multiple_choices' => $definition['restrictions']['multipleChoices'] ?? $definition['multipleChoices'] ?? null,
                'parameter_scope' => $scope, 'product_set_index' => $scope === 'product' ? ($candidate['product_set_index'] ?? 0) : null,
                'values_ids' => array_values(array_unique(array_map('strval', $candidate['values_ids'] ?? []))), 'values' => $candidate['values'] ?? [],
                'required_snapshot' => $definition['required'] ?? null, 'required_for_product_snapshot' => $definition['options']['requiredForProduct'] ?? $definition['requiredForProduct'] ?? null,
                'describes_product_snapshot' => $definition['options']['describesProduct'] ?? null, 'schema_snapshot_id' => $cache['schema_snapshot_id'] ?? null,
                'captured_at' => $capturedAt, 'valid_for_republication' => null, 'resolution_role' => $candidate['resolution_role'] ?? (($candidate['parameter_id'] ?? null) === null ? 'coverage_marker' : 'stored_source')]);
            $present = self::present($row);
            $historic = in_array($row['source'], ['legacy_offer_payload', 'marketplace_listing_payload', 'review_metadata'], true);
            $valid = $present && $definition !== null;
            if (($definition['type'] ?? null) === 'dictionary') {
                $allowed = array_map(fn ($value) => (string) $value['id'], $definition['dictionary'] ?? []);
                $valid = $valid && $row['values_ids'] !== [] && array_diff($row['values_ids'], $allowed) === [];
                if ($row['multiple_choices'] === false && count($row['values_ids']) > 1) {
                    $valid = false;
                }
            }
            $row['local_validation_status'] = $cache === null ? 'unknown' : ($definition === null ? 'parameter_not_in_local_schema' : ($valid ? 'value_present_in_local_schema' : 'value_missing_or_invalid_locally'));
            $row['completeness_status'] = $cache === null ? 'schema_unknown' : ($row['parameter_id'] === null ? 'partial' : ($definition === null || $historic ? 'historical_only' : ($valid ? 'complete' : 'partial')));
            $row['reason_code'] = $cache === null ? 'schema_unavailable_locally' : ($definition === null ? 'parameter_absent_from_local_schema' : ($historic ? ($present ? 'historical_not_validated_for_publication' : 'historical_parameter_id_only') : ($valid ? 'resolved_against_local_cache' : 'value_missing_or_invalid_locally')));
            $row['candidate_id'] = hash('sha256', self::json($row));
            $rows[$row['candidate_id']] = $row;
        }
        $groups = [];
        foreach ($rows as $id => $row) {
            if ($row['parameter_id'] === null) {
                continue;
            }
            // Unknown scope remains explicit, but cannot conceal manual-versus-history disagreements.
            $groups[self::json([$row['allegro_category_id'], $row['parameter_id'], $row['product_set_index'] ?? 0])][] = $id;
        }
        $historicalOnly = 0;
        foreach ($groups as $key => $ids) {
            $values = [];
            $localPresent = false;
            $historyPresent = false;
            foreach ($ids as $id) {
                $row = $rows[$id];
                if (! self::present($row)) {
                    continue;
                }
                $historical = in_array($row['source'], ['legacy_offer_payload', 'marketplace_listing_payload', 'review_metadata'], true);
                $localPresent = $localPresent || ! $historical;
                $historyPresent = $historyPresent || $historical;
                $values[self::canonicalValue($row)] = true;
            }
            if ($historyPresent && ! $localPresent) {
                $historicalOnly++;
            }
            if (count($values) < 2) {
                continue;
            }
            $conflictId = hash('sha256', self::json([$partId, $key]));
            foreach ($ids as $id) {
                $rows[$id]['conflict_id'] = $conflictId;
                $rows[$id]['completeness_status'] = 'conflict';
            }
            $conflicts[] = ['conflict_id' => $conflictId, 'part_id' => $partId, 'allegro_category_id' => $rows[$ids[0]]['allegro_category_id'], 'parameter_id' => $rows[$ids[0]]['parameter_id'], 'reason_code' => 'different_source_values', 'candidates' => array_map(fn ($id) => $rows[$id], $ids)];
        }
        foreach ($categories as $category) {
            $cache = $category === null ? null : $schema((string) $category);
            $missing = [];
            foreach ($cache['raw_response']['parameters'] ?? [] as $definition) {
                if (! ($definition['required'] ?? false) && ! ($definition['options']['requiredForProduct'] ?? $definition['requiredForProduct'] ?? false)) {
                    continue;
                }
                $found = false;
                foreach ($rows as $row) {
                    if ($row['allegro_category_id'] === (string) $category && $row['parameter_id'] === (string) $definition['id'] && $row['local_validation_status'] === 'value_present_in_local_schema' && ! in_array($row['source'], ['legacy_offer_payload', 'marketplace_listing_payload', 'review_metadata'], true)) {
                        $found = true;
                    }
                }
                if (! $found) {
                    $missing[] = (string) $definition['id'];
                }
            }
            $categoryRows = array_values(array_filter($rows, fn ($row) => $row['allegro_category_id'] === $category));
            $statuses = array_column($categoryRows, 'completeness_status');
            $status = $cache === null ? 'schema_unknown' : (in_array('conflict', $statuses, true) ? 'conflict' : ($missing !== [] || in_array('partial', $statuses, true) ? 'partial' : ($statuses !== [] && count(array_unique($statuses)) === 1 && $statuses[0] === 'historical_only' ? 'historical_only' : 'complete')));
            $reports[] = ['part_id' => $partId, 'allegro_category_id' => $category, 'completeness_status' => $status, 'reason_code' => $cache === null ? 'schema_unavailable_locally' : 'local_cache_assessment_only', 'missing_required_parameter_ids' => $missing, 'schema_snapshot_id' => $cache['schema_snapshot_id'] ?? null, 'has_manual_selections' => $hasManual];
        }

        return ['rows' => array_values($rows), 'conflicts' => $conflicts, 'completeness' => $reports, 'historical_only_parameters' => $historicalOnly, 'has_manual_selections' => $hasManual];
    }

    public static function present(array $row): bool
    {
        return ($row['values_ids'] ?? []) !== [] || array_filter($row['values'] ?? [], fn ($value) => $value !== null && $value !== '') !== [] || ($row['range_value'] ?? null) !== null;
    }

    private static function canonicalValue(array $row): string
    {
        $ids = $row['values_ids'];
        $values = $row['values'];
        sort($ids, SORT_STRING);
        sort($values, SORT_REGULAR);

        return self::json([$ids, $values, $row['range_value']]);
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : null;
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
