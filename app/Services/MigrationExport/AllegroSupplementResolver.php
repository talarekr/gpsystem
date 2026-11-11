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
    public const FIELDS = ['part_id', 'allegro_category_id', 'parameter_id', 'parameter_name_snapshot', 'parameter_type', 'multiple_choices', 'parameter_scope', 'product_set_index', 'values_ids', 'values', 'range_value', 'raw_value', 'required_snapshot', 'required_for_product_snapshot', 'describes_product_snapshot', 'schema_snapshot_id', 'source', 'source_field', 'source_listing_id', 'marketplace_account_id', 'captured_at', 'source_observed_at', 'completeness_status', 'reason_code', 'local_validation_status', 'resolution_role', 'candidate_id', 'conflict_id', 'valid_for_republication', 'provenance', 'schema_fetched_at', 'schema_source_endpoint', 'allegro_offer_id', 'product_id', 'unit', 'fetched_at', 'source_priority', 'is_preferred', 'historical_schema_mismatch', 'association_conflict', 'readback_unavailable'];

    public const COMPLETENESS_FIELDS = ['part_id', 'allegro_category_id', 'completeness_status', 'reason_code', 'missing_required_parameter_ids', 'schema_snapshot_id', 'has_manual_selections', 'required_parameter_count', 'required_resolved_count', 'missing_required_parameter_names', 'product_required_count', 'product_required_missing', 'missing_product_required_parameter_ids', 'missing_product_required_parameter_names', 'status', 'schema_freshness_status', 'canonical_allegro_category_id', 'category_context', 'product_required_resolved_count', 'existing_offer_parameter_count', 'resolved_from_existing_offer_count', 'conflict_count', 'readback_unavailable', 'missing_product_slots'];

    public const EXISTING_FIELDS = ['part_id', 'offer_id', 'category_id', 'scope', 'product_set_index', 'product_id', 'parameter_id', 'parameter_name', 'values', 'values_ids', 'range_value', 'unit', 'source', 'fetched_at', 'marketplace_account_id', 'account_code', 'source_listing_id', 'raw_value', 'historical_schema_mismatch', 'schema_status', 'association_conflict'];

    public const IMPORT_FIELDS = ['part_id', 'allegro_offer_id', 'allegro_category_id', 'scope', 'product_set_index', 'product_id', 'parameter_id', 'parameter_name', 'values', 'values_ids', 'range_value', 'unit', 'required', 'required_for_product', 'describes_product', 'source', 'status', 'is_preferred', 'candidate_count', 'candidates'];

    /** Preserve every actual product slot and all parameter value forms, even outside today's schema. */
    public function readbackCandidates(array $receipt, string $part, array $link, bool $associationConflict): array
    {
        $raw = $receipt['raw_response'];
        $category = self::text(data_get($raw, 'category.id')) ?? '';
        $groups = [['scope' => 'offer', 'index' => null, 'product_id' => null, 'parameters' => $raw['parameters'] ?? []]];
        $products = $raw['productSet'] ?? [];
        if (! is_array($products)) {
            $products = [['product' => ['parameters' => $products]]];
        }
        foreach ($products as $index => $product) {
            $groups[] = ['scope' => 'product', 'index' => (int) $index, 'product_id' => self::text(data_get($product, 'product.id')), 'parameters' => data_get($product, 'product.parameters', is_array($product) ? [] : $product)];
        }
        $out = [];
        foreach ($groups as $group) {
            $parameters = $group['parameters'];
            if (! is_array($parameters) || ! array_is_list($parameters)) {
                $parameters = [$parameters];
            }
            foreach ($parameters ?: [['id' => null]] as $rawParameter) {
                $parameter = $this->parameter($rawParameter);
                $out[] = ['part_id' => $part, 'offer_id' => $receipt['offer_id'], 'allegro_offer_id' => $receipt['offer_id'], 'category_id' => $category, 'allegro_category_id' => $category,
                    'scope' => $group['scope'], 'parameter_scope' => $group['scope'], 'product_set_index' => $group['index'], 'product_id' => $group['product_id'], 'parameter_id' => isset($parameter['id']) ? (string) $parameter['id'] : null,
                    'parameter_name' => self::text($parameter['name'] ?? null), 'parameter_name_snapshot' => self::text($parameter['name'] ?? null), 'values' => $parameter['values'], 'values_ids' => $parameter['valuesIds'], 'range_value' => $parameter['rangeValue'], 'unit' => self::text($parameter['unit'] ?? null),
                    'source' => 'existing_allegro_offer', 'source_field' => $group['scope'] === 'offer' ? 'parameters' : 'productSet.'.$group['index'].'.product.parameters', 'source_listing_id' => $link['listing_id'], 'marketplace_account_id' => $receipt['marketplace_account_id'], 'account_code' => $receipt['account_code'],
                    'fetched_at' => $receipt['fetched_at'], 'source_observed_at' => $receipt['fetched_at'], 'raw_value' => $rawParameter, 'association_conflict' => $associationConflict, 'record_issue' => $parameter['_issue'], 'readback_unavailable' => $parameter['_issue'] !== null];
            }
        }

        return $out;
    }

    /** One row per Part/category/scope/parameter/product slot; conflicting alternatives stay explicit. */
    public function importRows(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            if ($row['parameter_id'] === null) {
                continue;
            }
            $groups[self::json([$row['part_id'], $row['allegro_category_id'], $row['parameter_scope'], $row['parameter_id'], $row['product_set_index']])][] = $row;
        }
        $out = [];
        foreach ($groups as $candidates) {
            usort($candidates, fn ($a, $b) => ($a['source_priority'] ?? 99) <=> ($b['source_priority'] ?? 99) ?: strcmp($a['candidate_id'], $b['candidate_id']));
            $row = collect($candidates)->firstWhere('is_preferred', true) ?? $candidates[0];
            $conflict = collect($candidates)->contains(fn ($r) => $r['conflict_id'] !== null);
            $out[] = ['part_id' => $row['part_id'], 'allegro_offer_id' => $row['allegro_offer_id'], 'allegro_category_id' => $row['allegro_category_id'], 'scope' => $row['parameter_scope'], 'product_set_index' => $row['product_set_index'], 'product_id' => $row['product_id'], 'parameter_id' => $row['parameter_id'], 'parameter_name' => $row['parameter_name_snapshot'],
                'values' => $conflict ? null : $row['values'], 'values_ids' => $conflict ? null : $row['values_ids'], 'range_value' => $conflict ? null : $row['range_value'], 'unit' => $row['unit'], 'required' => $row['required_snapshot'], 'required_for_product' => $row['required_for_product_snapshot'], 'describes_product' => $row['describes_product_snapshot'],
                'source' => $conflict ? 'unresolved' : $row['provenance'], 'status' => $conflict ? 'CONFLICT' : ($row['historical_schema_mismatch'] ? 'historical_schema_mismatch' : strtoupper($row['completeness_status'])), 'is_preferred' => ! $conflict && $row['is_preferred'], 'candidate_count' => count($candidates), 'candidates' => $candidates];
        }

        return $out;
    }

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
            $payload['id'] = $payload['parameter_id'];
            $payload['name'] = $payload['parameter_name'] ?? $payload['name'] ?? null;
            $payload['valuesIds'] = (array) ($payload['selected_value_ids'] ?? $payload['saved_value_ids']);
        }
        if (array_key_exists('id', $payload) && (isset($payload['valuesIds']) || isset($payload['values_ids']) || isset($payload['values']) || isset($payload['rangeValue']) || isset($payload['range_value']))) {
            $parameter = $this->parameter($payload);
            $out[] = ['allegro_category_id' => $category, 'parameter_id' => $parameter['id'], 'parameter_name_snapshot' => self::text($payload['name'] ?? null),
                'values_ids' => $parameter['valuesIds'], 'values' => $parameter['values'], 'range_value' => $parameter['rangeValue'], 'record_issue' => $parameter['_issue'], 'readback_unavailable' => $parameter['_issue'] !== null,
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
    public function resolve(array $part, ?array $car, ?array $localCategory, array $categoryMappings, array $manual, array $historical, callable $schema, callable $rules, string $capturedAt, bool $freshRequired = false): array
    {
        $category = self::text(data_get($part, 'review_metadata.marketplace_category_overrides.allegro.external_category_id'));
        $categoryMappings = array_values(array_filter($categoryMappings, fn ($m) => self::text($m['external_category_id'] ?? null) !== null));
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
            $definitions = array_map(fn ($def) => $def + ['category_id' => (string) $categoryId], $this->builderDefinitions($cache));
            $mapping = (new MarketplaceCategoryMapping)->forceFill(['external_category_id' => (string) $categoryId]);
            $categoryRules = $rules((string) $categoryId);
            $result = $this->builder->buildFromSnapshot($model, $mapping, ['ok' => true, 'source' => 'frozen_local_cache', 'parameters' => $definitions], $manual, $categoryRules);
            foreach ($result['parameter_source_diagnostics'] ?? [] as $diagnostic) {
                if (! isset($diagnostic['id'])) {
                    continue;
                }
                $scope = ($diagnostic['parameter_location'] ?? null) === 'productSet[0].product.parameters' ? 'product' : 'offer';
                if (($cache['fresh_schema'] ?? false) && (($diagnostic['source'] ?? '') === 'fixed_business_rule' || ($diagnostic['auto_injected'] ?? false))) {
                    $candidates[] = ['allegro_category_id' => (string) $categoryId, 'parameter_id' => (string) $diagnostic['id'], 'parameter_name_snapshot' => $diagnostic['name'] ?? null,
                        'source' => 'unknown', 'source_field' => 'migration.builder_default_not_applied', 'parameter_scope' => $scope, 'resolution_role' => 'coverage_marker', 'values' => [], 'values_ids' => []];

                    continue;
                }
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
        foreach ($categories as $categoryId) {
            $cache = $schema((string) $categoryId);
            if (! ($cache['fresh_schema'] ?? false)) {
                continue;
            }
            foreach ($this->builderDefinitions($cache) as $definition) {
                if (! ($definition['required'] ?? false) && ! ($definition['requiredForProduct'] ?? $definition['options']['requiredForProduct'] ?? false)) {
                    continue;
                }
                $found = false;
                foreach ($candidates as $candidate) {
                    if ((string) ($candidate['allegro_category_id'] ?? '') === (string) $categoryId && (string) ($candidate['parameter_id'] ?? '') === (string) $definition['id']) {
                        $found = true;
                    }
                }
                if (! $found) {
                    $candidates[] = ['allegro_category_id' => (string) $categoryId, 'parameter_id' => (string) $definition['id'], 'source' => 'category_schema', 'parameter_scope' => ($definition['options']['describesProduct'] ?? false) ? 'product' : 'offer', 'source_field' => 'fresh_schema.required', 'resolution_role' => 'coverage_marker'];
                }
            }
        }
        if ($candidates === []) {
            $candidates[] = ['allegro_category_id' => $category, 'parameter_id' => null, 'source' => 'unknown'];
        }

        return $this->finish(self::text($part['id']), $candidates, $categories ?: [$category], $schema, $capturedAt, $manual !== [], $freshRequired, $category);
    }

    public function orphan(?string $partId, array $candidates, callable $schema, string $capturedAt): array
    {
        return $this->finish($partId, $candidates, array_unique(array_column($candidates, 'allegro_category_id')), $schema, $capturedAt, false);
    }

    private function finish(?string $partId, array $candidates, array $categories, callable $schema, string $capturedAt, bool $hasManual, bool $freshRequired = false, ?string $canonicalCategory = null): array
    {
        if ($freshRequired && $canonicalCategory === null && ! in_array(null, $categories, true)) {
            $categories[] = null;
        }
        $rows = [];
        $conflicts = [];
        $reports = [];
        foreach ($candidates as $candidate) {
            $category = self::text($candidate['allegro_category_id'] ?? null);
            $cache = $category === null ? null : $schema($category);
            $definition = null;
            $candidateScope = $candidate['parameter_scope'] ?? null;
            $schemaDefinitions = $candidateScope === 'product' && ($cache['fresh_schema'] ?? false) ? ($cache['product_raw_response']['parameters'] ?? []) : ($cache['raw_response']['parameters'] ?? []);
            foreach ($schemaDefinitions as $item) {
                if ((string) ($item['id'] ?? '') === (string) ($candidate['parameter_id'] ?? '')) {
                    $definition = $item;
                    break;
                }
            }
            $scope = $candidate['parameter_scope'] ?? null;
            if ($scope === null && isset($definition['options']['describesProduct'])) {
                $scope = $definition['options']['describesProduct'] ? 'product' : 'offer';
            }
            if (($cache['fresh_schema'] ?? false) && $scope === 'product') {
                $definition = null;
                foreach ($cache['product_raw_response']['parameters'] ?? [] as $item) {
                    if ((string) $item['id'] === (string) ($candidate['parameter_id'] ?? '')) {
                        $definition = $item;
                        break;
                    }
                }
            }
            $schemaKnown = $cache !== null && (! ($cache['fresh_schema'] ?? false) || ($scope === 'product' ? $cache['product_schema_available'] : $cache['offer_schema_available']));
            $row = array_replace(array_fill_keys(self::FIELDS, null), $candidate, ['part_id' => $partId, 'allegro_category_id' => $category,
                'parameter_name_snapshot' => $candidate['parameter_name_snapshot'] ?? $definition['name'] ?? null,
                'parameter_type' => $definition['type'] ?? null, 'multiple_choices' => $definition['restrictions']['multipleChoices'] ?? $definition['multipleChoices'] ?? null,
                'parameter_scope' => $scope, 'product_set_index' => $scope === 'product' ? ($candidate['product_set_index'] ?? 0) : null,
                'values_ids' => array_values(array_unique(array_map('strval', $candidate['values_ids'] ?? []))), 'values' => $candidate['values'] ?? [],
                'required_snapshot' => $definition['required'] ?? null, 'required_for_product_snapshot' => $definition['options']['requiredForProduct'] ?? $definition['requiredForProduct'] ?? (($cache['fresh_schema'] ?? false) && $scope === 'product' ? ($definition['required'] ?? null) : null),
                'describes_product_snapshot' => $definition['options']['describesProduct'] ?? null, 'schema_snapshot_id' => $cache['schema_snapshot_id'] ?? null,
                'provenance' => match ($candidate['source'] ?? 'unknown') {
                    'existing_allegro_offer' => 'existing_allegro_offer', 'manual_selection' => 'manual_selection', 'marketplace_listing_payload', 'legacy_offer_payload', 'review_metadata' => 'existing_listing', 'parameter_mapping' => 'local_mapping', 'computed_from_part' => 'derived_from_part', 'computed_from_car' => 'derived_from_car', 'category_schema' => 'category_schema', default => 'unresolved',
                }, 'schema_fetched_at' => $scope === 'product' ? ($cache['product_fetched_at'] ?? $cache['fetched_at'] ?? null) : ($cache['fetched_at'] ?? null),
                'schema_source_endpoint' => $scope === 'product' && ($cache['fresh_schema'] ?? false) ? 'GET /sale/categories/'.$category.'/product-parameters' : ($cache['endpoint'] ?? null),
                'captured_at' => $capturedAt, 'valid_for_republication' => null, 'resolution_role' => $candidate['resolution_role'] ?? (($candidate['parameter_id'] ?? null) === null ? 'coverage_marker' : 'stored_source')]);
            $row['unit'] ??= $definition['unit'] ?? null;
            $row['source_priority'] = match ($row['provenance']) {
                'existing_allegro_offer' => 1, 'manual_selection' => 2, 'local_mapping' => 3, 'derived_from_part' => 4, 'derived_from_car' => 5, default => 6
            };
            $row['is_preferred'] = false;
            $row['association_conflict'] = (bool) ($candidate['association_conflict'] ?? false);
            $row['readback_unavailable'] = (bool) ($candidate['readback_unavailable'] ?? false);
            $row['historical_schema_mismatch'] = $schemaKnown && $row['source'] === 'existing_allegro_offer' && $row['parameter_id'] !== null && $definition === null;
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
            if (($cache['fresh_schema'] ?? false) && $definition !== null) {
                $valid = $valid && $this->typedValueValid($row, $definition);
            }
            $row['local_validation_status'] = ! $schemaKnown ? 'unknown' : ($definition === null ? 'parameter_not_in_local_schema' : ($valid ? 'value_present_in_local_schema' : 'value_missing_or_invalid_locally'));
            $row['completeness_status'] = ! $schemaKnown ? 'schema_unknown' : ($row['parameter_id'] === null ? 'partial' : ($definition === null || $historic ? 'historical_only' : ($valid ? 'complete' : 'partial')));
            $row['reason_code'] = ! $schemaKnown ? 'schema_unavailable_locally' : ($definition === null ? 'parameter_absent_from_local_schema' : ($historic ? ($present ? 'historical_not_validated_for_publication' : 'historical_parameter_id_only') : ($valid ? 'resolved_against_local_cache' : 'value_missing_or_invalid_locally')));
            if ($row['historical_schema_mismatch']) {
                $row['reason_code'] = 'historical_schema_mismatch';
            }
            if (($candidate['record_issue'] ?? null) !== null) {
                $row['reason_code'] = 'supplement_preview_record_error';
                $row['local_validation_status'] = 'malformed_historical_parameter';
                $row['completeness_status'] = 'partial';
            }
            $row = array_intersect_key($row, array_flip(self::FIELDS));
            $row['candidate_id'] = hash('sha256', self::json($row));
            $rows[$row['candidate_id']] = $row;
        }
        $groups = [];
        foreach ($rows as $id => $row) {
            if ($row['parameter_id'] === null) {
                continue;
            }
            // Unknown scope remains explicit, but cannot conceal manual-versus-history disagreements.
            $scopes = [$row['parameter_scope']];
            if ($row['parameter_scope'] === null) {
                $known = array_values(array_unique(array_filter(array_map(fn ($r) => $r['allegro_category_id'] === $row['allegro_category_id'] && $r['parameter_id'] === $row['parameter_id'] ? $r['parameter_scope'] : null, $rows))));
                $scopes = $known ?: [null];
            }
            foreach ($scopes as $scope) {
                $groups[self::json([$row['allegro_category_id'], $row['parameter_id'], $scope, $row['product_set_index'] ?? 0])][] = $id;
            }
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
            $associationConflict = collect($ids)->contains(fn ($id) => $rows[$id]['association_conflict']);
            if (count($values) < 2 && ! $associationConflict) {
                $eligible = array_values(array_filter($ids, fn ($id) => self::present($rows[$id]) && ! in_array($rows[$id]['provenance'], ['existing_listing', 'unresolved', 'category_schema'], true)));
                usort($eligible, fn ($a, $b) => $rows[$a]['source_priority'] <=> $rows[$b]['source_priority'] ?: strcmp($a, $b));
                if ($eligible !== []) {
                    $rows[$eligible[0]]['is_preferred'] = true;
                }

                continue;
            }
            $conflictId = hash('sha256', self::json([$partId, $key]));
            foreach ($ids as $id) {
                $rows[$id]['is_preferred'] = false;
                $rows[$id]['conflict_id'] = $conflictId;
                $rows[$id]['completeness_status'] = 'conflict';
            }
            $conflicts[] = ['conflict_id' => $conflictId, 'part_id' => $partId, 'allegro_category_id' => $rows[$ids[0]]['allegro_category_id'], 'parameter_id' => $rows[$ids[0]]['parameter_id'], 'reason_code' => $associationConflict ? 'offer_linked_to_multiple_parts' : 'different_source_values', 'candidates' => array_map(fn ($id) => $rows[$id], $ids)];
        }
        foreach ($categories as $category) {
            $cache = $category === null ? null : $schema((string) $category);
            if ($freshRequired && $cache === null) {
                $cache = ['fresh_schema' => true, 'raw_response' => ['parameters' => []], 'product_raw_response' => ['parameters' => []], 'offer_schema_available' => false, 'product_schema_available' => false, 'schema_snapshot_id' => null];
            }
            if ($cache['fresh_schema'] ?? false) {
                $reports[] = $this->freshCompleteness($partId, $category, $cache, $rows, $hasManual) + ['canonical_allegro_category_id' => $canonicalCategory, 'category_context' => $category === null ? 'unresolved' : ((string) $category === $canonicalCategory ? 'canonical' : 'historical')];

                continue;
            }
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

    private function builderDefinitions(array $cache): array
    {
        $definitions = $cache['raw_response']['parameters'] ?? [];
        $ids = array_fill_keys(array_map('strval', array_column($definitions, 'id')), true);
        foreach ($cache['product_raw_response']['parameters'] ?? [] as $definition) {
            if (! isset($ids[(string) $definition['id']])) {
                // Annotation for the existing builder only; the exported endpoint response remains byte-exact.
                $definition['options']['describesProduct'] = true;
                $definitions[] = $definition;
            }
        }

        return $definitions;
    }

    private function typedValueValid(array $row, array $definition): bool
    {
        $type = $definition['type'] ?? null;
        if ($type === 'dictionary') {
            return true;
        } // Dictionary IDs/multiplicity were checked above.
        if (! in_array($type, ['string', 'integer', 'float'], true)) {
            return false;
        }
        $limits = $definition['restrictions'] ?? [];
        $values = $row['values'];
        if ($row['range_value'] !== null) {
            $values = array_values(array_intersect_key($row['range_value'], array_flip(['from', 'to'])));
            if (count($values) !== 2 || ! is_numeric($values[0]) || ! is_numeric($values[1]) || $values[0] > $values[1]) {
                return false;
            }
        }
        if ($values === []) {
            return false;
        }
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                return false;
            }
            if ($type === 'string') {
                $length = mb_strlen((string) $value);
                if ((isset($limits['minLength']) && $length < $limits['minLength']) || (isset($limits['maxLength']) && $length > $limits['maxLength'])) {
                    return false;
                }
            } else {
                if (! is_numeric($value) || ($type === 'integer' && ! preg_match('/\A-?[0-9]+\z/D', (string) $value))) {
                    return false;
                }
                if ((isset($limits['min']) && $value < $limits['min']) || (isset($limits['max']) && $value > $limits['max'])) {
                    return false;
                }
            }
        }

        return true;
    }

    private function freshCompleteness(?string $part, mixed $category, array $cache, array $rows, bool $manual): array
    {
        $offer = $cache['raw_response']['parameters'] ?? [];
        $product = $cache['product_raw_response']['parameters'] ?? [];
        $required = $productRequired = $names = [];
        $conditional = false;
        $requirementsKnown = true;
        foreach ($offer as $definition) {
            $id = (string) $definition['id'];
            $names[$id] = $definition['name'] ?? null;
            if (($definition['required'] ?? null) === true) {
                $required[$id] = true;
            }
            if (($definition['requiredForProduct'] ?? $definition['options']['requiredForProduct'] ?? null) === true) {
                $productRequired[$id] = true;
            }
            $requirementsKnown = $requirementsKnown && is_bool($definition['required'] ?? null);
            $conditional = $conditional || ! empty($definition['requiredIf']) || ! empty($definition['dependencies']) || ! empty($definition['dependsOn']) || ! empty($definition['options']['dependencies']);
        }
        foreach ($product as $definition) {
            $id = (string) $definition['id'];
            $names[$id] = $definition['name'] ?? $names[$id] ?? null;
            if (($definition['required'] ?? null) === true || ($definition['requiredForProduct'] ?? null) === true || ($definition['options']['requiredForProduct'] ?? null) === true) {
                $productRequired[$id] = true;
            }
            $requirementsKnown = $requirementsKnown && (is_bool($definition['required'] ?? null) || is_bool($definition['requiredForProduct'] ?? null) || is_bool($definition['options']['requiredForProduct'] ?? null));
            $conditional = $conditional || ! empty($definition['requiredIf']) || ! empty($definition['dependencies']) || ! empty($definition['dependsOn']) || ! empty($definition['options']['dependencies']);
        }
        $valid = $productValid = $slots = [];
        $conflict = false;
        $existing = $resolvedExisting = 0;
        $conflictIds = [];
        $unavailable = collect($rows)->contains(fn ($r) => $r['readback_unavailable'] ?? false);
        foreach ($rows as $row) {
            if ((string) $row['allegro_category_id'] !== (string) $category) {
                continue;
            }
            $conflict = $conflict || $row['conflict_id'] !== null || $row['association_conflict'];
            if ($row['conflict_id'] !== null) {
                $conflictIds[$row['conflict_id']] = true;
            }
            if ($row['source'] === 'existing_allegro_offer') {
                $existing += (int) ($row['parameter_id'] !== null);
                if ($row['parameter_scope'] === 'product') {
                    $slots[(int) $row['product_set_index']] = true;
                }
            }
            $resolvedExisting += (int) ($row['source'] === 'existing_allegro_offer' && $row['is_preferred'] && $row['local_validation_status'] === 'value_present_in_local_schema');
            if ($row['local_validation_status'] !== 'value_present_in_local_schema' || in_array($row['provenance'], ['existing_listing', 'unresolved', 'category_schema'], true) || $row['conflict_id'] !== null) {
                continue;
            }
            $valid[(string) $row['parameter_id']] = true;
            if ($row['parameter_scope'] === 'product') {
                $productValid[(int) $row['product_set_index']][(string) $row['parameter_id']] = true;
            }
        }
        $missingOffer = array_values(array_map('strval', array_diff(array_keys($required), array_keys($valid))));
        $slots = array_keys($slots ?: [0 => true]);
        $missingProduct = $missingSlots = [];
        foreach ($slots as $slot) {
            foreach (array_diff(array_keys($productRequired), array_keys($productValid[$slot] ?? [])) as $id) {
                $missingProduct[(string) $id] = (string) $id;
                $missingSlots[] = ['product_set_index' => $slot, 'parameter_id' => (string) $id];
            }
        }
        $missingProduct = array_values($missingProduct);
        $missing = array_values(array_unique([...$missingOffer, ...$missingProduct]));
        $known = ($cache['offer_schema_available'] ?? false) && ($cache['product_schema_available'] ?? false) && ! $conditional && $requirementsKnown && ! $unavailable;
        $status = $conflict ? 'CONFLICT' : (! $known ? 'SCHEMA_UNKNOWN' : ($missing ? 'PARTIAL' : 'COMPLETE'));

        return ['part_id' => $part, 'allegro_category_id' => $category, 'completeness_status' => strtolower($status), 'status' => $status,
            'reason_code' => ! $known ? 'fresh_schema_missing_or_conditional_rules_not_evaluated' : 'fresh_schema_local_assessment_not_publish_readiness',
            'required_parameter_count' => $cache['offer_schema_available'] ? count($required) : null, 'required_resolved_count' => $cache['offer_schema_available'] ? count($required) - count($missingOffer) : null, 'missing_required_parameter_ids' => $missing,
            'missing_required_parameter_names' => array_map(fn ($id) => $names[$id] ?? null, $missing), 'product_required_count' => $cache['product_schema_available'] ? count($productRequired) * count($slots) : null, 'product_required_missing' => $cache['product_schema_available'] ? count($missingSlots) : null,
            'product_required_resolved_count' => $cache['product_schema_available'] ? count($productRequired) * count($slots) - count($missingSlots) : null, 'existing_offer_parameter_count' => $existing, 'resolved_from_existing_offer_count' => $resolvedExisting, 'conflict_count' => count($conflictIds), 'readback_unavailable' => $unavailable, 'missing_product_slots' => $missingSlots,
            'missing_product_required_parameter_ids' => $missingProduct, 'missing_product_required_parameter_names' => array_map(fn ($id) => $names[$id] ?? null, $missingProduct),
            'schema_snapshot_id' => $cache['schema_snapshot_id'], 'has_manual_selections' => $manual, 'schema_freshness_status' => $known ? 'both_endpoints_captured' : 'not_fully_known'];
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

        return self::json([$ids, $values, $row['range_value'], $row['unit']]);
    }

    /** Keep the original value separately; malformed shapes become explicit incomplete markers. */
    private function parameter(mixed $raw): array
    {
        $parameter = is_array($raw) ? $raw : [];
        $id = $parameter['id'] ?? null;
        $ids = $parameter['valuesIds'] ?? $parameter['values_ids'] ?? [];
        $values = $parameter['values'] ?? [];
        $range = $parameter['rangeValue'] ?? $parameter['range_value'] ?? null;
        $malformed = ! is_array($raw) || ($id !== null && ! is_string($id) && ! is_int($id))
            || ! is_array($ids) || ! array_is_list($ids) || ! is_array($values) || ! array_is_list($values);
        foreach (is_array($ids) ? $ids : [] as $value) {
            $malformed = $malformed || (! is_string($value) && ! is_int($value));
        }
        foreach (is_array($values) ? $values : [] as $value) {
            $malformed = $malformed || ($value !== null && ! is_scalar($value));
        }
        if ($range !== null) {
            $malformed = $malformed || ! is_array($range) || array_is_list($range);
            foreach (is_array($range) ? $range : [] as $value) {
                $malformed = $malformed || ($value !== null && ! is_scalar($value));
            }
        }
        if ($id === null && ($ids !== [] || $values !== [] || $range !== null)) {
            $malformed = true;
        }

        return array_replace($parameter, ['id' => is_string($id) || is_int($id) ? self::text($id) : null, 'valuesIds' => $malformed ? [] : array_map('strval', $ids),
            'values' => $malformed ? [] : $values, 'rangeValue' => $malformed ? null : $range, '_issue' => $malformed ? 'malformed_historical_parameter' : null]);
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
