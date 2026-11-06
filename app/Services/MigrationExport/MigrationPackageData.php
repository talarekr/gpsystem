<?php

namespace App\Services\MigrationExport;

/** Relational projection of one snapshot. No database/network/file writes. */
final class MigrationPackageData
{
    public static function select(array $snapshot, string $kind, array $products, array $categoryIds): array
    {
        $datasets = $snapshot['datasets'] ?? [];
        if ($kind === 'full') {
            return $datasets;
        }
        $sourcePartIds = array_fill_keys(array_map('strval', array_column($snapshot['products'], 'id')), true);
        $partIds = array_fill_keys(array_map('strval', array_column($products, 'id')), true);
        $carIds = array_fill_keys(array_map('strval', array_filter(array_column($products, 'car_id'))), true);
        $categories = array_fill_keys(array_map('strval', $categoryIds), true);
        $selected = [];
        foreach ($datasets as $table => $rows) {
            foreach ($rows as $i => $row) {
                if (self::referencesPart($row, $partIds)
                    || ($table === 'car_images' && isset($carIds[(string) ($row['car_id'] ?? '')]))
                    || ($table === 'category_translations' && isset($categories[(string) ($row['category_id'] ?? '')]))
                    || $table === 'ovoko_car_dictionary_entries') {
                    $selected[$table][$i] = true;
                }
            }
        }
        // Select related orders only; never add unrelated customers as filler examples.
        $orderIds = [];
        foreach ($datasets['order_items'] ?? [] as $i => $row) {
            if (isset($selected['order_items'][$i])) {
                $orderIds[(string) ($row['order_id'] ?? '')] = true;
            }
        }
        foreach ($datasets['orders'] ?? [] as $i => $row) {
            if (isset($orderIds[(string) $row['id']])) {
                $selected['orders'][$i] = true;
            }
        }
        $relations = $snapshot['relations'] ?? [];
        // Parents + dependent children. Never fan out from a customer to all their unrelated orders.
        do {
            $before = array_sum(array_map('count', $selected));
            $ids = [];
            foreach ($datasets as $table => $rows) {
                foreach ($rows as $i => $row) {
                    if (isset($selected[$table][$i]) && isset($row['id'])) {
                        $ids[$table][(string) $row['id']] = true;
                    }
                }
            }
            foreach ($datasets as $table => $rows) {
                foreach ($relations[$table] ?? [] as $column => $target) {
                    if (is_array($target)) {
                        $target = $target['table'] ?? $target['references_table'] ?? null;
                    }
                    if (! is_string($target)) {
                        continue;
                    }
                    $wanted = [];
                    foreach ($rows as $i => $row) {
                        $foreign = (string) ($row[$column] ?? '');
                        if ($foreign === '') {
                            continue;
                        }
                        if (isset($selected[$table][$i])) {
                            $wanted[$foreign] = true;
                        }
                        if (! in_array($target, ['customers', 'marketplace_account_identities'], true) && isset($ids[$target][$foreign])) {
                            $selected[$table][$i] = true;
                        }
                    }
                    foreach ($datasets[$target] ?? [] as $i => $row) {
                        if (isset($wanted[(string) ($row['id'] ?? '')])) {
                            $selected[$target][$i] = true;
                        }
                    }
                }
            }
        } while ($before !== array_sum(array_map('count', $selected)));
        $result = [];
        foreach ($datasets as $table => $rows) {
            $result[$table] = [];
            foreach ($rows as $i => $row) {
                if (! isset($selected[$table][$i])) {
                    continue;
                }
                if ($table === 'order_items' && ! empty($row['part_id'])) {
                    $row['sample_part_reference'] = isset($partIds[(string) $row['part_id']]) ? 'in_sample' : (isset($sourcePartIds[(string) $row['part_id']]) ? 'outside_sample' : 'missing_source');
                }
                $result[$table][] = $row;
            }
        }

        return $result;
    }

    private static function referencesPart(array $row, array $ids): bool
    {
        foreach ($row as $key => $value) {
            if (in_array($key, ['part_id', 'local_part_id'], true) && is_scalar($value) && isset($ids[(string) $value])) {
                return true;
            }
            if ($key === 'part_snapshot' && is_array($value) && isset($ids[(string) ($value['id'] ?? '')])) {
                return true;
            }
            if (is_array($value) && self::referencesPart($value, $ids)) {
                return true;
            }
        }

        return false;
    }

    public static function dictionary(array $snapshot, array $datasets): array
    {
        $fields = [];
        foreach ($datasets as $table => $rows) {
            $columns = array_values(array_unique(array_merge(...array_map('array_keys', $rows ?: [[]]))));
            $fields[$table] = ['columns' => $columns, 'source_schema' => $snapshot['schema_inventory'][$table] ?? null, 'relations' => $snapshot['relations'][$table] ?? []];
        }

        return ['schema_version' => 'gpswiss-wystawczesc-2', 'csv' => ['encoding' => 'UTF-8 BOM', 'delimiter' => ';', 'null' => '\N', 'literal_backslash' => 'leading backslash doubled', 'nested_values' => 'JSON', 'identifiers' => 'text; import spreadsheet columns explicitly as text', 'formula_prefix' => 'apostrophe for =,+,-,@; JSON preserves original text'],
            'dates' => ['representation' => 'source value preserved; not reinterpreted', 'application_timezone' => config('app.timezone'), 'snapshot_timezone' => 'explicit ISO-8601 offset'], 'datasets' => $fields];
    }
}
