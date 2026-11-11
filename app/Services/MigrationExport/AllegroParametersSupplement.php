<?php

namespace App\Services\MigrationExport;

use App\Services\Marketplace\ShippingRateAudit\ReadOnlyDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Independent, private, bounded supplement. Parent checkpoint/artifacts are opened read-only. */
final class AllegroParametersSupplement
{
    public const FORMAT = 'gpswiss-allegro-parameters-supplement-v1';

    private const TABLES = ['allegro_category_parameters_cache', 'allegro_parameter_mappings'];

    private const PARENT_STAGES = ['parts_index' => 'parts', 'categories_index' => 'part_categories', 'category_mappings_index' => 'marketplace_category_mappings', 'manual_index' => 'allegro_parameter_selections', 'listings_index' => 'marketplace_listings', 'resolve' => 'parts'];

    public function __construct(private GpswissMigrationExport $parents, private BrowserMigrationSnapshot $snapshots, private AllegroSupplementResolver $resolver, private BrowserMigrationArchives $archives, private AllegroSchemaRefresh $refresh) {}

    public function start(int $owner, string $run, string $attempt, bool $requireFresh = false): array
    {
        $parent = $this->parents->supplementParent($owner, $run, $attempt);
        $fresh = $this->fresh($owner, $run, $attempt);
        if ($requireFresh && $fresh === null) {
            throw new MigrationExportException('supplement_schema_refresh_required');
        }
        $folder = $this->folder($parent, $run, $attempt, $fresh);
        $this->mkdir($folder);

        return $this->locked($folder, function () use ($folder, $parent, $run, $attempt, $fresh): array {
            if (is_file($folder.'/checkpoint.json')) {
                $state = $this->load($folder.'/checkpoint.json');
                $this->assertParent($parent, $state);

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
            $state = ['format' => self::FORMAT, 'parent_run_id' => $run, 'parent_attempt_id' => $attempt, 'captured_at' => now()->toIso8601String(), 'phase' => $fresh ? 'refreshed_schema' : self::TABLES[0], 'fresh_plan_id' => $fresh['plan_id'] ?? null, 'schema_mode' => $fresh ? 'provider_get_refresh' : 'legacy_local_only', 'contract_revision' => $fresh ? 2 : 1, 'refresh_context' => $fresh, 'refresh_offset' => 0, 'shard' => 0, 'row' => 0,
                'parent_index_sha256' => hash_file('sha256', $parent.'/logical-snapshot/'.$attempt.'/checkpoint.json'), 'tables' => [], 'files' => [], 'datasets' => [], 'done' => false,
                'stats' => ['parts_with_manual_selections' => 0, 'resolved_parameter_rows' => 0, 'conflicts' => 0, 'historical_only_parameters' => 0, 'missing_required_values' => 0, 'orphan_listing_parameter_rows' => 0,
                    'part_category_completeness' => array_fill_keys(['COMPLETE', 'PARTIAL', 'SCHEMA_UNKNOWN', 'CONFLICT'], 0), 'completeness' => array_fill_keys(['complete', 'partial', 'historical_only', 'schema_unknown', 'conflict'], 0)], 'parameter_ids' => [], 'category_ids' => [], 'missing_schema_categories' => []];
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
            if ($state['done']) {
                return $this->publicState($state);
            }
            if (! hash_equals($state['frozen_index_sha256'], hash_file('sha256', $folder.'/parent-index.json'))) {
                throw new MigrationExportException('supplement_frozen_index_invalid');
            }
            $this->rollbackUncommitted($folder, $state);
            $started = microtime(true);
            if ($state['phase'] === 'refreshed_schema') {
                $this->freshCacheTick($folder, $state);
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
            $state['phase'] = self::TABLES[1];

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
                    array_merge([], ...array_values($this->get($folder, 'history', $id) ?? [])), fn ($category) => $this->get($folder, 'schema', $category), fn ($category) => array_values($this->get($folder, 'rules', $category) ?? []), $state['captured_at'], freshRequired: ($state['fresh_plan_id'] ?? null) !== null);
                $this->record($folder, $state, $result);
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
        $rows = MigrationDataPolicy::clean($rows);
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
        $summary = $this->summary($state);
        $parentIndex = $this->load($folder.'/parent-index.json');
        $this->write($folder.'/summary.json', $summary);
        $this->atomicBytes($folder.'/schema.json', file_get_contents(resource_path('allegro-resolved-parameters-supplement.schema.json')));
        $manifest = ['format' => self::FORMAT, 'parent_run_id' => $state['parent_run_id'], 'parent_attempt_id' => $state['parent_attempt_id'], 'parent_index_sha256' => $state['parent_index_sha256'], 'captured_at' => $state['captured_at'],
            'consistency' => ($state['fresh_plan_id'] ?? null) ? 'sealed_parent_artifacts_plus_operator_confirmed_provider_schemas_and_later_local_rules_not_transactional' : 'sealed_parent_artifacts_plus_later_local_cache_and_rules_capture_not_transactional', 'local_table_boundaries' => $state['tables'], 'datasets' => $state['datasets'], 'summary' => $summary, 'schema_mode' => $state['schema_mode'] ?? 'legacy_local_only', 'contract_revision' => $state['contract_revision'] ?? 1, 'schema_refresh' => $state['refresh_context']['summary'] ?? null,
            'parent_database_consistency' => $parentIndex['database_consistency'] ?? null, 'parent_consistency_exception' => $parentIndex['consistency_exception'] ?? null,
            'resolved_parameters_schema' => 'schema.json',
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

    private function publicState(array $state): array
    {
        return array_intersect_key($state, array_flip(['contract_revision', 'schema_mode', 'fresh_plan_id', 'format', 'parent_run_id', 'parent_attempt_id', 'phase', 'shard', 'row', 'done', 'last_tick'])) + ['schema_mode' => $state['schema_mode'] ?? 'legacy_local_only', 'contract_revision' => $state['contract_revision'] ?? 1, 'next_action' => $state['done'] ? 'download' : 'tick', 'summary' => $this->summary($state), 'dataset_counts' => array_map(fn ($dataset) => $dataset['rows'], $state['datasets']), 'provider_calls' => false, 'business_writes' => false];
    }

    private function context(int $owner, string $run, string $attempt): array
    {
        $parent = $this->parents->supplementParent($owner, $run, $attempt);
        $folder = $this->folder($parent, $run, $attempt, $this->fresh($owner, $run, $attempt));
        if (! is_file($folder.'/checkpoint.json')) {
            throw new MigrationExportException('supplement_not_started', 404);
        }
        $this->assertParent($parent, $this->load($folder.'/checkpoint.json'));

        return [$parent, $folder];
    }

    private function folder(string $parent, string $run, string $attempt, ?array $fresh = null): string
    {
        return dirname($parent).'/.allegro-parameters-supplements/'.$run.'/'.$attempt.($fresh ? '/fresh-'.$fresh['plan_id'] : '');
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
