<?php

namespace App\Services\MigrationExport;

use App\Services\Marketplace\ShippingRateAudit\ReadOnlyDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Independent, private, bounded supplement. Parent checkpoint/artifacts are opened read-only. */
final class AllegroParametersSupplement
{
    public const FORMAT = 'gpswiss-allegro-parameters-supplement-v1';

    private const TABLES = ['allegro_category_parameters_cache', 'allegro_parameter_mappings'];

    private const PARENT_STAGES = ['parts_index' => 'parts', 'categories_index' => 'part_categories', 'category_mappings_index' => 'marketplace_category_mappings', 'manual_index' => 'allegro_parameter_selections', 'listings_index' => 'marketplace_listings', 'resolve' => 'parts'];

    public function __construct(private GpswissMigrationExport $parents, private BrowserMigrationSnapshot $snapshots, private AllegroSupplementResolver $resolver, private BrowserMigrationArchives $archives, private AllegroSchemaRefresh $refresh, private AllegroOfferParameterReadback $readback) {}

    public function start(int $owner, string $run, string $attempt, bool $requireFresh = false, bool $requireReadback = false, bool $preparePlan = false): array
    {
        $parent = $this->parents->supplementParent($owner, $run, $attempt);
        $fresh = $this->fresh($owner, $run, $attempt);
        if ($requireFresh && $fresh === null) {
            throw new MigrationExportException('supplement_schema_refresh_required');
        }
        $offers = $this->offerContext($owner, $run, $attempt);
        if ($requireReadback && $offers === null) {
            throw new MigrationExportException('supplement_offer_readback_required');
        }
        $folder = $this->folder($parent, $run, $attempt, $fresh, $offers);
        $this->mkdir($folder);

        return $this->locked($folder, function () use ($folder, $parent, $run, $attempt, $fresh, $offers, $preparePlan): array {
            if (is_file($folder.'/checkpoint.json')) {
                $state = $this->load($folder.'/checkpoint.json');
                $this->assertParent($parent, $state);
                $this->assertPlan($folder, $state);
                if ($preparePlan && ! isset($state['plan'])) {
                    throw new MigrationExportException('supplement_already_started_without_preview');
                }

                return $this->publicState($state);
            }
            $index = $this->snapshots->index($parent, $attempt);
            if (! ($index['done'] ?? false) || ($index['failed'] ?? false)) {
                throw new MigrationExportException('supplement_parent_capture_not_sealed');
            }
            foreach (['parts', 'cars', 'part_categories', 'marketplace_listings'] as $name) {
                if (! isset($index['datasets'][$name]) || $index['datasets'][$name]['processed'] !== $index['datasets'][$name]['total']) {
                    throw new MigrationExportException('supplement_parent_capture_incomplete');
                }
            }
            $state = ['format' => self::FORMAT, 'parent_run_id' => $run, 'parent_attempt_id' => $attempt, 'captured_at' => now()->toIso8601String(), 'phase' => $fresh ? 'refreshed_schema' : self::TABLES[0], 'fresh_plan_id' => $fresh['plan_id'] ?? null, 'schema_mode' => $fresh ? 'provider_get_refresh' : 'legacy_local_only', 'contract_revision' => $offers ? 3 : ($fresh ? 2 : 1), 'readback_plan_id' => $offers['plan_id'] ?? null, 'readback_context' => $offers, 'readback_offset' => 0, 'refresh_context' => $fresh, 'refresh_offset' => 0, 'shard' => 0, 'row' => 0,
                'parent_index_sha256' => hash_file('sha256', $parent.'/logical-snapshot/'.$attempt.'/checkpoint.json'), 'tables' => [], 'files' => [], 'datasets' => [], 'done' => false,
                'stats' => ['parts_with_manual_selections' => 0, 'resolved_parameter_rows' => 0, 'conflicts' => 0, 'historical_only_parameters' => 0, 'missing_required_values' => 0, 'orphan_listing_parameter_rows' => 0,
                    'part_category_completeness' => array_fill_keys(['COMPLETE', 'PARTIAL', 'SCHEMA_UNKNOWN', 'CONFLICT'], 0), 'completeness' => array_fill_keys(['complete', 'partial', 'historical_only', 'schema_unknown', 'conflict'], 0)], 'parameter_ids' => [], 'category_ids' => [], 'missing_schema_categories' => []];
            if ($preparePlan) {
                $state['plan'] = ['plan_id' => (string) Str::uuid(), 'parent_run_id' => $run, 'parent_attempt_id' => $attempt, 'fresh_plan_id' => $fresh['plan_id'], 'readback_plan_id' => $offers['plan_id'], 'status' => 'PLANNING', 'created_at' => $state['captured_at'], 'ready_at' => null, 'confirmed_at' => null, 'plan_digest' => null,
                    'categories' => count($fresh['categories']), 'offers' => count($offers['offers']), 'readback_successes' => $offers['summary']['successes'], 'readback_not_found_404' => $offers['summary']['not_found_404'], 'parts_total' => $index['datasets']['parts']['total'], 'parts_processed' => 0, 'stage_rows' => [], 'dataset_totals' => array_map(fn ($d) => $d['total'], $index['datasets']), 'parameters_found' => 0, 'missing_required' => 0,
                    'part_statuses' => array_fill_keys(['COMPLETE', 'PARTIAL', 'CONFLICT', 'SCHEMA_UNKNOWN'], 0), 'source_values' => array_fill_keys(['existing_allegro_offer', 'manual_selection', 'local_mapping', 'derived_from_part', 'derived_from_car', 'existing_listing', 'unresolved'], 0), 'resolved_values_by_source' => [], 'expected_files' => null, 'expected_zip_volumes' => null, 'provider_gets' => 0];
            }
            ReadOnlyDatabase::run(function () use (&$state, $fresh): void {
                foreach (self::TABLES as $table) {
                    if ($fresh !== null && $table === self::TABLES[0]) {
                        continue;
                    }
                    $available = Schema::hasTable($table);
                    if ($available && ! Schema::hasColumn($table, 'id')) {
                        throw new MigrationExportException('supplement_source_key_unsupported');
                    }
                    $columns = $available ? array_values(array_filter(Schema::getColumnListing($table), fn ($key) => ! MigrationDataPolicy::sensitiveKey($key))) : [];
                    $state['tables'][$table] = ['available' => $available, 'columns' => $columns, 'max_id' => $available ? (string) (DB::table($table)->max('id') ?? '0') : '0', 'cursor' => '0', 'rows' => 0, 'boundary_at' => now()->toIso8601String(), 'completed_at' => null];
                }
            });
            $this->write($folder.'/parent-index.json', $index);
            $state['frozen_index_sha256'] = hash_file('sha256', $folder.'/parent-index.json');
            $this->write($folder.'/checkpoint.json', $state);

            return $this->publicState($state);
        });
    }

    public function tick(int $owner, string $run, string $attempt): array
    {
        [$parent, $folder] = $this->context($owner, $run, $attempt);

        return $this->locked($folder, function () use ($parent, $folder): array {
            $state = $this->load($folder.'/checkpoint.json');
            $this->assertPlan($folder, $state);
            if ($state['done'] || $state['phase'] === 'planned') {
                return $this->publicState($state);
            }
            if (! hash_equals($state['frozen_index_sha256'], hash_file('sha256', $folder.'/parent-index.json'))) {
                throw new MigrationExportException('supplement_frozen_index_invalid');
            }
            $this->rollbackUncommitted($folder, $state);
            $started = microtime(true);
            if ($state['phase'] === 'refreshed_schema') {
                for ($n = 0; $n < 5 && $state['phase'] === 'refreshed_schema' && microtime(true) < $started + 2; $n++) {
                    $this->freshCacheTick($folder, $state);
                }
            } elseif ($state['phase'] === 'offer_readback') {
                for ($n = 0; $n < 5 && $state['phase'] === 'offer_readback' && microtime(true) < $started + 2; $n++) {
                    $this->offerCacheTick($folder, $state);
                }
            } elseif (in_array($state['phase'], self::TABLES, true)) {
                ReadOnlyDatabase::run(function () use ($folder, &$state): void {
                    $this->captureTable($folder, $state);
                });
            } elseif (isset(self::PARENT_STAGES[$state['phase']])) {
                $source = new AllegroSupplementSource($parent, $this->load($folder.'/parent-index.json'));
                ReadOnlyDatabase::run(function () use ($folder, &$state, $source, $started): void {
                    $this->parentTick($folder, $state, $source, $started + 2);
                });
            } elseif ($state['phase'] === 'manifest') {
                $this->seal($folder, $state);
            } elseif ($state['phase'] === 'package') {
                try {
                    $archive = $this->archives->tick($folder, 'archive-input.jsonl', microtime(true) + 2, containsCustomerData: false);
                } catch (\RuntimeException $error) {
                    if (in_array($error->getMessage(), ['archive_artifact_changed', 'archive_artifact_hash_mismatch', 'archive_artifact_size_changed', 'archive_index_changed', 'archive_source_missing_or_unsafe', 'archive_source_truncated'], true)) {
                        throw new MigrationExportException('supplement_artifact_integrity_failed');
                    }
                    throw $error;
                }
                if ($archive['done']) {
                    $state['done'] = true;
                    $state['phase'] = 'completed';
                }
            }
            if (isset($state['plan']) && $state['plan']['confirmed_at'] === null && $state['phase'] === 'manifest') {
                $this->readyPlan($folder, $state);
            }
            $state['last_tick'] = ['action' => 'allegro_supplement_tick', 'reason_code' => 'tick_completed', 'phase' => $state['phase'], 'shard' => $state['shard'], 'row' => $state['row'], 'duration_ms' => (int) ((microtime(true) - $started) * 1000)];
            $this->write($folder.'/checkpoint.json', $state);

            return $this->publicState($state);
        });
    }

    public function status(int $owner, string $run, string $attempt): array
    {
        [, $folder] = $this->context($owner, $run, $attempt);

        return $this->publicState($this->load($folder.'/checkpoint.json'));
    }

    public function download(int $owner, string $run, string $attempt, string $key): string
    {
        [, $folder] = $this->context($owner, $run, $attempt);
        if (! $this->load($folder.'/checkpoint.json')['done']) {
            throw new MigrationExportException('supplement_not_complete');
        }
        if (in_array($key, ['manifest', 'checksums', 'summary', 'archive-manifest'], true)) {
            $file = $folder.'/'.$key.'.json';
        } elseif ($key === 'parameters-csv') {
            $file = $folder.'/allegro_parametry_czesci.csv';
        } elseif (preg_match('/\Avolume-[0-9]{6}\z/D', $key)) {
            $archive = $this->load($folder.'/archive-manifest.json');
            $volume = collect($archive['volumes'])->firstWhere('key', $key);
            if (! $volume) {
                throw new MigrationExportException('supplement_artifact_not_found', 404);
            }
            $file = $folder.'/'.$volume['path'];
        } else {
            throw new MigrationExportException('supplement_artifact_not_found', 404);
        }
        if (! is_file($file)) {
            throw new MigrationExportException('supplement_artifact_not_found', 404);
        }

        return $file;
    }

    /** Prepare local derived files in bounded ticks; archive creation remains behind confirmation. */
    public function plan(int $owner, string $run, string $attempt): array
    {
        return $this->start($owner, $run, $attempt, requireFresh: true, requireReadback: true, preparePlan: true);
    }

    public function confirmPlan(int $owner, string $run, string $attempt, string $planId, string $digest, bool $confirm): array
    {
        [, $folder] = $this->context($owner, $run, $attempt);

        return $this->locked($folder, function () use ($folder, $planId, $digest, $confirm) {
            $state = $this->load($folder.'/checkpoint.json');
            $this->assertPlan($folder, $state);
            $plan = $state['plan'] ?? null;
            if (! $confirm || $plan === null || $plan['plan_id'] !== $planId || ! is_string($plan['plan_digest']) || ! hash_equals($plan['plan_digest'], $digest)) {
                throw new MigrationExportException('supplement_plan_confirmation_mismatch');
            }
            if ($plan['confirmed_at'] === null) {
                if ($plan['status'] !== 'READY_FOR_CONFIRMATION' || $state['phase'] !== 'planned') {
                    throw new MigrationExportException('supplement_plan_not_ready');
                }
                $state['plan']['confirmed_at'] = now()->toIso8601String();
                $state['plan']['status'] = 'CONFIRMED';
                $state['phase'] = 'manifest';
                $this->write($folder.'/checkpoint.json', $state);
            }

            return $this->publicState($state);
        });
    }

    private function datasetNames(array $state): array
    {
        return array_values(array_unique([...array_keys($state['datasets']), 'allegro_category_parameters_cache', 'allegro_product_parameters_cache', 'allegro_parameter_mappings', 'allegro_parameter_selections', 'allegro_resolved_parameters', 'conflicts', 'completeness', 'schema_freshness', 'allegro_existing_offer_parameters', 'allegro_existing_offer_readbacks', 'allegro_offer_readback_freshness', 'allegro_parameter_completeness', 'allegro_parameter_conflicts', 'allegro_schema_freshness', 'allegro_parametry_czesci']));
    }

    private function planDefinition(array $state): array
    {
        $plan = $state['plan'];

        return ['plan_id' => $plan['plan_id'], 'parent_run_id' => $state['parent_run_id'], 'parent_attempt_id' => $state['parent_attempt_id'], 'fresh_plan_id' => $state['fresh_plan_id'], 'readback_plan_id' => $state['readback_plan_id'], 'parent_index_sha256' => $state['parent_index_sha256'], 'frozen_index_sha256' => $state['frozen_index_sha256'],
            'preview' => array_diff_key($plan, array_flip(['status', 'confirmed_at', 'plan_digest'])), 'datasets' => $state['datasets'], 'files' => array_map(fn ($file) => ['bytes' => $file['bytes'], 'sha256' => SerializableSha256::digest($file['hash'])], $state['files'])];
    }

    private function readyPlan(string $folder, array &$state): void
    {
        if ($state['plan']['parts_processed'] !== $state['plan']['parts_total']) {
            throw new MigrationExportException('supplement_preview_parts_incomplete');
        }
        $state['phase'] = 'planned';
        $state['plan']['status'] = 'READY_FOR_CONFIRMATION';
        $state['plan']['ready_at'] = now()->toIso8601String();
        // CSV+JSONL datasets, four package metadata files and the later archive manifest; ZIP count is unknown.
        $state['plan']['expected_files'] = count($this->datasetNames($state)) * 2 + 5;
        $this->write($folder.'/plan-definition.json', $this->planDefinition($state));
        $state['plan']['plan_digest'] = hash_file('sha256', $folder.'/plan-definition.json');
    }

    private function assertPlan(string $folder, array $state): void
    {
        if (! isset($state['plan'])) {
            return;
        } // Packages explicitly started before durable planning stay resumable.
        $plan = $state['plan'];
        if (! Str::isUuid($plan['plan_id'] ?? null) || $plan['parent_run_id'] !== $state['parent_run_id'] || $plan['parent_attempt_id'] !== $state['parent_attempt_id'] || $plan['fresh_plan_id'] !== $state['fresh_plan_id'] || $plan['readback_plan_id'] !== $state['readback_plan_id']) {
            throw new MigrationExportException('supplement_plan_identity_mismatch');
        }
        if ($plan['plan_digest'] === null) {
            if ($plan['status'] !== 'PLANNING' || $plan['confirmed_at'] !== null || in_array($state['phase'], ['planned', 'manifest', 'package', 'completed'], true)) {
                throw new MigrationExportException('supplement_plan_not_ready');
            }

            return;
        }
        if (! hash_equals($plan['plan_digest'], (string) @hash_file('sha256', $folder.'/plan-definition.json'))) {
            throw new MigrationExportException('supplement_plan_integrity_failed');
        }
        $definition = $this->load($folder.'/plan-definition.json');
        $current = $this->planDefinition($state);
        foreach (['plan_id', 'parent_run_id', 'parent_attempt_id', 'fresh_plan_id', 'readback_plan_id', 'parent_index_sha256', 'frozen_index_sha256', 'preview'] as $field) {
            if ($definition[$field] !== $current[$field]) {
                throw new MigrationExportException('supplement_plan_integrity_failed');
            }
        }
        if ($plan['confirmed_at'] === null && ($definition['datasets'] !== $current['datasets'] || $definition['files'] !== $current['files'])) {
            throw new MigrationExportException('supplement_plan_integrity_failed');
        }
    }

    private function previewPart(array &$state, array $result): void
    {
        if (! isset($state['plan']) || $state['plan']['confirmed_at'] !== null) {
            return;
        }
        $plan = &$state['plan'];
        $plan['parts_processed']++;
        $ranks = ['COMPLETE' => 0, 'PARTIAL' => 1, 'SCHEMA_UNKNOWN' => 2, 'CONFLICT' => 3];
        $status = 'SCHEMA_UNKNOWN';
        $rank = -1;
        $missing = [];
        foreach ($result['completeness'] as $report) {
            $candidate = $report['status'] ?? strtoupper($report['completeness_status']);
            $candidate = isset($ranks[$candidate]) ? $candidate : 'PARTIAL';
            if ($ranks[$candidate] > $rank) {
                $status = $candidate;
                $rank = $ranks[$candidate];
            }
            foreach ($report['missing_required_parameter_ids'] as $id) {
                $missing[$this->json([$report['allegro_category_id'], (string) $id])] = true;
            }
        }
        $plan['part_statuses'][$status]++;
        $plan['missing_required'] += count($missing);
        $parameters = [];
        foreach ($result['rows'] as $row) {
            if ($row['parameter_id'] === null || ! AllegroSupplementResolver::present($row)) {
                continue;
            }
            $parameters[$this->json([$row['allegro_category_id'], $row['parameter_scope'], $row['product_set_index'], $row['parameter_id']])] = true;
            $source = $row['provenance'];
            $plan['source_values'][$source] = ($plan['source_values'][$source] ?? 0) + 1;
            if ($row['is_preferred'] && $row['conflict_id'] === null && $row['local_validation_status'] === 'value_present_in_local_schema') {
                $plan['resolved_values_by_source'][$source] = ($plan['resolved_values_by_source'][$source] ?? 0) + 1;
            }
        }
        $plan['parameters_found'] += count($parameters);
    }

    private function offerContext(int $owner, string $run, string $attempt): ?array
    {
        try {
            return $this->readback->completed($owner, $run, $attempt);
        } catch (MigrationExportException $e) {
            if (in_array($e->reasonCode, ['offer_plan_not_started', 'supplement_offer_readback_required'], true)) {
                return null;
            }
            throw $e;
        }
    }

    private function offerCacheTick(string $folder, array &$state): void
    {
        $context = $state['readback_context'];
        $offer = $context['offers'][$state['readback_offset']] ?? null;
        if ($offer === null) {
            $state['phase'] = self::TABLES[1];

            return;
        }
        $receipt = $this->readback->receipt($context['directory'], $offer['offer_id']);
        if (! ($receipt['confirmed'] ?? false)) {
            throw new MigrationExportException('supplement_offer_receipt_missing');
        }
        $freshness = ['offer_id' => $offer['offer_id'], 'links' => array_values($offer['links']), 'readback_plan_id' => $context['plan_id'], 'http_status' => $receipt['http_status'] ?? null, 'reason_code' => $receipt['reason_code'], 'fetched_at' => $receipt['fetched_at'] ?? null, 'attempts' => $receipt['attempts'], 'sha256' => $receipt['sha256'] ?? null];
        $this->appendRows($folder, $state, 'allegro_offer_readback_freshness', [$freshness], array_keys($freshness));
        if ($receipt['ok']) {
            $raw = $receipt + ['links' => array_values($offer['links']), 'readback_plan_id' => $context['plan_id']];
            $this->appendRows($folder, $state, 'allegro_existing_offer_readbacks', [$raw], array_keys($raw));
        }
        $parts = array_unique(array_column($offer['links'], 'part_id'));
        foreach ($parts as $part) {
            $link = collect($offer['links'])->firstWhere('part_id', $part);
            if ($receipt['ok']) {
                $candidates = $this->resolver->readbackCandidates($receipt, (string) $part, $link, count($parts) > 1);
                $normalized = array_values(array_filter($candidates, fn ($r) => $r['parameter_id'] !== null));
                foreach ($normalized as &$row) {
                    $cache = $this->get($folder, 'schema', $row['allegro_category_id']);
                    $definitions = $row['scope'] === 'product' ? ($cache['product_raw_response']['parameters'] ?? []) : ($cache['raw_response']['parameters'] ?? []);
                    $definition = collect($definitions)->first(fn ($d) => (string) $d['id'] === $row['parameter_id']);
                    $row['parameter_name'] = $definition['name'] ?? $row['parameter_name'] ?? null;
                    $available = ($row['scope'] === 'product' ? ($cache['product_schema_available'] ?? false) : ($cache['offer_schema_available'] ?? false));
                    $row['historical_schema_mismatch'] = $available && $definition === null;
                    $row['schema_status'] = ! $available ? 'schema_unknown' : ($definition === null ? 'historical_schema_mismatch' : 'known');
                }
                unset($row);
                $this->appendRows($folder, $state, 'allegro_existing_offer_parameters', $normalized, AllegroSupplementResolver::EXISTING_FIELDS);
            } else {
                $candidates = [['allegro_category_id' => null, 'parameter_id' => null, 'source' => 'existing_allegro_offer', 'allegro_offer_id' => $offer['offer_id'], 'offer_id' => $offer['offer_id'], 'readback_unavailable' => true, 'source_field' => 'readback.'.$receipt['reason_code']]];
            }
            $this->putGroup($folder, 'readback', (string) $part, $offer['offer_id'], $candidates);
        }
        $state['readback_offset']++;
    }

    private function fresh(int $owner, string $run, string $attempt): ?array
    {
        try {
            return $this->refresh->completed($owner, $run, $attempt);
        } catch (MigrationExportException $e) {
            if (in_array($e->reasonCode, ['schema_plan_not_started', 'supplement_schema_refresh_required'], true)) {
                return null;
            }
            throw $e;
        }
    }

    private function freshCacheTick(string $folder, array &$state): void
    {
        $context = $state['refresh_context'];
        $category = $context['categories'][$state['refresh_offset']] ?? null;
        if ($category === null) {
            $state['phase'] = ($state['readback_plan_id'] ?? null) ? 'offer_readback' : self::TABLES[1];

            return;
        }
        $category = (string) $category;
        $cache = ['raw_response' => ['parameters' => []], 'product_raw_response' => ['parameters' => []], 'offer_schema_available' => false, 'product_schema_available' => false, 'fresh_schema' => true];
        $reports = [];
        foreach (AllegroSchemaReadClient::ENDPOINTS as $endpoint) {
            $receipt = $this->refresh->receipt($context['directory'], $category, $endpoint);
            if ($receipt === null || ! $receipt['confirmed']) {
                throw new MigrationExportException('supplement_refresh_receipt_missing');
            }
            $reports[] = ['category_id' => $category, 'endpoint' => 'GET /sale/categories/'.$category.'/'.$endpoint, 'http_status' => $receipt['http_status'] ?? null, 'reason_code' => $receipt['reason_code'], 'fetched_at' => $receipt['fetched_at'] ?? null, 'attempts' => $receipt['attempts'], 'schema_snapshot_id' => $receipt['sha256'] ?? null, 'refresh_plan_id' => $context['plan_id']];
            if (! $receipt['ok']) {
                continue;
            }
            $row = ['category_id' => $category, 'allegro_category_id' => $category, 'fetched_at' => $receipt['fetched_at'], 'endpoint' => $receipt['endpoint'], 'source' => $receipt['source'],
                'raw_response' => $receipt['raw_response'], 'raw_response_text' => $receipt['raw_response_text'], 'schema_snapshot_id' => $receipt['sha256'], 'parameters' => $receipt['raw_response']['parameters']];
            $this->appendRows($folder, $state, $endpoint === 'parameters' ? self::TABLES[0] : 'allegro_product_parameters_cache', [$row], array_keys($row));
            if ($endpoint === 'parameters') {
                $cache['raw_response'] = $receipt['raw_response'];
                $cache['offer_schema_available'] = true;
                $cache['fetched_at'] = $receipt['fetched_at'];
                $cache['endpoint'] = $receipt['endpoint'];
            } else {
                $cache['product_raw_response'] = $receipt['raw_response'];
                $cache['product_schema_available'] = true;
                $cache['product_fetched_at'] = $receipt['fetched_at'];
            }
        }
        $cache['schema_snapshot_id'] = hash('sha256', $this->json($reports));
        $this->put($folder, 'schema', $category, $cache);
        $this->appendRows($folder, $state, 'schema_freshness', $reports, array_keys($reports[0]));
        if ($state['readback_plan_id'] ?? null) {
            $this->appendRows($folder, $state, 'allegro_schema_freshness', $reports, array_keys($reports[0]));
        }
        $state['refresh_offset']++;
    }

    private function captureTable(string $folder, array &$state): void
    {
        $name = $state['phase'];
        $window = &$state['tables'][$name];
        $capturePath = $folder.'/capture-'.$name.'-'.$window['cursor'].'.json';
        if (is_file($capturePath)) {
            $rows = $this->load($capturePath);
        } else {
            $rows = $window['available'] ? DB::table($name)->where('id', '>', $window['cursor'])->where('id', '<=', $window['max_id'])->orderBy('id')->limit($name === self::TABLES[0] ? 1 : 5)->get($window['columns'])->map(fn ($row) => MigrationDataPolicy::clean((array) $row) + ['source_observed_at' => now()->toIso8601String()])->all() : [];
            // Freeze the page before publishing any derivative. Retrying a lost checkpoint cannot mix versions.
            $this->write($capturePath, $rows);
        }
        $columns = [...$window['columns'], 'source_observed_at'];
        if ($name === self::TABLES[0]) {
            $columns = array_values(array_unique([...$columns, 'allegro_category_id', 'schema_snapshot_id', 'parameters', 'schema_status']));
        } else {
            $columns = array_values(array_unique([...$columns, 'source_type', 'constant_value', 'dictionary_value_mapping']));
        }
        foreach ($rows as &$row) {
            if ($name === self::TABLES[0]) {
                $row['allegro_category_id'] = (string) ($row['allegro_category_id'] ?? $row['category_id'] ?? '');
                $row['schema_snapshot_id'] = hash('sha256', $this->json([$row['allegro_category_id'], $row['raw_response']]));
                $valid = is_array($row['raw_response'] ?? null) && is_array($row['raw_response']['parameters'] ?? null);
                foreach ($valid ? $row['raw_response']['parameters'] : [] as $parameter) {
                    if (! is_array($parameter) || ! is_scalar($parameter['id'] ?? null) || (isset($parameter['dictionary']) && ! is_array($parameter['dictionary']))) {
                        $valid = false;
                    }
                }
                $row['schema_status'] = $valid ? 'local_cache_preserved_not_refreshed' : 'schema_invalid_locally';
                $row['parameters'] = [];
                foreach ($valid ? $row['raw_response']['parameters'] : [] as $parameter) {
                    $row['parameters'][] = ['parameter_id' => (string) ($parameter['id'] ?? ''), 'parameter_name' => $parameter['name'] ?? null, 'parameter_type' => $parameter['type'] ?? null,
                        'dictionary' => $parameter['dictionary'] ?? null, 'required' => $parameter['required'] ?? null, 'requiredForProduct' => $parameter['options']['requiredForProduct'] ?? $parameter['requiredForProduct'] ?? null,
                        'describesProduct' => $parameter['options']['describesProduct'] ?? null, 'multipleChoices' => $parameter['restrictions']['multipleChoices'] ?? $parameter['multipleChoices'] ?? null, 'restrictions' => $parameter['restrictions'] ?? null];
                }
                if ($valid && $row['allegro_category_id'] !== '') {
                    $this->put($folder, 'schema', $row['allegro_category_id'], $row);
                }
            } else {
                $constant = filled($row['fixed_value_id'] ?? null) || filled($row['fixed_value_label'] ?? null);
                $row['source_type'] = $constant ? 'constant' : (filled($row['source_field'] ?? null) ? 'source_field' : 'unknown');
                $row['constant_value'] = $constant ? ['value_id' => $row['fixed_value_id'] ?? null, 'value_label' => $row['fixed_value_label'] ?? null] : null;
                $row['dictionary_value_mapping'] = $row['metadata']['dictionary_value_mapping'] ?? $row['metadata']['dictionary_map'] ?? $row['metadata']['value_mapping'] ?? null;
                $this->putGroup($folder, 'rules', (string) $row['allegro_category_id'], (string) $row['id'], $row);
            }
            $window['cursor'] = (string) $row['id'];
            $window['rows']++;
        }
        unset($row);
        $this->appendRows($folder, $state, $name, $rows, $columns);
        if ($rows === []) {
            $window['completed_at'] = now()->toIso8601String();
            $state['phase'] = $name === self::TABLES[0] ? self::TABLES[1] : 'parts_index';
        }
    }

    private function parentTick(string $folder, array &$state, AllegroSupplementSource $source, float $deadline): void
    {
        $phase = $state['phase'];
        $dataset = self::PARENT_STAGES[$phase];
        $rows = $source->shard($dataset, $state['shard']);
        if ($rows === null) {
            $phases = array_keys(self::PARENT_STAGES);
            $state['phase'] = $phases[array_search($phase, $phases, true) + 1] ?? 'manifest';
            $state['shard'] = 0;
            $state['row'] = 0;

            return;
        }
        $count = 0;
        while (isset($rows[$state['row']]) && $count < 5 && microtime(true) < $deadline) {
            $row = $rows[$state['row']];
            $id = (string) ($row['id'] ?? '');
            if ($phase === 'parts_index') {
                $this->put($folder, 'parts', $id, ['present' => true]);
            } elseif ($phase === 'categories_index') {
                $this->put($folder, 'categories', $id, ['id' => $id, 'name' => $row['name'] ?? null]);
            } elseif ($phase === 'category_mappings_index' && in_array($row['channel'] ?? null, ['allegro', 'allegro_main'], true)) {
                $this->putGroup($folder, 'category_mappings', (string) $row['local_category_id'], $id, $row);
            } elseif ($phase === 'manual_index') {
                $this->appendRows($folder, $state, 'allegro_parameter_selections', [$row], $source->index['datasets']['allegro_parameter_selections']['csv_columns'] ?? array_keys($row));
                $this->putGroup($folder, 'manual', (string) $row['part_id'], $id, $row);
            } elseif ($phase === 'listings_index' && str_starts_with((string) ($row['marketplace'] ?? ''), 'allegro')) {
                $candidates = $this->resolver->historical((array) ($row['raw_payload'] ?? []), 'marketplace_listing_payload', null, $id, isset($row['marketplace_account_id']) ? (string) $row['marketplace_account_id'] : null, $row['last_synced_at'] ?? $row['updated_at'] ?? null, 'marketplace_listings.raw_payload');
                $partId = isset($row['part_id']) ? (string) $row['part_id'] : null;
                if ($candidates !== [] && ($partId === null || $this->get($folder, 'parts', $partId) === null)) {
                    $result = $this->resolver->orphan($partId, $candidates, fn ($category) => $this->get($folder, 'schema', $category), $state['captured_at']);
                    $this->record($folder, $state, $result);
                    $state['stats']['orphan_listing_parameter_rows'] += count($result['rows']);
                } elseif ($candidates !== []) {
                    $this->putGroup($folder, 'history', $partId, $id, $candidates);
                }
            } elseif ($phase === 'resolve') {
                $result = $this->resolver->resolve($row, $source->find('cars', isset($row['car_id']) ? (string) $row['car_id'] : null), $this->get($folder, 'categories', (string) ($row['category_id'] ?? '')),
                    array_values($this->get($folder, 'category_mappings', (string) ($row['category_id'] ?? '')) ?? []), array_values($this->get($folder, 'manual', $id) ?? []),
                    array_merge([], ...array_values($this->get($folder, 'history', $id) ?? []), ...array_values($this->get($folder, 'readback', $id) ?? [])), fn ($category) => $this->get($folder, 'schema', $category), fn ($category) => array_values($this->get($folder, 'rules', $category) ?? []), $state['captured_at'], freshRequired: ($state['fresh_plan_id'] ?? null) !== null);
                $this->record($folder, $state, $result);
                $this->previewPart($state, $result);
            }
            if (isset($state['plan']) && $state['plan']['confirmed_at'] === null) {
                $state['plan']['stage_rows'][$phase] = ($state['plan']['stage_rows'][$phase] ?? 0) + 1;
            }
            $state['row']++;
            $count++;
        }
        if ($state['row'] >= count($rows)) {
            $state['shard']++;
            $state['row'] = 0;
        }
    }

    private function record(string $folder, array &$state, array $result): void
    {
        $this->appendRows($folder, $state, 'allegro_resolved_parameters', $result['rows'], AllegroSupplementResolver::FIELDS);
        $this->appendRows($folder, $state, 'conflicts', $result['conflicts'], ['conflict_id', 'part_id', 'allegro_category_id', 'parameter_id', 'reason_code', 'candidates']);
        $this->appendRows($folder, $state, 'completeness', $result['completeness'], AllegroSupplementResolver::COMPLETENESS_FIELDS);
        if ($state['readback_plan_id'] ?? null) {
            $this->appendRows($folder, $state, 'allegro_parameter_completeness', $result['completeness'], AllegroSupplementResolver::COMPLETENESS_FIELDS);
            $this->appendRows($folder, $state, 'allegro_parameter_conflicts', $result['conflicts'], ['conflict_id', 'part_id', 'allegro_category_id', 'parameter_id', 'reason_code', 'candidates']);
            $import = $this->resolver->importRows($result['rows']);
            $this->appendRows($folder, $state, 'allegro_parametry_czesci', $import, AllegroSupplementResolver::IMPORT_FIELDS);
        }
        $state['stats']['parts_with_manual_selections'] += (int) $result['has_manual_selections'];
        $state['stats']['resolved_parameter_rows'] += count($result['rows']);
        $state['stats']['conflicts'] += count($result['conflicts']);
        $state['stats']['historical_only_parameters'] += $result['historical_only_parameters'];
        foreach ($result['rows'] as $row) {
            if ($row['parameter_id'] !== null) {
                $state['parameter_ids'][$row['parameter_id']] = true;
            }
            if ($row['allegro_category_id'] !== null) {
                $state['category_ids'][$row['allegro_category_id']] = true;
            }
            $state['stats']['completeness'][$row['completeness_status']]++;
        }
        foreach ($result['completeness'] as $report) {
            $status = $report['status'] ?? strtoupper($report['completeness_status']);
            if ($status === 'HISTORICAL_ONLY') {
                $status = 'PARTIAL';
            }
            $state['stats']['part_category_completeness'][$status] = ($state['stats']['part_category_completeness'][$status] ?? 0) + 1;
            if ($report['reason_code'] === 'schema_unavailable_locally' && $report['allegro_category_id'] !== null) {
                $state['missing_schema_categories'][$report['allegro_category_id']] = true;
            }
            $state['stats']['missing_required_values'] += count($report['missing_required_parameter_ids']);
        }
    }

    private function appendRows(string $folder, array &$state, string $dataset, array $rows, array $columns): void
    {
        $rows = AllegroParameterDataPolicy::clean($rows);
        foreach ($rows as &$row) {
            if (is_array($row['raw_response_text'] ?? null)) {
                $row['raw_response_text'] = $this->json($row['raw_response_text']);
            }
        }
        unset($row);
        $json = implode('', array_map(fn ($row) => $this->json($row)."\n", $rows));
        $stream = fopen('php://temp', 'w+b');
        $csvFile = $dataset.'.csv';
        if (! isset($state['files'][$csvFile])) {
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, $columns, ';', '"', '');
        }
        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                $value = $value === null ? '\\N' : (is_array($value) ? $this->json($value) : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value));
                if (($row[$column] ?? null) !== null && str_starts_with($value, '\\')) {
                    $value = '\\'.$value;
                }
                if (preg_match('/^[=+\-@]/', $value)) {
                    $value = "'".$value;
                }
                $values[] = $value;
            }
            fputcsv($stream, $values, ';', '"', '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        foreach ([$dataset.'.jsonl' => $json, $csvFile => $csv] as $file => $bytes) {
            $descriptor = $state['files'][$file] ?? ['bytes' => 0, 'hash' => SerializableSha256::initial()];
            $handle = fopen($folder.'/'.$file, 'c+b');
            chmod($folder.'/'.$file, 0600);
            if (! $handle || ! ftruncate($handle, $descriptor['bytes']) || fseek($handle, $descriptor['bytes']) !== 0) {
                throw new MigrationExportException('supplement_file_write_failed');
            }
            try {
                if ($bytes !== '' && fwrite($handle, $bytes) !== strlen($bytes)) {
                    throw new MigrationExportException('supplement_file_write_failed');
                }
                if (! fflush($handle)) {
                    throw new MigrationExportException('supplement_file_write_failed');
                }
            } finally {
                fclose($handle);
            }
            $descriptor['bytes'] += strlen($bytes);
            $descriptor['hash'] = SerializableSha256::update($descriptor['hash'], $bytes);
            $state['files'][$file] = $descriptor;
        }
        $state['datasets'][$dataset] = ['columns' => $columns, 'rows' => ($state['datasets'][$dataset]['rows'] ?? 0) + count($rows)];
    }

    private function seal(string $folder, array &$state): void
    {
        foreach (['allegro_resolved_parameters' => AllegroSupplementResolver::FIELDS, 'conflicts' => ['conflict_id', 'part_id', 'allegro_category_id', 'parameter_id', 'reason_code', 'candidates'], 'completeness' => AllegroSupplementResolver::COMPLETENESS_FIELDS] as $name => $columns) {
            if (! isset($state['datasets'][$name])) {
                $this->appendRows($folder, $state, $name, [], $columns);
            }
        }
        foreach (['allegro_parameter_selections' => ['part_id', 'allegro_category_id', 'parameter_id', 'value_id'], 'allegro_category_parameters_cache' => ['category_id', 'raw_response', 'fetched_at', 'endpoint'], 'allegro_product_parameters_cache' => ['category_id', 'raw_response', 'fetched_at', 'endpoint'], 'schema_freshness' => ['category_id', 'endpoint', 'http_status', 'reason_code', 'fetched_at', 'schema_snapshot_id']] as $dataset => $columns) {
            if (! isset($state['datasets'][$dataset])) {
                $this->appendRows($folder, $state, $dataset, [], $columns);
            }
        }
        if ($state['readback_plan_id'] ?? null) {
            foreach (['allegro_existing_offer_parameters' => AllegroSupplementResolver::EXISTING_FIELDS, 'allegro_parametry_czesci' => AllegroSupplementResolver::IMPORT_FIELDS, 'allegro_parameter_completeness' => AllegroSupplementResolver::COMPLETENESS_FIELDS, 'allegro_parameter_conflicts' => ['conflict_id', 'part_id', 'allegro_category_id', 'parameter_id', 'reason_code', 'candidates'], 'allegro_schema_freshness' => ['category_id', 'endpoint', 'http_status', 'reason_code', 'fetched_at'], 'allegro_existing_offer_readbacks' => ['offer_id', 'raw_response', 'fetched_at'], 'allegro_offer_readback_freshness' => ['offer_id', 'http_status', 'reason_code', 'fetched_at']] as $name => $columns) {
                if (! isset($state['datasets'][$name])) {
                    $this->appendRows($folder, $state, $name, [], $columns);
                }
            }
        }
        $summary = $this->summary($state);
        $parentIndex = $this->load($folder.'/parent-index.json');
        $this->write($folder.'/summary.json', $summary);
        $this->atomicBytes($folder.'/schema.json', file_get_contents(resource_path('allegro-resolved-parameters-supplement.schema.json')));
        $manifest = ['format' => self::FORMAT, 'parent_run_id' => $state['parent_run_id'], 'parent_attempt_id' => $state['parent_attempt_id'], 'parent_index_sha256' => $state['parent_index_sha256'], 'captured_at' => $state['captured_at'],
            'consistency' => ($state['fresh_plan_id'] ?? null) ? 'sealed_parent_artifacts_plus_operator_confirmed_provider_schemas_and_later_local_rules_not_transactional' : 'sealed_parent_artifacts_plus_later_local_cache_and_rules_capture_not_transactional', 'local_table_boundaries' => $state['tables'], 'datasets' => $state['datasets'], 'summary' => $summary, 'schema_mode' => $state['schema_mode'] ?? 'legacy_local_only', 'contract_revision' => $state['contract_revision'] ?? 1, 'schema_refresh' => $state['refresh_context']['summary'] ?? null, 'offer_readback' => $state['readback_context']['summary'] ?? null, 'readback_plan_id' => $state['readback_plan_id'] ?? null, 'parameter_source_priority' => ['existing_allegro_offer', 'manual_selection', 'local_mapping', 'derived_from_part', 'derived_from_car', 'unresolved'],
            'parent_database_consistency' => $parentIndex['database_consistency'] ?? null, 'parent_consistency_exception' => $parentIndex['consistency_exception'] ?? null,
            'resolved_parameters_schema' => 'schema.json', 'supplement_plan' => $state['plan'] ?? null,
            'formats' => ['jsonl' => 'UTF-8; identifiers are strings; null remains null; arrays are preserved', 'csv' => 'UTF-8 BOM; semicolon; nested JSON; null=\\N; literal leading backslash doubled; formula prefix apostrophe; import identifiers as TEXT'],
            'limitations' => [($state['fresh_plan_id'] ?? null) ? 'schema_freshness_is_per_endpoint_fetch_time_not_permanent_or_transactional' : 'local_cache_is_not_refreshed_or_asserted_current', 'parent_parts_and_listings_are_not_recaptured', 'inherited_parent_consistency_exceptions_remain_applicable', 'late_local_table_updates_not_historical_as_of_parent', 'source_observed_at_may_be_unknown', 'historical_candidates_not_authorized_for_republication', 'complete_means_locally_resolved_parameter_not_publish_readiness', 'unknown_categories_and_values_reported_not_inferred'],
            'database_writes' => false, 'business_writes' => false, 'provider_calls' => false, 'automatic_import_allowed' => false, 'automatic_publish' => false, 'contains_customer_data' => false, 'parent_artifacts_modified' => false];
        $this->write($folder.'/manifest.json', $manifest);
        $checksums = [];
        foreach ($state['files'] as $path => $descriptor) {
            $checksums[] = ['path' => $path, 'bytes' => $descriptor['bytes'], 'sha256' => SerializableSha256::digest($descriptor['hash'])];
        }
        foreach (['manifest.json', 'summary.json', 'schema.json'] as $path) {
            $checksums[] = ['path' => $path, 'bytes' => filesize($folder.'/'.$path), 'sha256' => hash_file('sha256', $folder.'/'.$path)];
        }
        $this->write($folder.'/checksums.json', $checksums);
        $checksums[] = ['path' => 'checksums.json', 'bytes' => filesize($folder.'/checksums.json'), 'sha256' => hash_file('sha256', $folder.'/checksums.json')];
        $this->atomicBytes($folder.'/archive-input.jsonl', implode('', array_map(fn ($entry) => $this->json($entry + ['archive_path' => $entry['path']])."\n", $checksums)));
        $state['phase'] = 'package';
    }

    private function rollbackUncommitted(string $folder, array $state): void
    {
        foreach ($state['files'] as $file => $descriptor) {
            $path = $folder.'/'.$file;
            if (! is_file($path) || filesize($path) < $descriptor['bytes']) {
                throw new MigrationExportException('supplement_committed_file_missing_or_truncated');
            }
            if (filesize($path) > $descriptor['bytes']) {
                $handle = fopen($path, 'c+b');
                try {
                    if (! ftruncate($handle, $descriptor['bytes'])) {
                        throw new MigrationExportException('supplement_file_write_failed');
                    }
                } finally {
                    fclose($handle);
                }
            }
        }
        // Files created by an interrupted first append have no committed descriptor yet.
        foreach (glob($folder.'/*.csv') ?: [] as $path) {
            if (! isset($state['files'][basename($path)])) {
                unlink($path);
            }
        }
        foreach (glob($folder.'/*.jsonl') ?: [] as $path) {
            if (! isset($state['files'][basename($path)]) && basename($path) !== 'archive-input.jsonl') {
                unlink($path);
            }
        }
    }

    private function summary(array $state): array
    {
        return $state['stats'] + ['distinct_parameter_ids' => count($state['parameter_ids']), 'distinct_allegro_category_ids' => count($state['category_ids']), 'categories_without_local_schema' => count($state['missing_schema_categories'])];
    }

    private function planProgress(array $state): ?array
    {
        if (! isset($state['plan']) || $state['plan']['confirmed_at'] !== null) {
            return null;
        }
        $plan = $state['plan'];
        $phase = $state['phase'];
        if ($phase === 'refreshed_schema') {
            return ['label' => 'Analiza zapisanych definicji', 'processed' => $state['refresh_offset'], 'total' => $plan['categories']];
        }
        if ($phase === 'offer_readback') {
            return ['label' => 'Analiza zapisanych wyników ofert', 'processed' => $state['readback_offset'], 'total' => $plan['offers']];
        }
        if (in_array($phase, ['resolve', 'planned'], true)) {
            return ['label' => $phase === 'planned' ? 'Plan gotowy do zatwierdzenia' : 'Analiza parametrów części', 'processed' => $plan['parts_processed'], 'total' => $plan['parts_total']];
        }
        if (isset(self::PARENT_STAGES[$phase])) {
            return ['label' => 'Przygotowanie powiązań danych', 'processed' => $plan['stage_rows'][$phase] ?? 0, 'total' => $plan['dataset_totals'][self::PARENT_STAGES[$phase]] ?? null];
        }

        return ['label' => 'Analiza lokalnych mapowań', 'processed' => $state['tables'][$phase]['rows'] ?? 0, 'total' => null];
    }

    private function publicState(array $state): array
    {
        return array_intersect_key($state, array_flip(['contract_revision', 'schema_mode', 'fresh_plan_id', 'readback_plan_id', 'format', 'parent_run_id', 'parent_attempt_id', 'phase', 'shard', 'row', 'done', 'last_tick', 'plan'])) + ['schema_mode' => $state['schema_mode'] ?? 'legacy_local_only', 'contract_revision' => $state['contract_revision'] ?? 1, 'next_action' => $state['done'] ? 'download' : ($state['phase'] === 'planned' ? 'confirm' : (isset($state['plan']) && $state['plan']['confirmed_at'] === null ? 'plan_tick' : 'tick')), 'summary' => $this->summary($state), 'plan_progress' => $this->planProgress($state), 'dataset_counts' => array_map(fn ($dataset) => $dataset['rows'], $state['datasets']), 'provider_calls' => false, 'business_writes' => false];
    }

    private function context(int $owner, string $run, string $attempt): array
    {
        $parent = $this->parents->supplementParent($owner, $run, $attempt);
        $folder = $this->folder($parent, $run, $attempt, $this->fresh($owner, $run, $attempt), $this->offerContext($owner, $run, $attempt));
        if (! is_file($folder.'/checkpoint.json')) {
            throw new MigrationExportException('supplement_not_started', 404);
        }
        $state = $this->load($folder.'/checkpoint.json');
        $this->assertParent($parent, $state);
        $this->assertPlan($folder, $state);

        return [$parent, $folder];
    }

    private function folder(string $parent, string $run, string $attempt, ?array $fresh = null, ?array $offers = null): string
    {
        return dirname($parent).'/.allegro-parameters-supplements/'.$run.'/'.$attempt.($fresh ? '/fresh-'.$fresh['plan_id'] : '').($offers ? '/offers-'.$offers['plan_id'] : '');
    }

    private function putGroup(string $folder, string $bucket, string $key, string $item, array $row): void
    {
        $group = $this->get($folder, $bucket, $key) ?? [];
        $group[$item] = $row;
        $this->put($folder, $bucket, $key, $group);
    }

    private function get(string $folder, string $bucket, string $key): ?array
    {
        $path = $folder.'/work/'.$bucket.'/'.hash('sha256', $key).'.json';
        if (! is_file($path)) {
            return null;
        }
        $envelope = $this->load($path);
        if (! isset($envelope['data'], $envelope['sha256']) || ! hash_equals($envelope['sha256'], hash('sha256', $this->json($envelope['data'])))) {
            throw new MigrationExportException('supplement_work_index_invalid');
        }

        return $envelope['data'];
    }

    private function put(string $folder, string $bucket, string $key, array $row): void
    {
        $directory = $folder.'/work/'.$bucket;
        $this->mkdir($directory);
        $this->write($directory.'/'.hash('sha256', $key).'.json', ['data' => $row, 'sha256' => hash('sha256', $this->json($row))]);
    }

    private function assertParent(string $parent, array $state): void
    {
        $file = $parent.'/logical-snapshot/'.$state['parent_attempt_id'].'/checkpoint.json';
        if (! is_file($file) || ! hash_equals($state['parent_index_sha256'], hash_file('sha256', $file))) {
            throw new MigrationExportException('supplement_parent_boundary_changed');
        }
    }

    private function locked(string $folder, callable $work): mixed
    {
        $lock = fopen($folder.'/lock', 'c');
        chmod($folder.'/lock', 0600);
        if (! $lock) {
            throw new MigrationExportException('supplement_lock_unavailable');
        }
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new MigrationExportException('supplement_busy');
        }
        try {
            return $work();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function mkdir(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new MigrationExportException('supplement_directory_unavailable');
        }
    }

    private function load(string $path): array
    {
        try {
            return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\Throwable) {
            throw new MigrationExportException('supplement_checkpoint_or_index_invalid');
        }
    }

    private function write(string $path, array $value): void
    {
        $this->atomicBytes($path, $this->json($value));
    }

    private function atomicBytes(string $path, string $bytes): void
    {
        if (file_put_contents($path.'.tmp', $bytes, LOCK_EX) !== strlen($bytes) || ! chmod($path.'.tmp', 0600) || ! rename($path.'.tmp', $path)) {
            throw new MigrationExportException('supplement_file_write_failed');
        }
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
