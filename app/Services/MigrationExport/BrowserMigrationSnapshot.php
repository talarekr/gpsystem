<?php

namespace App\Services\MigrationExport;

use App\Services\Marketplace\ShippingRateAudit\ReadOnlyDatabase;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Immutable, bounded logical export shards. Never claims a transaction shared by HTTP requests. */
final class BrowserMigrationSnapshot
{
    use MutableListingSnapshot;

    private int $attempted = 0;

    private const ROW_BYTES = 262144;

    private const CHUNK_BYTES = 196608;

    public function __construct(private GpswissSnapshot $source) {}

    public function begin(string $directory, string $attempt): array
    {
        $folder = $this->folder($directory, $attempt);
        if (is_file($folder.'/checkpoint.json')) {
            return $this->index($directory, $attempt);
        }
        if (! is_dir($folder)) {
            mkdir($folder, 0700, true);
        }
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('snapshot_driver_not_supported');
        }
        $cutoff = ReadOnlyDatabase::run(fn () => DB::selectOne($driver === 'mysql' ? 'SELECT CURRENT_TIMESTAMP(6) AS cutoff_at' : 'SELECT CURRENT_TIMESTAMP AS cutoff_at')->cutoff_at);
        $state = ['format' => 'gpswiss-wystawczesc-logical-3', 'attempt_id' => $attempt, 'cutoff_database_time' => $cutoff,
            'started_at' => now()->toIso8601String(), 'stage' => 'boundaries', 'dataset_names' => array_keys($this->source->browserDefinitions()),
            'dataset_index' => 0, 'datasets' => [], 'database_consistency' => 'frozen_logical_per_dataset_boundaries_verified_second_pass_not_transactional',
            'limitations' => ['no_historical_row_versions_across_http_requests', 'each_table_max_key_frozen_at_its_boundary_tick', 'created_at_cutoff_is_advisory_null_backdated_and_second_precision_timestamps_possible', 'numeric_max_excludes_keys_above_each_observed_boundary_only', 'no_exact_global_new_record_exclusion_guarantee', 'strict_datasets_updates_deletes_and_membership_drift_fail_verification', 'verification_does_not_establish_global_atomic_point_in_time'],
            'last_success' => null, 'last_error' => null];
        $this->write($folder.'/checkpoint.json', $state);

        return $this->index($directory, $attempt);
    }

    /** Caller holds the run/attempt filesystem lease. One invocation performs ONE bounded unit. */
    public function tick(string $directory, string $attempt): array
    {
        $folder = $this->folder($directory, $attempt);
        $state = $this->read($folder.'/checkpoint.json');
        if (in_array($state['stage'], ['completed', 'failed'], true)) {
            return $this->index($directory, $attempt);
        }
        $started = microtime(true);
        $saved = $state;
        $this->attempted = 0;
        $dataset = $state['dataset_names'][$state['dataset_index']] ?? null;
        $cursorField = $state['stage'] === 'verify' ? 'verify_cursor' : 'cursor';
        $trace = ['started_at' => microtime(true), 'records_limit' => max(1, min(5, (int) config('migration_export.browser_snapshot_rows', 5))), 'action' => 'snapshot_tick', 'reason_code' => 'tick_running', 'current_dataset' => $dataset,
            'cursor_before' => $state['datasets'][$dataset][$cursorField] ?? null, 'cursor_after' => null,
            'records_attempted' => null, 'records_completed' => 0, 'backend_exception_class' => null];
        $this->trace($directory, $trace);
        $state['last_error'] = null;
        try {
            ReadOnlyDatabase::run(function () use (&$state, $folder): void {
                $name = $state['dataset_names'][$state['dataset_index']] ?? null;
                if ($name === null) {
                    $state['dataset_index'] = 0;
                    $state['stage'] = match ($state['stage']) {
                        'boundaries' => 'export', 'export' => 'verify', 'verify' => 'completed'
                    };

                    return;
                }
                if ($state['stage'] === 'recovery_preflight') {
                    $this->recoverDatasetBoundary($folder, $state);
                } elseif ($state['stage'] === 'boundaries') {
                    if ($name === 'part_car_relations') {
                        $parts = $state['datasets']['parts'];
                        $definition = ['name' => $name, 'source_table' => 'frozen:parts', 'required' => true, 'available' => true,
                            'derived_from' => 'parts', 'exported_columns' => ['id', 'part_id', 'car_id'], 'column_definitions' => [],
                            'relations' => ['part_id' => 'parts', 'car_id' => 'cars'], 'schema_sha256' => $parts['schema_sha256'],
                            'key_columns' => ['id'], 'max_key' => $parts['max_key'], 'total' => $parts['total']];
                    } else {
                        $definition = $this->source->browserDescribe($this->source->browserDefinitions()[$name]);
                    }
                    $definition['references'] ??= [];
                    foreach ($definition['references'] as &$reference) {
                        $frozen = $state['datasets'][$reference['table']] ?? null;
                        if ($frozen === null) {
                            throw new RuntimeException('snapshot_reference_boundary_required');
                        }
                        $reference['boundary_bound'] = true;
                        $reference['frozen_key_columns'] = $frozen['key_columns'] ?? [];
                        $reference['frozen_max_key'] = $frozen['max_key'] ?? null;
                    }
                    unset($reference);
                    $definition += ['processed' => 0, 'verified' => 0, 'cursor' => null, 'verify_cursor' => null, 'shards' => [], 'done' => false,
                        'checksum_partial' => hash('sha256', $name), 'verification_checksum' => hash('sha256', $name), 'verify_shard' => 0,
                        'boundary_frozen_at' => now()->toIso8601String()];
                    if (isset($definition['derived_from'])) {
                        // Membership and car_id come from immutable parts, never a second live query.
                    } elseif ($definition['available']) {
                        $query = $this->source->browserQuery($definition, $state['cutoff_database_time']);
                        $maximum = clone $query;
                        foreach ($definition['key_columns'] as $column) {
                            $maximum->orderByDesc($column);
                        }
                        $last = $maximum->first($definition['key_columns']);
                        $definition['max_key'] = $last ? (array) $last : null;
                        $definition['total'] = $definition['max_key'] === null ? 0 : $this->count($this->bounded($query, $definition));
                    } else {
                        $definition['max_key'] = null;
                        $definition['total'] = 0;
                    }
                    if ($name === 'marketplace_listings' && $definition['key_columns'] === ['id']) {
                        $this->initializeMutable($definition, $state['cutoff_database_time']);
                        $state['dataset_consistency_overrides']['marketplace_listings'] = $definition['mutable_contract'];
                    }
                    $state['datasets'][$name] = $definition;
                    $state['dataset_index']++;
                } else {
                    $definition = &$state['datasets'][$name];
                    if (! $definition['available'] || ($definition['total'] === 0 && ! $this->mutable($definition))) {
                        $definition['done'] = true;
                        $state['dataset_index']++;

                        return;
                    }
                    $current = isset($definition['derived_from']) ? $definition : $this->source->browserDescribe($this->source->browserDefinitions()[$name]);
                    if (($current['schema_sha256'] ?? null) !== $definition['schema_sha256']) {
                        throw new RuntimeException('snapshot_schema_changed');
                    }
                    if ($state['stage'] === 'export') {
                        $this->exportChunk($folder, $state, $definition);
                    } else {
                        $this->verifyChunk($folder, $state, $definition);
                    }
                }
                $state['last_success'] = now()->toIso8601String();
            });
        } catch (\Throwable $error) {
            if ($error instanceof SnapshotSourceMutation) {
                $trace['source_conflict'] = $error->diagnostic;
                $state['source_conflict'] = $error->diagnostic;
            }
            $allowed = ['snapshot_mutable_dataset_unstable', 'snapshot_schema_changed', 'snapshot_source_changed', 'snapshot_row_updated_after_cutoff', 'snapshot_row_too_large_for_http', 'snapshot_stable_nonsecret_key_required', 'snapshot_immutable_chunk_conflict', 'snapshot_shard_checksum_mismatch', 'snapshot_reference_boundary_required'];
            $state['last_error'] = in_array($error->getMessage(), $allowed, true) ? $error->getMessage() : 'snapshot_http_tick_failed';
            $trace['backend_exception_class'] = str_contains(get_class($error), '@anonymous') ? 'AnonymousThrowable' : get_class($error);
            if ($state['last_error'] === 'snapshot_http_tick_failed') {
                $state = $saved;
                $state['last_error'] = 'snapshot_http_tick_failed';
                $trace['reason_code'] = $error instanceof QueryException && in_array((int) ($error->errorInfo[1] ?? 0), [3024, 1969], true) ? 'backend_timeout' : 'server_exception';
            } else {
                $state['failure_stage'] = $state['stage'];
                $state['stage'] = 'failed';
                $trace['reason_code'] = $state['last_error'];
            }
        }
        $this->write($folder.'/checkpoint.json', $state);
        try {
            app(CompactSnapshotProgress::class)->publish($directory, $attempt, $state);
        } catch (\Throwable) {
        }
        $trace['reason_code'] = $trace['reason_code'] === 'tick_running' ? 'tick_completed' : $trace['reason_code'];
        $trace['cursor_after'] = $state['datasets'][$dataset][$cursorField] ?? null;
        $trace['records_attempted'] = $this->attempted;
        $trace['records_completed'] = max(0, array_sum(array_column($state['datasets'], 'processed')) + array_sum(array_column($state['datasets'], 'verified')) - array_sum(array_column($saved['datasets'], 'processed')) - array_sum(array_column($saved['datasets'], 'verified')));
        $trace['duration_ms'] = (int) ((microtime(true) - $started) * 1000);
        $this->trace($directory, $trace);
        $index = $this->index($directory, $attempt);
        if ($state['stage'] === 'completed') {
            $this->write($directory.'/snapshot.json', $index);
        }

        return $index;
    }

    private function trace(string $directory, array $trace): void
    {
        try {
            $this->write($directory.'/last-tick.json', $trace);
        } catch (\Throwable) {
            // Diagnostics never replace the data checkpoint or mask its result.
        }
    }

    public function status(string $directory, ?string $attempt = null): array
    {
        $index = $this->index($directory, $attempt);
        $current = $index['current_dataset'];
        $descriptor = $current !== null ? ($index['datasets'][$current] ?? []) : [];

        return array_intersect_key($index, array_flip(['dataset_recovery', 'dataset_boundary_history', 'source_conflict', 'format', 'attempt_id', 'stage', 'done', 'failed', 'current_dataset', 'processed', 'verified', 'total', 'last_success', 'last_error', 'next_action', 'database_consistency']))
            + ['phase' => 'snapshot_'.$index['stage'], 'cursor' => $descriptor['cursor'] ?? null, 'remaining' => max(0, $index['total'] - $index['processed']),
                'datasets' => array_map(fn ($d) => array_intersect_key($d, array_flip(['available', 'processed', 'verified', 'total', 'cursor', 'max_key', 'boundary_frozen_at', 'checksum_partial', 'done'])), $index['datasets'])];
    }

    public function files(string $directory, ?string $attempt = null): array
    {
        $files = [];
        foreach ($this->index($directory, $attempt)['datasets'] as $dataset) {
            foreach ($dataset['shards'] as $shard) {
                $files[] = ['path' => $shard['path'], 'sha256' => $shard['sha256'], 'bytes' => $shard['bytes']];
                $files[] = ['path' => $shard['csv_path'], 'sha256' => $shard['csv_sha256'], 'bytes' => $shard['csv_bytes']];
            }
        }

        return $files;
    }

    public function index(string $directory, ?string $attempt = null): array
    {
        // Root supplies the attempt. Optional discovery only supports an unambiguous single attempt.
        if ($attempt === null) {
            $matches = glob($directory.'/logical-snapshot/*/checkpoint.json') ?: [];
            if (count($matches) !== 1) {
                throw new RuntimeException('snapshot_attempt_required');
            }
            $attempt = basename(dirname($matches[0]));
        }
        $state = $this->read($this->folder($directory, $attempt).'/checkpoint.json');
        $current = $state['dataset_names'][$state['dataset_index']] ?? null;
        $processed = array_sum(array_column($state['datasets'], 'processed'));
        $verified = array_sum(array_column($state['datasets'], 'verified'));

        return $state + ['phase' => 'snapshot_'.$state['stage'], 'cursor' => $current !== null ? ($state['datasets'][$current]['cursor'] ?? null) : null,
            'relations' => array_map(fn ($d) => $d['relations'] ?? [], $state['datasets']),
            'source_counts' => ['products' => $state['datasets']['parts']['total'] ?? 0, 'cars' => $state['datasets']['cars']['total'] ?? 0, 'orders' => $state['datasets']['orders']['total'] ?? 0, 'order_items' => $state['datasets']['order_items']['total'] ?? 0, 'photo_rows' => $state['datasets']['part_images']['total'] ?? 0, 'categories' => $state['datasets']['part_categories']['total'] ?? 0, 'storage_locations' => $state['datasets']['storage_locations']['total'] ?? 0, 'sold_parts' => $state['datasets']['parts']['sold_count'] ?? 0, 'parts_to_list' => $state['datasets']['parts']['to_list_count'] ?? 0, 'statuses' => $state['datasets']['parts']['status_counts'] ?? [], 'dataset_counts' => array_map(fn ($d) => $d['total'], $state['datasets'])], 'done' => $state['stage'] === 'completed', 'failed' => $state['stage'] === 'failed', 'current_dataset' => $current,
            'processed' => $processed, 'verified' => $verified, 'total' => array_sum(array_column($state['datasets'], 'total')),
            'next_action' => match ($state['stage']) {
                'completed' => 'photo_inventory_tick', 'failed' => 'retry_snapshot_required', default => 'snapshot_tick'
            }];
    }

    /** Streams at most one bounded shard in memory; does not combine the entire snapshot. */
    public function rows(string $directory, string $dataset, ?string $attempt = null): \Generator
    {
        $index = $this->index($directory, $attempt);
        foreach ($index['datasets'][$dataset]['shards'] ?? [] as $shard) {
            $file = $directory.'/'.$shard['path'];
            if (! hash_equals($shard['sha256'], hash_file('sha256', $file))) {
                throw new RuntimeException('snapshot_shard_checksum_mismatch');
            }
            foreach ($this->read($file) as $row) {
                yield $row;
            }
        }
    }

    private function exportChunk(string $folder, array &$state, array &$definition): void
    {
        if (! $this->mutable($definition) && $definition['processed'] === $definition['total']) {
            $state['dataset_index']++;

            return;
        }
        [$rows, $cursor] = isset($definition['derived_from'])
            ? $this->frozenRelations($folder, $state, count($definition['shards']))
            : $this->page($definition, $definition['dataset_cutoff_database_time'] ?? $state['cutoff_database_time'], $definition['cursor']);
        if ($this->mutable($definition)) {
            if ($rows === []) {
                if ($cursor !== $definition['cursor']) {
                    $definition['cursor'] = $cursor;

                    return;
                }
                $definition['total'] = $definition['processed'];
                if (($state['dataset_recovery_return_stage'] ?? null) === 'verify') {
                    $state['stage'] = 'verify';
                } else {
                    $state['dataset_index']++;
                }
                unset($state['dataset_recovery_return_stage']);

                return;
            }
            $shard = $this->mutableShard($folder, $state, $definition, $rows, $cursor, count($definition['shards']), 'export');
            $shard['source_ids'] = array_column($rows, 'id');
            $definition['shards'][] = $shard;
            $definition['cursor'] = $cursor;
            $definition['processed'] += count($rows);
            $definition['total'] = max($definition['total'], $definition['processed']);
            $definition['checksum_partial'] = hash('sha256', $definition['checksum_partial'].$shard['sha256']);

            return;
        }
        if ($rows === [] || $definition['total'] < $definition['processed'] + count($rows)) {
            throw new RuntimeException('snapshot_source_changed');
        }
        $number = count($definition['shards']);
        $path = 'datasets/'.$definition['name'].'/'.sprintf('%06d', $number).'.json';
        $csvPath = substr($path, 0, -5).'.csv';
        $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $columns = array_values(array_unique([...$definition['exported_columns'], ...array_keys($rows[0])]));
        $csv = $this->csv($rows, $columns);
        $this->immutable($folder.'/'.$path, $json);
        $this->immutable($folder.'/'.$csvPath, $csv);
        $prefix = 'logical-snapshot/'.$state['attempt_id'].'/';
        $sha = hash('sha256', $json);
        $definition['shards'][] = ['path' => $prefix.$path, 'csv_path' => $prefix.$csvPath, 'rows' => count($rows), 'sha256' => $sha,
            'csv_sha256' => hash('sha256', $csv), 'bytes' => strlen($json), 'csv_bytes' => strlen($csv), 'cursor_end' => $cursor];
        $definition['csv_columns'] = $columns;
        $definition['cursor'] = $cursor;
        $definition['processed'] += count($rows);
        foreach ($rows as $row) {
            if (isset($row['status'])) {
                $key = (string) $row['status'];
                $definition['status_counts'][$key] = ($definition['status_counts'][$key] ?? 0) + 1;
            }
            if ($definition['name'] === 'parts') {
                $definition['sold_count'] = ($definition['sold_count'] ?? 0) + (int) ($row['is_sold_by_panel_rule'] ?? false);
                $definition['to_list_count'] = ($definition['to_list_count'] ?? 0) + (int) ($row['is_to_list_by_panel_rule'] ?? false);
            }
        }
        $definition['checksum_partial'] = hash('sha256', $definition['checksum_partial'].$sha);
    }

    private function verifyChunk(string $folder, array &$state, array &$definition): void
    {
        if ($this->mutable($definition)) {
            $this->reconcileMutable($folder, $state, $definition);

            return;
        }
        $position = $definition['verify_shard'];
        if ($position === count($definition['shards'])) {
            $count = isset($definition['derived_from']) ? $state['datasets']['parts']['processed'] : $this->count($this->bounded($this->source->browserQuery($definition, $state['cutoff_database_time']), $definition));
            if ($count !== $definition['total'] || ! hash_equals($definition['checksum_partial'], $definition['verification_checksum'])) {
                throw new RuntimeException('snapshot_source_changed');
            }
            $definition['done'] = true;
            $state['dataset_index']++;

            return;
        }
        $shard = $definition['shards'][$position];
        $root = dirname(dirname($folder));
        foreach (['path' => 'sha256', 'csv_path' => 'csv_sha256'] as $pathKey => $hashKey) {
            $file = $root.'/'.$shard[$pathKey];
            if (! is_file($file) || ! hash_equals($shard[$hashKey], hash_file('sha256', $file))) {
                throw new RuntimeException('snapshot_shard_checksum_mismatch');
            }
        }
        [$rows, $cursor] = isset($definition['derived_from'])
            ? $this->frozenRelations($folder, $state, $position)
            : $this->page($definition, $state['cutoff_database_time'], $definition['verify_cursor'], $shard['rows']);
        $sha = hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if (! hash_equals($shard['sha256'], $sha) || $cursor !== $shard['cursor_end']) {
            throw new RuntimeException('snapshot_source_changed');
        }
        $definition['verified'] += count($rows);
        $definition['verify_cursor'] = $cursor;
        $definition['verify_shard']++;
        $definition['verification_checksum'] = hash('sha256', $definition['verification_checksum'].$sha);
    }

    private function frozenRelations(string $folder, array $state, int $position): array
    {
        $shard = $state['datasets']['parts']['shards'][$position] ?? null;
        if ($shard === null) {
            throw new RuntimeException('snapshot_source_changed');
        }
        $file = dirname(dirname($folder)).'/'.$shard['path'];
        if (! is_file($file) || ! hash_equals($shard['sha256'], hash_file('sha256', $file))) {
            throw new RuntimeException('snapshot_shard_checksum_mismatch');
        }
        $rows = array_map(fn ($p) => ['id' => $p['id'], 'part_id' => $p['id'], 'car_id' => $p['car_id'] ?? null], $this->read($file));

        return [$rows, $shard['cursor_end']];
    }

    /** Exact lookup in frozen rows. Numeric PK ranges need one shard; other keys expose a bounded continuation. */
    public function lookup(string $directory, string $dataset, string $id, ?string $attempt = null, int $cursor = 0): array
    {
        $definition = $this->index($directory, $attempt)['datasets'][$dataset] ?? null;
        if ($definition === null) {
            return ['found' => false, 'done' => true, 'row' => null, 'next_cursor' => null];
        }
        $shards = $definition['shards'];
        if ($shards === []) {
            return ['found' => false, 'done' => true, 'row' => null, 'next_cursor' => null];
        }
        $numeric = $definition['key_columns'] === ['id'] && preg_match('/int|numeric|decimal/i', $definition['column_definitions']['id']['type'] ?? '') && preg_match('/^[0-9]+$/D', $id);
        if ($numeric) {
            $low = 0;
            $high = count($shards) - 1;
            while ($low < $high) {
                $middle = intdiv($low + $high, 2);
                if ($this->compareNumericId((string) $shards[$middle]['cursor_end']['id'], $id) >= 0) {
                    $high = $middle;
                } else {
                    $low = $middle + 1;
                }
            }
            $cursor = $low;
        }
        $shard = $shards[$cursor] ?? null;
        if ($shard === null) {
            return ['found' => false, 'done' => true, 'row' => null, 'next_cursor' => null];
        }
        $file = $directory.'/'.$shard['path'];
        if (! is_file($file) || ! hash_equals($shard['sha256'], hash_file('sha256', $file))) {
            throw new RuntimeException('snapshot_shard_checksum_mismatch');
        }
        foreach ($this->read($file) as $row) {
            if ((string) ($row['id'] ?? '') === $id) {
                return ['found' => true, 'done' => true, 'row' => $row, 'next_cursor' => null];
            }
        }
        $done = $numeric || $cursor + 1 >= count($shards);

        return ['found' => false, 'done' => $done, 'row' => null, 'next_cursor' => $done ? null : $cursor + 1];
    }

    private function compareNumericId(string $left, string $right): int
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';

        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }

    /** Probe byte sizes before fetching large TEXT/JSON values so a single row cannot exhaust a request. */
    private function page(array $definition, string $cutoff, ?array $cursor, ?int $exact = null, bool $allowMissing = false): array
    {
        $query = $this->bounded($this->source->browserQuery($definition, $cutoff), $definition);
        if (isset($definition['reconcile_ids'])) {
            $query->whereIn('id', $definition['reconcile_ids']);
        }
        if ($cursor !== null) {
            $this->tuple($query, $definition['key_columns'], $cursor, '>');
        }
        foreach ($definition['key_columns'] as $column) {
            $query->orderBy($column);
        }
        $limit = $exact ?? max(1, min(5, (int) config('migration_export.browser_snapshot_rows', 5)));
        $probe = clone $query;
        $grammar = DB::connection()->getQueryGrammar();
        $lengths = array_map(fn ($c) => DB::connection()->getDriverName() === 'mysql' ? 'COALESCE(OCTET_LENGTH('.$grammar->wrap($c).'),0)' : 'COALESCE(LENGTH(CAST('.$grammar->wrap($c).' AS BLOB)),0)', $definition['exported_columns']);
        $keys = $probe->select($definition['key_columns'])->selectRaw('('.implode('+', $lengths).') AS snapshot_row_bytes')->limit($limit)->get();
        $picked = [];
        $budget = 0;
        foreach ($keys as $key) {
            if ($key->snapshot_row_bytes > self::ROW_BYTES) {
                throw new RuntimeException('snapshot_row_too_large_for_http');
            }
            if ($exact === null && $picked !== [] && $budget + $key->snapshot_row_bytes > self::CHUNK_BYTES) {
                break;
            }
            $picked[] = array_intersect_key((array) $key, array_flip($definition['key_columns']));
            $budget += $key->snapshot_row_bytes;
        }
        if ($picked === []) {
            return [[], $cursor];
        }
        $this->tuple($query, $definition['key_columns'], $picked[count($picked) - 1], '<=');
        $this->attempted += count($picked);
        $records = $query->limit(count($picked))->get($definition['exported_columns']);
        if ($records->count() !== count($picked) && ! $allowMissing && ! $this->mutable($definition)) {
            throw new RuntimeException('snapshot_source_changed');
        }
        $rows = [];
        foreach ($records as $record) {
            $raw = (array) $record;
            if (! $this->mutable($definition) && $definition['updated_column'] && ($raw[$definition['updated_column']] ?? '') > $cutoff) {
                throw new SnapshotSourceMutation(['dataset' => $definition['name'], 'record_id' => (string) ($raw['id'] ?? ''),
                    'timestamp_field' => $definition['updated_column'], 'record_updated_at' => $raw[$definition['updated_column']],
                    'cutoff_database_time' => $cutoff, 'dataset_boundary_frozen_at' => $definition['boundary_frozen_at'] ?? null,
                    'changed_business_fields' => null, 'historical_version_available' => false, 'mutation_origin' => 'not_proven_by_timestamp']);
            }
            $rows[] = $this->source->browserNormalize($raw, $definition);
        }

        return [$rows, $picked[count($picked) - 1]];
    }

    private function bounded(Builder $query, array $definition): Builder
    {
        if ($definition['max_key'] === null) {
            return $query->whereRaw('1 = 0');
        }
        $this->tuple($query, $definition['key_columns'], $definition['max_key'], '<=');

        return $query;
    }

    private function tuple(Builder $query, array $columns, array $values, string $operator): void
    {
        $query->where(function ($outer) use ($columns, $values, $operator) {
            foreach ($columns as $index => $column) {
                $outer->orWhere(function ($q) use ($columns, $values, $operator, $index, $column) {
                    for ($i = 0; $i < $index; $i++) {
                        $q->where($columns[$i], '=', $values[$columns[$i]]);
                    }
                    $q->where($column, $operator === '<=' && $index < count($columns) - 1 ? '<' : $operator, $values[$column]);
                });
            }
        });
    }

    private function count(Builder $query): int
    {
        return (int) $query->selectRaw('/*+ MAX_EXECUTION_TIME(1200) */ COUNT(*) AS snapshot_count')->first()->snapshot_count;
    }

    private function folder(string $directory, string $attempt): string
    {
        if (! Str::isUuid($attempt)) {
            throw new RuntimeException('invalid_snapshot_attempt');
        }

        return $directory.'/logical-snapshot/'.$attempt;
    }

    private function csv(array $rows, array $columns): string
    {
        $file = fopen('php://temp', 'w+b');
        fwrite($file, "\xEF\xBB\xBF");
        fputcsv($file, $columns, ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($file, array_map(function ($column) use ($row) {
                $value = $row[$column] ?? null;
                if ($value === null) {
                    return '\\N';
                }
                $value = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);
                if (str_starts_with($value, '\\')) {
                    $value = '\\'.$value;
                }

                return preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $value) ? "'".$value : $value;
            }, $columns), ';', '"', '');
        }
        rewind($file);
        $bytes = stream_get_contents($file);
        fclose($file);

        return $bytes;
    }

    private function immutable(string $file, string $bytes): void
    {
        if (is_file($file)) {
            if (! hash_equals(hash_file('sha256', $file), hash('sha256', $bytes))) {
                throw new RuntimeException('snapshot_immutable_chunk_conflict');
            }

            return;
        }
        if (! is_dir(dirname($file)) && ! mkdir(dirname($file), 0700, true) && ! is_dir(dirname($file))) {
            throw new RuntimeException('snapshot_private_directory_failed');
        }
        $this->atomicBytes($file, $bytes);
    }

    private function write(string $file, array $value): void
    {
        $this->atomicBytes($file, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function atomicBytes(string $file, string $bytes): void
    {
        $pending = $file.'.pending-'.Str::uuid();
        try {
            $written = @file_put_contents($pending, $bytes, LOCK_EX);
            if ($written !== strlen($bytes) || ! @chmod($pending, 0600) || ! hash_equals(hash('sha256', $bytes), (string) @hash_file('sha256', $pending))) {
                throw new RuntimeException('snapshot_private_write_failed');
            }
            if (! @rename($pending, $file)) {
                throw new RuntimeException('snapshot_private_commit_failed');
            }
        } finally {
            if (is_file($pending)) {
                @unlink($pending);
            }
        }
    }

    private function read(string $file): array
    {
        return json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }
}
