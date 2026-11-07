<?php

namespace App\Services\MigrationExport;

use RuntimeException;

/** One explicitly authorized legacy run, never a default consistency policy. */
trait OperatorPartsDriftSnapshot
{
    public const DRIFT_RUN = '8de6e27c-ad7c-470b-830b-b9ecee9446ba';

    public const DRIFT_ATTEMPT = '9cb33c92-3f1e-4db0-b08d-052715aa5f4c';

    public const DRIFT_CONFIRM = 'ACCEPT-PARTS-DRIFT-AND-FINALIZE';

    public static function operatorDriftAvailable(array $data): bool
    {
        return ($data['run_id'] ?? null) === self::DRIFT_RUN
            && ($data['snapshot_attempt_id'] ?? $data['attempt_id'] ?? null) === self::DRIFT_ATTEMPT
            && ($data['current_dataset'] ?? null) === 'parts'
            && ($data['exported_records'] ?? $data['processed'] ?? null) === ($data['total'] ?? -1)
            && ($data['total'] ?? 0) > 0
            && ! isset($data['consistency_exception'])
            && in_array($data['state'] ?? null, ['snapshot_running', 'snapshot_failed', 'snapshot_paused'], true)
            && in_array($data['last_error'] ?? null, [null, 'snapshot_row_updated_after_cutoff', 'snapshot_mutable_dataset_unstable'], true)
            && (($data['source_conflict']['dataset'] ?? null) === 'parts'
                || ($data['dataset_recovery']['dataset'] ?? null) === 'parts' || ($data['mutable_dirty_rows'] ?? 0) > 0);
    }

    public static function acceptedFinalization(array $data): bool
    {
        $exception = $data['consistency_exception'] ?? [];

        return ($exception['run_id'] ?? null) === self::DRIFT_RUN && ($exception['attempt_id'] ?? null) === self::DRIFT_ATTEMPT
            && ($exception['accepted_by_operator'] ?? false) === true && ($exception['dataset'] ?? null) === 'parts'
            && ($data['snapshot_stage'] ?? $data['stage'] ?? null) === 'operator_finalize' && ($data['last_error'] ?? null) === null;
    }

    /** Caller holds the owner/run lease and validates source identity before reaching here. */
    public function acceptKnownPartsDrift(string $directory, string $attempt, int $operator): array
    {
        if (basename($directory) !== self::DRIFT_RUN || $attempt !== self::DRIFT_ATTEMPT) {
            throw new MigrationExportException('operator_parts_drift_wrong_run_or_attempt', 409);
        }
        $file = $this->folder($directory, $attempt).'/checkpoint.json';
        $state = $this->read($file);
        if (($state['attempt_id'] ?? null) !== $attempt || ($state['format'] ?? null) !== 'gpswiss-wystawczesc-logical-3') {
            throw new MigrationExportException('stale_snapshot_attempt', 409);
        }
        if (isset($state['consistency_exception'])) {
            if (! self::acceptedFinalization($state) && ($state['stage'] ?? null) !== 'completed') {
                throw new MigrationExportException('operator_finalization_not_resumable', 409);
            }

            return ['phase' => 'snapshot_'.$state['stage'], 'consistency_exception' => $state['consistency_exception']];
        }
        $current = $state['dataset_names'][$state['dataset_index']] ?? null;
        $parts = $state['datasets']['parts'] ?? [];
        $reason = $state['last_error'] ?? null;
        $knownConflict = ($state['source_conflict']['dataset'] ?? null) === 'parts'
            || (($state['dataset_recovery']['dataset'] ?? null) === 'parts' && ($state['dataset_recovery']['recovery_reason'] ?? null) === 'snapshot_row_updated_after_cutoff')
            || ($this->mutable($parts) && ($parts['mutable_dirty_rows'] ?? 0) > 0);
        if ($current !== 'parts' || ! $knownConflict || ! in_array($state['stage'], ['failed', 'recovery_preflight', 'verify'], true)
            || ! in_array($reason, [null, 'snapshot_row_updated_after_cutoff', 'snapshot_mutable_dataset_unstable'], true)
            || ($parts['source_table'] ?? null) !== 'parts' || ($parts['key_columns'] ?? []) !== ['id']) {
            throw new MigrationExportException('operator_parts_drift_not_allowed', 409);
        }
        $recorded = [];
        if (($state['source_conflict']['dataset'] ?? null) === 'parts') {
            $recorded[] = $state['source_conflict']['record_id'] ?? null;
        }
        foreach ($parts['shards'] ?? [] as $shard) {
            $recorded = [...$recorded, ...($shard['dirty_ids'] ?? [])];
        }
        $recorded = array_values(array_unique(array_map('strval', array_filter($recorded, fn ($id) => is_scalar($id) && preg_match('/^[0-9]+$/D', (string) $id)))));
        sort($recorded, SORT_NATURAL);
        if (count($recorded) > 2) {
            throw new MigrationExportException('parts_drift_exceeds_operator_declared_count', 409);
        }
        $verification = [];
        if (array_keys($state['datasets']) !== $state['dataset_names']) {
            throw new MigrationExportException('snapshot_dataset_inventory_mismatch', 409);
        }
        foreach ($state['datasets'] as $name => $definition) {
            if ($definition['processed'] !== $definition['total'] || array_sum(array_column($definition['shards'], 'rows')) !== $definition['processed']) {
                throw new MigrationExportException('operator_finalization_requires_complete_export', 409);
            }
            $verification[$name] = ['verified' => $definition['verified'], 'total' => $definition['total'], 'done' => $definition['done']];
        }
        [$chains, $chainExceptions] = $this->sealArtifactChains($directory, $attempt, $state);
        $state['consistency_exception'] = ['accepted_by_operator' => true, 'operator_id' => $operator, 'dataset' => 'parts',
            'reason' => 'known_post_cutoff_mutation', 'recovery_skipped' => true, 'manual_correction_required' => true,
            'accepted_at' => now()->toIso8601String(), 'run_id' => self::DRIFT_RUN, 'attempt_id' => $attempt,
            'outcome' => 'accepted_known_drift', 'known_count_from_operator' => 2, 'recorded_ids' => $recorded,
            'remaining_operator_declared_without_recorded_id' => 2 - count($recorded),
            'recorded_ids_provenance' => 'checkpoint_source_conflict_and_dirty_ids; not_a_complete_drift_inventory',
            'recovery_at_acceptance' => $state['dataset_recovery'] ?? null,
            'generation_at_acceptance' => $state['dataset_recovery']['new_generation'] ?? $parts['dataset_generation'] ?? 0,
            'boundary_at_acceptance' => $state['dataset_recovery']['dataset_boundary_id'] ?? $parts['dataset_boundary_id'] ?? 'legacy'];
        $state['operator_finalization'] = ['dataset_index' => 0, 'shard' => 0, 'rows' => 0, 'checksum_chain' => null,
            'sealed_chains' => $chains, 'chain_validation_exceptions' => $chainExceptions,
            'artifacts_checked' => 0, 'source_verification_at_acceptance' => $verification,
            'previous_database_consistency' => $state['database_consistency'], 'source_verification_guarantees' => 'not_asserted_for_skipped_checks',
            'previous_stage' => $state['stage'], 'previous_dataset_index' => $state['dataset_index'],
            'live_source_verification_skipped' => true, 'verification_mode' => 'stored_artifact_integrity_then_stored_relations_only'];
        $state['stage'] = 'operator_finalize';
        $state['database_consistency'] = 'frozen_logical_per_dataset_boundaries_operator_accepted_parts_drift_artifact_integrity_and_relations_not_transactional';
        $state['last_error'] = null;
        $state['parts_recovery_outcome'] = 'accepted_known_drift';
        $state['limitations'][] = 'operator_finalization_skips_remaining_live_source_verification; accepted_parts_drift_is_not_a_complete_inventory_of_source_changes';
        unset($state['dataset_recovery']);
        $this->write($file, $state);
        try {
            app(CompactSnapshotProgress::class)->publish($directory, $attempt, $state);
        } catch (\Throwable) {
        }

        return ['phase' => 'snapshot_operator_finalize', 'consistency_exception' => $state['consistency_exception']];
    }

    /** At most five stored shards per request; no live source read and no shard mutation. */
    private function finalizeAcceptedArtifacts(string $folder, array &$state): void
    {
        if (! self::acceptedFinalization($state) || basename(dirname(dirname($folder))) !== self::DRIFT_RUN
            || ($state['attempt_id'] ?? null) !== self::DRIFT_ATTEMPT) {
            throw new RuntimeException('operator_finalization_identity_mismatch');
        }
        $work = &$state['operator_finalization'];
        $name = $state['dataset_names'][$work['dataset_index']] ?? null;
        if ($name === null) {
            $work['artifact_integrity_complete'] = true;
            $work['completed_at'] = now()->toIso8601String();
            $state['stage'] = 'completed';

            return;
        }
        $definition = &$state['datasets'][$name];
        $work['checksum_chain'] ??= hash('sha256', $name);
        $deadline = microtime(true) + 1;
        for ($n = 0; $n < 5 && isset($definition['shards'][$work['shard']]) && microtime(true) < $deadline; $n++) {
            $shard = $definition['shards'][$work['shard']];
            $prefix = 'logical-snapshot/'.$state['attempt_id'].'/datasets/'.$name.'/';
            foreach (['path', 'csv_path'] as $key) {
                if (! str_starts_with($shard[$key] ?? '', $prefix) || str_contains($shard[$key], '..')) {
                    throw new RuntimeException('snapshot_shard_identity_mismatch');
                }
            }
            $this->verifyStoredShard(dirname(dirname($folder)), $shard, true, $definition['key_columns'], $definition['csv_columns'] ?? $definition['exported_columns']);
            $work['checksum_chain'] = hash('sha256', $work['checksum_chain'].$shard['sha256']);
            $work['rows'] += $shard['rows'];
            $work['shard']++;
            $work['artifacts_checked']++;
        }
        if ($work['shard'] < count($definition['shards'])) {
            return;
        }
        $sealed = $work['sealed_chains'][$name];
        if (! hash_equals($sealed['current'], $work['checksum_chain']) || $definition['checksum_partial'] !== $sealed['recorded'] || $work['rows'] !== $definition['processed']) {
            throw new RuntimeException('snapshot_shard_checksum_mismatch');
        }
        $definition['artifact_integrity'] = ['verified' => true, 'rows' => $work['rows'], 'checksum_chain' => $work['checksum_chain']];
        $work['dataset_index']++;
        $work['shard'] = $work['rows'] = 0;
        $work['checksum_chain'] = null;
    }

    /** The mutable writer defers its aggregate chain until the round ends. Preserve, never fake it. */
    private function sealArtifactChains(string $directory, string $attempt, array $state): array
    {
        $chains = $exceptions = [];
        foreach ($state['datasets'] as $name => $definition) {
            $current = $this->descriptorChain($name, $definition['shards']);
            $chains[$name] = ['recorded' => $definition['checksum_partial'], 'current' => $current];
            if (hash_equals($definition['checksum_partial'], $current)) {
                continue;
            }
            if ($name !== 'parts' || ! $this->mutable($definition) || empty($definition['mutable_pending'])) {
                throw new MigrationExportException('snapshot_shard_checksum_mismatch', 409);
            }
            $history = array_values(array_filter($state['dataset_boundary_history'] ?? [], fn ($entry) => ($entry['dataset'] ?? null) === 'parts'
                && ($entry['dataset_boundary_id'] ?? null) === $definition['dataset_boundary_id']));
            $entry = $history === [] ? [] : $history[array_key_last($history)];
            $relative = $entry['old_definition_path'] ?? '';
            if (! str_starts_with($relative, 'dataset-history/parts/') || str_contains($relative, '..')) {
                throw new MigrationExportException('snapshot_shard_checksum_mismatch', 409);
            }
            $file = $this->folder($directory, $attempt).'/'.$relative;
            $real = realpath($file);
            if (! $real || ! str_starts_with($real, $this->folder($directory, $attempt).'/dataset-history/parts/')
                || ! is_file($real) || ! is_readable($real) || filesize($real) > 4 * 1024 * 1024) {
                throw new MigrationExportException('snapshot_shard_checksum_mismatch', 409);
            }
            $bytes = @file_get_contents($real, false, null, 0, 4 * 1024 * 1024 + 1);
            if (! is_string($bytes) || strlen($bytes) > 4 * 1024 * 1024 || ! hash_equals($entry['old_definition_sha256'] ?? '', hash('sha256', $bytes))) {
                throw new MigrationExportException('snapshot_shard_checksum_mismatch', 409);
            }
            try {
                $old = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new MigrationExportException('snapshot_shard_checksum_mismatch', 409);
            }
            if (($old['schema_sha256'] ?? null) !== $definition['schema_sha256'] || count($old['shards'] ?? []) !== count($definition['shards'])
                || ($old['checksum_partial'] ?? null) !== $definition['checksum_partial']
                || ! hash_equals($old['checksum_partial'], $this->descriptorChain($name, $old['shards']))) {
                throw new MigrationExportException('snapshot_shard_checksum_mismatch', 409);
            }
            foreach ($definition['shards'] as $position => $shard) {
                if ($shard['sha256'] === $old['shards'][$position]['sha256']) {
                    continue;
                }
                if (empty($shard['dirty_ids']) || ($shard['dataset_generation'] ?? null) !== $definition['dataset_generation']
                    || ($shard['dataset_boundary_id'] ?? null) !== $definition['dataset_boundary_id']) {
                    throw new MigrationExportException('snapshot_shard_checksum_mismatch', 409);
                }
            }
            $exceptions[$name] = ['reason' => 'mutable_round_chain_not_yet_finalized', 'recorded_chain_preserved' => $definition['checksum_partial'],
                'sealed_current_descriptor_chain' => $current, 'baseline_definition_path' => $relative, 'baseline_sha256' => $entry['old_definition_sha256']];
        }

        return [$chains, $exceptions];
    }

    private function descriptorChain(string $name, array $shards): string
    {
        $chain = hash('sha256', $name);
        foreach ($shards as $shard) {
            $chain = hash('sha256', $chain.$shard['sha256']);
        }

        return $chain;
    }
}
