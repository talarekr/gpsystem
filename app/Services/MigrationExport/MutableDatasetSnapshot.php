<?php

namespace App\Services\MigrationExport;

use App\Services\Marketplace\ShippingRateAudit\ReadOnlyDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Whitelisted mutable extension of the v3 writer; caller always holds the run/attempt lease. */
trait MutableDatasetSnapshot
{
    public static function mutableDatasetAllowed(?string $name): bool
    {
        return in_array($name, ['marketplace_listings', 'parts'], true);
    }

    private function mutable(array $definition): bool
    {
        return self::mutableDatasetAllowed($definition['name'] ?? null)
            && ($definition['consistency_mode'] ?? null) === 'bounded_current_rows_reconciled_observations_v1';
    }

    private function databaseTime(): string
    {
        return (string) DB::selectOne(DB::connection()->getDriverName() === 'mysql'
            ? 'SELECT CURRENT_TIMESTAMP(6) AS cutoff_at' : 'SELECT CURRENT_TIMESTAMP AS cutoff_at')->cutoff_at;
    }

    private function initializeMutable(array &$definition, string $cutoff, int $generation = 1, ?string $boundary = null): void
    {
        $definition += ['dataset_generation' => $generation, 'dataset_boundary_id' => $boundary ?? (string) Str::uuid(),
            'dataset_cutoff_database_time' => $cutoff, 'boundary_refreshed_at' => now()->toIso8601String(),
            'previous_boundary_id' => null, 'recovery_reason' => null];
        $definition['consistency_mode'] = 'bounded_current_rows_reconciled_observations_v1';
        $definition['mutable_contract'] = [
            'membership' => 'max_PK_frozen_no_new_ids_above_max; first_read_membership_with_created_at_advisory_filter',
            'values' => 'current_sanitized_values_when_observed; not_historical_as_of_cutoff',
            'verification' => 'one_full_pass_then_only_changed_shards; max_3_rounds; row_fingerprints_and_deletion_tombstones',
            'guarantee' => 'each_final_row_observed_unchanged_twice; no_global_atomic_or_completion_time_guarantee; late_changes_to_stable_rows_not_revisited',
        ];
        $definition['boundary_expected_total'] = $definition['total'];
        $definition['mutable_round'] = 1;
        $definition['mutable_position'] = 0;
        $definition['mutable_active'] = null;
        $definition['mutable_pending'] = [];
        $definition['mutable_change_observations'] = 0;
        $definition['mutable_deleted_observations'] = 0;
    }

    /** Explicit read-only diagnostic, never part of compact polling. Old failures have no before-image. */
    public function diagnoseDatasetConflict(string $directory, string $attempt): array
    {
        $state = $this->read($this->folder($directory, $attempt).'/checkpoint.json');
        $name = $state['dataset_names'][$state['dataset_index']] ?? null;
        $definition = $state['datasets'][$name] ?? [];
        if (($state['attempt_id'] ?? null) !== $attempt || $state['stage'] !== 'failed'
            || ! self::mutableDatasetAllowed($name) || $state['last_error'] !== 'snapshot_row_updated_after_cutoff') {
            throw new MigrationExportException('dataset_conflict_diagnostic_not_available', 409);
        }
        if (($state['source_conflict']['dataset'] ?? null) === $name) {
            return ['evidence' => 'recorded_at_failure', 'conflict' => $state['source_conflict']];
        }

        return ReadOnlyDatabase::run(function () use ($state, $definition): array {
            $query = $this->bounded($this->source->browserQuery($definition, $state['cutoff_database_time']), $definition);
            $cursor = ($state['failure_stage'] ?? 'export') === 'verify' ? $definition['verify_cursor'] : $definition['cursor'];
            if ($cursor) {
                $this->tuple($query, $definition['key_columns'], $cursor, '>');
            }
            $columns = array_values(array_intersect(['id', 'part_id', 'marketplace', 'marketplace_account_id', 'updated_at', 'created_at'], $definition['exported_columns']));
            // Examine exactly the next page, not an arbitrary later mutated row.
            $rows = $query->orderBy('id')->limit(5)->get($columns);
            $row = $rows->first(fn ($r) => ($r->updated_at ?? '') > $state['cutoff_database_time']);

            return ['evidence' => 'current_read_of_legacy_failure_page_not_historical_proof', 'record' => $row ? (array) $row : null,
                'cutoff_database_time' => $state['cutoff_database_time'], 'dataset_boundary_frozen_at' => $definition['boundary_frozen_at'] ?? null,
                'cursor' => $cursor, 'changed_business_fields' => null, 'mutation_origin' => 'requires_correlated_sync_or_order_logs',
                'historical_version_available' => false];
        });
    }

    public function requestDatasetRecovery(string $directory, string $attempt): array
    {
        $file = $this->folder($directory, $attempt).'/checkpoint.json';
        $state = $this->read($file);
        $name = $state['dataset_names'][$state['dataset_index']] ?? null;
        $definition = $state['datasets'][$name] ?? [];
        if (($state['attempt_id'] ?? null) !== $attempt) {
            throw new MigrationExportException('stale_snapshot_attempt', 409);
        }
        $history = $state['dataset_boundary_history'] ?? [];
        $lastRecovery = $history === [] ? [] : $history[array_key_last($history)];
        if (($state['stage'] ?? null) === 'recovery_preflight' && self::mutableDatasetAllowed($name)) {
            return $this->index($directory, $attempt);
        }
        if (in_array($state['stage'], ['export', 'verify'], true) && $state['last_error'] === null
            && ($lastRecovery['recovery_reason'] ?? null) === 'snapshot_row_updated_after_cutoff'
            && ($state['datasets'][$lastRecovery['dataset'] ?? 'marketplace_listings']['dataset_boundary_id'] ?? null) === ($lastRecovery['dataset_boundary_id'] ?? false)) {
            return $this->index($directory, $attempt);
        }
        if ($state['stage'] !== 'failed' || ! self::mutableDatasetAllowed($name)
            || $state['last_error'] !== 'snapshot_row_updated_after_cutoff' || $this->mutable($definition)
            || ($definition['key_columns'] ?? []) !== ['id'] || ($definition['source_table'] ?? null) !== $name) {
            throw new MigrationExportException('dataset_recovery_not_allowed', 409);
        }
        $current = ReadOnlyDatabase::run(fn () => $this->source->browserDescribe($this->source->browserDefinitions()[$name]));
        if ($current['schema_sha256'] !== $definition['schema_sha256']) {
            throw new MigrationExportException('snapshot_schema_changed', 409);
        }
        $returnStage = $state['failure_stage'] ?? ($definition['processed'] < $definition['total'] ? 'export' : 'verify');
        if ($name === 'parts' && ($definition['processed'] !== $definition['total'] || $returnStage !== 'verify')) {
            throw new MigrationExportException('parts_reconciliation_requires_complete_export', 409);
        }
        $generation = (int) ($definition['dataset_generation'] ?? 0);
        $state['dataset_recovery'] = ['dataset' => $name, 'previous_generation' => $generation, 'new_generation' => $generation + 1,
            'previous_boundary_id' => $definition['dataset_boundary_id'] ?? 'legacy', 'dataset_boundary_id' => (string) Str::uuid(),
            'recovery_reason' => 'snapshot_row_updated_after_cutoff', 'preflight_shard' => 0,
            'preflight_chain' => hash('sha256', $name), 'preflight_rows' => 0, 'preflight_total_shards' => count($definition['shards']),
            'return_stage' => $returnStage, 'strategy' => $name === 'parts' ? 'adopt_complete_shards_then_reconcile' : 'reexport_dataset',
            'started_at' => now()->toIso8601String()];
        if (! in_array($state['dataset_recovery']['return_stage'], ['export', 'verify'], true)) {
            throw new MigrationExportException('dataset_recovery_not_allowed', 409);
        }
        $state['stage'] = 'recovery_preflight';
        $state['last_error'] = null;
        $this->write($file, $state);
        app(CompactSnapshotProgress::class)->publish($directory, $attempt, $state);

        return $this->index($directory, $attempt);
    }

    /** Hash old data in bounded steps before adoption or boundary refresh; corruption can never be reset away. */
    private function recoverDatasetBoundary(string $folder, array &$state): void
    {
        $work = &$state['dataset_recovery'];
        $name = $work['dataset'];
        $definition = &$state['datasets'][$name];
        $current = $this->source->browserDescribe($this->source->browserDefinitions()[$name]);
        if ($current['schema_sha256'] !== $definition['schema_sha256']) {
            throw new RuntimeException('snapshot_schema_changed');
        }
        $deadline = microtime(true) + 1;
        for ($n = 0; $n < 5 && isset($definition['shards'][$work['preflight_shard']]) && microtime(true) < $deadline; $n++) {
            $this->verifyStoredShard(dirname(dirname($folder)), $definition['shards'][$work['preflight_shard']]);
            $shard = $definition['shards'][$work['preflight_shard']];
            $work['preflight_chain'] = hash('sha256', $work['preflight_chain'].$shard['sha256']);
            $work['preflight_rows'] += $shard['rows'];
            $work['preflight_shard']++;
        }
        if ($work['preflight_shard'] < count($definition['shards'])) {
            return;
        }
        if (! hash_equals($definition['checksum_partial'], $work['preflight_chain']) || $work['preflight_rows'] !== $definition['processed']) {
            throw new RuntimeException('snapshot_shard_checksum_mismatch');
        }
        $archive = 'dataset-history/'.$name.'/'.$work['previous_boundary_id'].'/definition.json';
        $bytes = json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->immutable($folder.'/'.$archive, $bytes);
        $history = ['dataset' => $name, 'strategy' => $work['strategy'] ?? 'reexport_dataset', 'previous_generation' => $work['previous_generation'], 'dataset_generation' => $work['new_generation'],
            'previous_boundary_id' => $work['previous_boundary_id'], 'dataset_boundary_id' => $work['dataset_boundary_id'],
            'recovery_reason' => $work['recovery_reason'], 'old_definition_sha256' => hash('sha256', $bytes),
            'old_definition_path' => $archive, 'previous_processed' => $definition['processed'], 'previous_cursor' => $definition['cursor'],
            'previous_cutoff_database_time' => $definition['dataset_cutoff_database_time'] ?? $state['cutoff_database_time'],
            'max_key_retained' => $definition['max_key'], 'boundary_refreshed_at' => now()->toIso8601String()];
        $adopt = ($work['strategy'] ?? null) === 'adopt_complete_shards_then_reconcile';
        $definition['verified'] = $definition['verify_shard'] = 0;
        $definition['verify_cursor'] = null;
        $definition['verification_checksum'] = hash('sha256', $name);
        if (! $adopt) {
            $definition['processed'] = 0;
            $definition['cursor'] = null;
            $definition['shards'] = [];
            $definition['checksum_partial'] = hash('sha256', $name);
            unset($definition['status_counts'], $definition['csv_columns']);
        }
        $definition['done'] = false;
        $definition['dataset_generation'] = $work['new_generation'];
        $definition['dataset_boundary_id'] = $work['dataset_boundary_id'];
        $definition['previous_boundary_id'] = $work['previous_boundary_id'];
        $definition['recovery_reason'] = $work['recovery_reason'];
        $definition['boundary_refreshed_at'] = $history['boundary_refreshed_at'];
        $definition['dataset_cutoff_database_time'] = $this->databaseTime();
        // Retain the original key range; do not introduce IDs outside the other frozen datasets.
        if (! $adopt) {
            $definition['total'] = $this->count($this->bounded($this->source->browserQuery($definition, $definition['dataset_cutoff_database_time']), $definition));
        }
        $this->initializeMutable($definition, $definition['dataset_cutoff_database_time']);
        $state['limitations'] = array_map(fn ($value) => $value === 'updates_deletes_and_membership_drift_fail_verification' ? 'strict_datasets_updates_deletes_and_membership_drift_fail_verification' : $value, $state['limitations'] ?? []);
        if ($adopt) {
            $definition['mutable_contract']['membership'] = 'captured_complete_shard_ids; original_max_PK_retained; no_new_members_during_recovery';
            $definition['recovery_strategy'] = 'adopt_complete_shards_then_reconcile';
            $definition['baseline_fingerprint_strategy'] = 'existing_shard_SHA256; row_fingerprints_reconstructed_from_verified_JSON_for_dirty_detection';
        }
        $state['dataset_consistency_overrides'][$name] = $definition['mutable_contract'];
        $state['limitations'] = array_values(array_unique([...($state['limitations'] ?? []), $name.'_consistency_override_current_observations_not_as_of_cutoff']));
        $state['dataset_boundary_history'][] = $history + ['dataset_cutoff_database_time' => $definition['dataset_cutoff_database_time']];
        if (! $adopt) {
            $state['dataset_recovery_return_stage'] = $work['return_stage'];
        }
        unset($state['dataset_recovery'], $state['failure_stage']);
        $state['stage'] = $adopt ? 'verify' : 'export';
    }

    private function verifyStoredShard(string $directory, array $shard): void
    {
        foreach (['path' => 'sha256', 'csv_path' => 'csv_sha256'] as $key => $hash) {
            if (! is_file($directory.'/'.$shard[$key]) || ! hash_equals($shard[$hash], hash_file('sha256', $directory.'/'.$shard[$key]))) {
                throw new RuntimeException('snapshot_shard_checksum_mismatch');
            }
        }
    }

    private function mutableShard(string $folder, array $state, array &$definition, array $rows, ?array $cursor, int $position, string $stage): array
    {
        $json = json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $sha = hash('sha256', $json);
        $path = 'datasets/'.$definition['name'].'/generation-'.$definition['dataset_generation'].'-'.$definition['dataset_boundary_id'].'/'.$stage.'/'.sprintf('%06d', $position).'-'.$sha.'.json';
        $csvPath = substr($path, 0, -5).'.csv';
        $columns = $definition['csv_columns'] ?? $definition['exported_columns'];
        $columns = array_values(array_unique([...$columns, ...array_keys($rows[0] ?? [])]));
        if ($definition['name'] === 'marketplace_listings') {
            $columns = array_values(array_unique([...$columns, 'migration_binding_policy', 'automatic_activation_allowed']));
        }
        $definition['csv_columns'] = $columns;
        $csv = $this->csv($rows, $columns);
        $this->immutable($folder.'/'.$path, $json);
        $this->immutable($folder.'/'.$csvPath, $csv);
        $prefix = 'logical-snapshot/'.$state['attempt_id'].'/';
        $versions = [];
        foreach ($rows as $row) {
            $versions[(string) $row['id']] = ['sha256' => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
                'updated_at' => $row['updated_at'] ?? null];
        }

        return ['path' => $prefix.$path, 'csv_path' => $prefix.$csvPath, 'rows' => count($rows), 'sha256' => $sha, 'csv_sha256' => hash('sha256', $csv),
            'bytes' => strlen($json), 'csv_bytes' => strlen($csv), 'cursor_end' => $cursor, 'row_versions' => $versions,
            'observed_at' => now()->toIso8601String(), 'dataset_generation' => $definition['dataset_generation'], 'dataset_boundary_id' => $definition['dataset_boundary_id']];
    }

    /** ONE shard per tick. Only dirty shards are revisited, never the complete dataset after round 1. */
    private function reconcileMutable(string $folder, array &$state, array &$definition): void
    {
        $position = $definition['mutable_active'] === null
            ? $definition['mutable_position'] : ($definition['mutable_active'][$definition['mutable_position']] ?? null);
        if ($position === null || ! isset($definition['shards'][$position])) {
            if ($definition['mutable_pending'] !== []) {
                if ($definition['mutable_round'] >= 3) {
                    throw new RuntimeException('snapshot_mutable_dataset_unstable');
                }
                $definition['mutable_active'] = $definition['mutable_pending'];
                $definition['mutable_pending'] = [];
                $definition['mutable_position'] = 0;
                $definition['mutable_round']++;

                return;
            }
            $chain = hash('sha256', $definition['name']);
            foreach ($definition['shards'] as $shard) {
                $chain = hash('sha256', $chain.$shard['sha256']);
            }
            $definition['checksum_partial'] = $definition['verification_checksum'] = $chain;
            $definition['verified'] = $definition['processed'];
            $definition['done'] = true;
            $definition['reconciliation_finished_at'] = now()->toIso8601String();
            $state['dataset_index']++;

            return;
        }
        $shard = $definition['shards'][$position];
        $root = dirname(dirname($folder));
        $this->verifyStoredShard($root, $shard);
        $old = $this->read($root.'/'.$shard['path']);
        $ids = $shard['source_ids'] ?? array_column($old, 'id');
        if (count($ids) > 25 || count($ids) !== count(array_unique($ids))) {
            throw new RuntimeException('snapshot_legacy_shard_budget_exceeded');
        }
        // Probe sizes using the same bounded page reader; membership here is the captured IDs.
        $readDefinition = $definition;
        $readDefinition['created_column'] = null;
        $readDefinition['reconcile_ids'] = $ids;
        [$rows] = $this->page($readDefinition, $definition['dataset_cutoff_database_time'], null, count($ids), true);
        $changed = ! hash_equals($shard['sha256'], hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
        $stableBefore = ($shard['observation_stable'] ?? false) ? $shard['rows'] : 0;
        if ($changed) {
            $replacement = $this->mutableShard($folder, $state, $definition, $rows, $shard['cursor_end'], $position, 'reconcile-'.$definition['mutable_round']);
            $replacement['source_ids'] = $ids;
            $replacement['deleted_ids'] = array_values(array_diff($ids, array_column($rows, 'id')));
            $definition['last_mutation'] = ['source_ids' => $ids, 'observed_at' => now()->toIso8601String(),
                'changed_fields' => array_values(array_unique(array_merge(...array_map(function ($row) use ($old): array {
                    $previous = collect($old)->first(fn ($r) => (string) $r['id'] === (string) $row['id']);

                    return $previous ? array_keys(array_filter($row, fn ($v, $k) => ($previous[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH)) : ['row_presence'];
                }, $rows))))];
            if ($replacement['deleted_ids'] !== []) {
                $definition['last_mutation']['changed_fields'][] = 'row_presence';
            }
            $this->adjustMutableCounts($definition, $old, $rows);
            $beforeHashes = [];
            foreach ($old as $row) {
                $beforeHashes[$row['id']] = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            }
            $dirty = array_values(array_filter($ids, fn ($id) => ($beforeHashes[$id] ?? null) !== ($replacement['row_versions'][$id]['sha256'] ?? null)));
            $replacement['dirty_ids'] = array_values(array_unique([...($shard['dirty_ids'] ?? []), ...$dirty]));
            $definition['mutable_dirty_shards'] = ($definition['mutable_dirty_shards'] ?? 0) + (int) empty($shard['dirty_ids']);
            $definition['mutable_dirty_rows'] = ($definition['mutable_dirty_rows'] ?? 0) + count($replacement['dirty_ids']) - count($shard['dirty_ids'] ?? []);
            $definition['mutable_change_observations']++;
            $definition['mutable_deleted_observations'] += count(array_diff(array_column($old, 'id'), array_column($rows, 'id')));
            $definition['processed'] += count($rows) - count($old);
            $definition['total'] = $definition['processed'];
            $definition['shards'][$position] = $replacement;
            $definition['mutable_pending'][] = $position;
        } else {
            $definition['mutable_reused_shards'] = ($definition['mutable_reused_shards'] ?? 0) + (int) empty($shard['dirty_ids']);
            $definition['shards'][$position]['observation_stable'] = true;
            $definition['shards'][$position]['verified_at'] = now()->toIso8601String();
        }
        $definition['verified'] += ($changed ? 0 : count($rows)) - $stableBefore;
        $definition['verify_cursor'] = $shard['cursor_end'];
        $definition['mutable_position']++;
    }

    /** Derived data is compared with canonical parts, never with another live query. */
    private function verifyReconciledPartsRelations(string $folder, array &$state, array &$definition): void
    {
        $parts = $state['datasets']['parts'];
        $position = $definition['verify_shard'];
        $definition['derived_original_checksum'] ??= $definition['checksum_partial'];
        $definition['derived_original_chain'] ??= hash('sha256', $definition['name']);
        if ($position === count($definition['shards'])) {
            if (count($parts['shards']) !== count($definition['shards']) || $definition['processed'] !== $parts['processed']
                || ! hash_equals($definition['derived_original_checksum'], $definition['derived_original_chain'])) {
                throw new RuntimeException('snapshot_shard_checksum_mismatch');
            }
            $chain = hash('sha256', $definition['name']);
            foreach ($definition['shards'] as $shard) {
                $chain = hash('sha256', $chain.$shard['sha256']);
            }
            $definition['checksum_partial'] = $definition['verification_checksum'] = $chain;
            $definition['done'] = true;
            $definition['source_parts_boundary_id'] = $parts['dataset_boundary_id'];
            $state['dataset_consistency_overrides']['part_car_relations'] = ['values' => 'derived_only_from_canonical_reconciled_parts; no_live_query', 'source_boundary_id' => $parts['dataset_boundary_id']];
            $state['dataset_index']++;

            return;
        }
        $shard = $definition['shards'][$position];
        $this->verifyStoredShard(dirname(dirname($folder)), $shard);
        [$rows, $cursor] = $this->frozenRelations($folder, $state, $position);
        $definition['derived_original_chain'] = hash('sha256', $definition['derived_original_chain'].$shard['sha256']);
        $sha = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        if (! hash_equals($shard['sha256'], $sha) || $cursor !== $shard['cursor_end']) {
            $definition['dataset_generation'] = $parts['dataset_generation'];
            $definition['dataset_boundary_id'] = $parts['dataset_boundary_id'];
            $definition['shards'][$position] = $this->mutableShard($folder, $state, $definition, $rows, $cursor, $position, 'derived');
            $definition['processed'] += count($rows) - $shard['rows'];
            $definition['total'] = $definition['processed'];
            $definition['derived_rewritten_shards'] = ($definition['derived_rewritten_shards'] ?? 0) + 1;
        }
        $definition['verified'] += count($rows);
        $definition['verify_cursor'] = $cursor;
        $definition['verify_shard']++;
    }

    private function adjustMutableCounts(array &$definition, array $old, array $rows): void
    {
        // #1517 listing generations have no baseline aggregates; do not invent partial/negative counts.
        if ($definition['name'] !== 'parts') {
            return;
        }
        foreach ([[-1, $old], [1, $rows]] as [$direction, $items]) {
            foreach ($items as $row) {
                if (isset($row['status'])) {
                    $status = (string) $row['status'];
                    $definition['status_counts'][$status] = ($definition['status_counts'][$status] ?? 0) + $direction;
                }
                if ($definition['name'] === 'parts') {
                    foreach (['sold_count' => 'is_sold_by_panel_rule', 'to_list_count' => 'is_to_list_by_panel_rule'] as $counter => $field) {
                        $definition[$counter] = ($definition[$counter] ?? 0) + $direction * (int) ($row[$field] ?? false);
                    }
                }
            }
        }
    }
}
