<?php

namespace App\Services\MigrationExport;

/** Frozen parent artifacts only. Never queries live Part/Car/Listing tables. */
final class AllegroSupplementSource
{
    public function __construct(public readonly string $directory, public readonly array $index) {}

    public function shard(string $dataset, int $number): ?array
    {
        $descriptor = $this->index['datasets'][$dataset]['shards'][$number] ?? null;
        if ($descriptor === null) {
            return null;
        }
        $path = realpath($this->directory.'/'.$descriptor['path']);
        if (! $path || ! str_starts_with($path, realpath($this->directory).DIRECTORY_SEPARATOR)
            || ! is_file($path) || ! hash_equals($descriptor['sha256'], hash_file('sha256', $path))) {
            throw new MigrationExportException('supplement_parent_artifact_invalid');
        }
        try {
            $rows = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\Throwable) {
            throw new MigrationExportException('supplement_parent_artifact_invalid');
        }
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) !== $descriptor['rows']) {
            throw new MigrationExportException('supplement_parent_artifact_invalid');
        }

        return $rows;
    }

    public function find(string $dataset, ?string $id): ?array
    {
        if ($id === null || $id === '') {
            return null;
        }
        $shards = $this->index['datasets'][$dataset]['shards'] ?? [];
        if ($shards === []) {
            return null;
        }
        // These parent datasets use numeric PKs; never fall back to an unbounded scan or live SQL.
        if (! ctype_digit($id)) {
            throw new MigrationExportException('supplement_parent_key_unsupported');
        }
        $low = 0;
        $high = count($shards) - 1;
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            $end = (string) ($shards[$middle]['cursor_end']['id'] ?? '');
            if (! ctype_digit($end)) {
                throw new MigrationExportException('supplement_parent_key_unsupported');
            }
            if (self::compareId($end, $id) >= 0) {
                $high = $middle;
            } else {
                $low = $middle + 1;
            }
        }
        foreach ($this->shard($dataset, $low) ?? [] as $row) {
            if ((string) ($row['id'] ?? '') === $id) {
                return $row;
            }
        }

        return null;
    }

    private static function compareId(string $left, string $right): int
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';

        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }
}
