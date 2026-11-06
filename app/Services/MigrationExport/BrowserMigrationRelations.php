<?php

namespace App\Services\MigrationExport;

use RuntimeException;

/** Bounded referential audit of immutable export shards only. The caller holds the run lease. */
final class BrowserMigrationRelations
{
    private const MAX_PROBES = 20;

    private const MAX_SHARD_BYTES = 4 * 1024 * 1024;

    public function __construct(private BrowserMigrationSnapshot $snapshots) {}

    public function tick(string $directory, string $attempt): array
    {
        $directory = realpath($directory) ?: throw new RuntimeException('relation_directory_missing');
        $index = $this->snapshots->index($directory, $attempt);
        if (! ($index['done'] ?? false) || ($index['attempt_id'] ?? null) !== $attempt) {
            throw new RuntimeException('relation_snapshot_not_completed');
        }
        $checkpoint = $directory.'/relation-checkpoint.json';
        $state = is_file($checkpoint) ? $this->read($checkpoint) : [
            'attempt_id' => $attempt, 'dataset_index' => 0, 'shard_index' => 0, 'row_index' => 0, 'column_index' => 0,
            'dataset_names' => array_keys($index['datasets']), 'total' => array_sum(array_column($index['datasets'], 'total')),
            'processed' => 0, 'probes' => 0, 'issues_count' => 0, 'issue_counts' => [], 'journal_bytes' => 0,
            'checksum_partial' => hash('sha256', ''),
            'journal_hash' => SerializableSha256::initial(),
            'done' => false, 'last_success' => null,
        ];
        $this->assertAttempt($state, $attempt);
        if ($state['done']) {
            return $this->result($state);
        }
        $journal = fopen($directory.'/relation-issues.jsonl', 'c+b');
        if (! is_resource($journal) || ! chmod($directory.'/relation-issues.jsonl', 0600)) {
            throw new RuntimeException('relation_journal_open_failed');
        }
        try {
            if (fstat($journal)['size'] < $state['journal_bytes'] || ! ftruncate($journal, $state['journal_bytes'])
                || fseek($journal, $state['journal_bytes']) !== 0) {
                throw new RuntimeException('relation_journal_truncated');
            }
            $names = array_keys($index['datasets']);
            $name = $names[$state['dataset_index']] ?? null;
            if ($name === null) {
                $state['done'] = true;
            } else {
                $definition = $index['datasets'][$name];
                $shard = $definition['shards'][$state['shard_index']] ?? null;
                if ($shard === null) {
                    $state['dataset_index']++;
                    $state['shard_index'] = $state['row_index'] = $state['column_index'] = 0;
                } else {
                    // Never scan the source table or more than one source shard in a request.
                    $rows = $this->shard($directory, $shard);
                    $relations = $index['relations'][$name] ?? $definition['relations'] ?? [];
                    $columns = array_keys($relations);
                    $probes = 0;
                    $deadline = microtime(true) + 2.0;
                    $cache = [];
                    while (isset($rows[$state['row_index']])) {
                        $row = $rows[$state['row_index']];
                        while (isset($columns[$state['column_index']])) {
                            if ($probes >= self::MAX_PROBES || ($probes > 0 && microtime(true) >= $deadline)) {
                                break 2;
                            }
                            $column = $columns[$state['column_index']];
                            $value = $row[$column] ?? null;
                            if ($value !== null && $value !== '') {
                                $target = $this->target($name, $column, $relations[$column]);
                                $reason = $this->lookup($directory, $index['datasets'], $target, $value, $cache);
                                $probes++;
                                $state['probes']++;
                                if ($reason !== null) {
                                    $issue = ['source_dataset' => $name, 'source_id' => $row['id'] ?? null,
                                        'source_key' => array_intersect_key($row, array_flip($definition['key_columns'] ?? [])),
                                        'column' => $column, 'target_dataset' => $target, 'referenced_id' => $value, 'reason_code' => $reason];
                                    $line = json_encode($issue, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
                                    if (fwrite($journal, $line) !== strlen($line)) {
                                        throw new RuntimeException('relation_journal_write_failed');
                                    }
                                    $state['journal_bytes'] += strlen($line);
                                    $state['checksum_partial'] = hash('sha256', $state['checksum_partial'].$line);
                                    $state['journal_hash'] = SerializableSha256::update($state['journal_hash'], $line);
                                    $state['issues_count']++;
                                    $state['issue_counts'][$reason] = ($state['issue_counts'][$reason] ?? 0) + 1;
                                }
                            }
                            $state['column_index']++;
                        }
                        $state['processed']++;
                        $state['row_index']++;
                        $state['column_index'] = 0;
                    }
                    if ($state['row_index'] >= count($rows)) {
                        $state['shard_index']++;
                        $state['row_index'] = $state['column_index'] = 0;
                    }
                }
            }
            if (! fflush($journal)) {
                throw new RuntimeException('relation_journal_write_failed');
            }
            $state['last_success'] = now()->toIso8601String();
            $this->write($checkpoint, $state);
        } finally {
            fclose($journal);
        }

        return $this->result($state);
    }

    public function status(string $directory, ?string $attempt = null): array
    {
        if (! is_file($directory.'/relation-checkpoint.json')) {
            return ['done' => false, 'processed' => 0, 'probes' => 0, 'issues_count' => 0, 'issue_counts' => [],
                'last_success' => null, 'current_dataset_index' => 0, 'shard_index' => 0, 'row_index' => 0,
                'total' => 0, 'remaining' => 0, 'current_dataset' => null, 'checksum_partial' => hash('sha256', ''),
                'next_action' => 'relation_tick', 'issues_file' => 'relation-issues.jsonl'];
        }
        $state = $this->read($directory.'/relation-checkpoint.json');
        if ($attempt !== null) {
            $this->assertAttempt($state, $attempt);
        }

        return $this->result($state);
    }

    private function lookup(string $directory, array $datasets, string $target, mixed $value, array &$cache): ?string
    {
        $definition = $datasets[$target] ?? null;
        if (! $definition || ! ($definition['available'] ?? false)) {
            return 'unknown_target_dataset';
        }
        // All current referenced entities use integer id. Composite/non-integer keys need an explicit mapping.
        $type = strtolower((string) ($definition['column_definitions']['id']['type'] ?? ''));
        if (($definition['key_columns'] ?? []) !== ['id'] || ! preg_match('/(?:^|\W)(?:tinyint|smallint|mediumint|bigint|integer|int)(?:\W|$)/', $type)
            || $this->id($value) === null) {
            return 'unverifiable_target_key';
        }
        $shards = $definition['shards'] ?? [];
        $low = 0;
        $high = count($shards) - 1;
        $candidate = null;
        // cursor_end is the last unique key in each frozen ordered shard: logarithmic metadata lookup.
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            $end = $shards[$middle]['cursor_end']['id'] ?? null;
            if ($this->id($end) === null) {
                return 'unverifiable_target_key';
            }
            if ($this->compare($value, $end) <= 0) {
                $candidate = $middle;
                $high = $middle - 1;
            } else {
                $low = $middle + 1;
            }
        }
        if ($candidate === null) {
            return 'missing_source_reference';
        }
        $key = $target.':'.$candidate;
        if (! isset($cache[$key])) {
            // Keep at most two target shards in memory; a tick can perform at most twenty probes.
            if (count($cache) >= 2) {
                array_shift($cache);
            }
            $cache[$key] = $this->shard($directory, $shards[$candidate]);
        }
        foreach ($cache[$key] as $row) {
            if ($this->id($row['id'] ?? null) !== null && $this->compare($value, $row['id']) === 0) {
                return null;
            }
        }

        return 'missing_source_reference';
    }

    private function target(string $source, string $column, string $target): string
    {
        if ($source === 'customer_returns' && $column === 'order_id') {
            return 'shop_events';
        }

        return match ($target) {
            'users' => 'customers', 'marketplace_accounts' => 'marketplace_account_identities', default => $target,
        };
    }

    private function shard(string $directory, array $descriptor): array
    {
        $file = realpath($directory.'/'.($descriptor['path'] ?? ''));
        if (! $file || ! str_starts_with($file, $directory.DIRECTORY_SEPARATOR) || ! is_file($file)
            || filesize($file) > self::MAX_SHARD_BYTES || ! hash_equals($descriptor['sha256'] ?? '', hash_file('sha256', $file))) {
            throw new RuntimeException('relation_shard_missing_or_changed');
        }
        $rows = $this->read($file);
        if (! array_is_list($rows) || count($rows) > 25) {
            throw new RuntimeException('relation_shard_not_bounded');
        }

        return $rows;
    }

    private function id(mixed $value): ?string
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }
        $value = (string) $value;
        if (! preg_match('/^-?\d+$/D', $value)) {
            return null;
        }
        $negative = str_starts_with($value, '-');
        $digits = ltrim($negative ? substr($value, 1) : $value, '0');

        return $digits === '' ? '0' : ($negative ? '-' : '').$digits;
    }

    private function compare(mixed $left, mixed $right): int
    {
        $left = $this->id($left);
        $right = $this->id($right);
        $negativeLeft = str_starts_with($left, '-');
        $negativeRight = str_starts_with($right, '-');
        if ($negativeLeft !== $negativeRight) {
            return $negativeLeft ? -1 : 1;
        }
        $left = ltrim($left, '-');
        $right = ltrim($right, '-');
        $result = (strlen($left) <=> strlen($right)) ?: strcmp($left, $right);

        return $negativeLeft ? -$result : $result;
    }

    private function assertAttempt(array $state, string $attempt): void
    {
        if (($state['attempt_id'] ?? null) !== $attempt) {
            throw new RuntimeException('relation_stale_snapshot_attempt');
        }
    }

    private function result(array $state): array
    {
        return array_intersect_key($state, array_flip(['done', 'processed', 'total', 'probes', 'issues_count', 'issue_counts', 'last_success', 'shard_index', 'row_index', 'checksum_partial']))
            + ['current_dataset_index' => $state['dataset_index'], 'current_dataset' => $state['dataset_names'][$state['dataset_index']] ?? null,
                'remaining' => max(0, $state['total'] - $state['processed']), 'cursor' => ['shard' => $state['shard_index'], 'row' => $state['row_index'], 'column' => $state['column_index']],
                'next_action' => $state['done'] ? 'photo_inventory_tick' : 'relation_tick', 'issues_file' => 'relation-issues.jsonl',
                'journal_sha256' => SerializableSha256::digest($state['journal_hash'])];
    }

    private function read(string $path): array
    {
        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function write(string $path, array $data): void
    {
        $bytes = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (@file_put_contents($path.'.tmp', $bytes) !== strlen($bytes) || ! chmod($path.'.tmp', 0600) || ! rename($path.'.tmp', $path)) {
            throw new RuntimeException('relation_checkpoint_write_failed');
        }
    }
}
