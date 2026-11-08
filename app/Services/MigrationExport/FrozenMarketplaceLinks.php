<?php

namespace App\Services\MigrationExport;

/** Pure projection of frozen listing fields. Never infers URLs, markets, accounts or live offer status. */
final class FrozenMarketplaceLinks
{
    public const FILE = 'marketplace_listingi_czesci.csv';

    public const ISSUES_FILE = 'marketplace_listingi_czesci_issues.csv';

    public const ISSUE_COLUMNS = ['reason_code', 'part_id', 'marketplace', 'listing_local_id', 'source_metadata'];

    private const ALIASES = ['part_id', 'marketplace', 'marketplace_account_id', 'sku', 'status', 'sync_status', 'listing_local_id', 'account_code', 'external_listing_id', 'external_offer_id', 'public_url', 'market', 'relationship_mode', 'automatic_activation_allowed'];

    private const SHORTCUTS = ['listing_id', 'external_offer_id', 'url', 'status', 'account', 'marketplace_account_id', 'market', 'sku'];

    public static function family(mixed $marketplace): string
    {
        $name = is_string($marketplace) ? $marketplace : '';
        foreach (['ebay', 'ovoko', 'allegro'] as $family) {
            if ($name === $family || str_starts_with($name, $family.'_')) {
                return $family;
            }
        }

        return 'other';
    }

    public static function shortcutColumns(): array
    {
        $out = [];
        foreach (['ebay', 'ovoko'] as $family) {
            foreach (self::SHORTCUTS as $field) {
                $out[] = $family.'_'.$field;
            }
        }

        return $out;
    }

    public static function columns(array $columns): array
    {
        return array_values(array_unique([...$columns, ...self::ALIASES]));
    }

    public static function listing(array $row): array
    {
        return array_replace($row, ['listing_local_id' => $row['id'], 'public_url' => self::url($row),
            'relationship_mode' => in_array(self::family($row['marketplace'] ?? null), ['ebay', 'ovoko'], true) ? 'active_reference' : 'historical_reference',
            'automatic_activation_allowed' => false]);
    }

    public static function url(array $row): mixed
    {
        foreach (['url', 'public_url'] as $field) {
            if (! self::blank($row[$field] ?? null)) {
                return $row[$field];
            }
        }

        return null;
    }

    public static function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    public static function missingExternalId(array $row): bool
    {
        foreach (['external_listing_id', 'external_offer_id', 'external_inventory_id'] as $field) {
            if (! self::blank($row[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /** Exactly one row in the whole provider family, across accounts/markets/statuses, or all shortcuts NULL. */
    public static function shortcuts(array $rows): array
    {
        $result = array_fill_keys(self::shortcutColumns(), null);
        foreach (['ebay', 'ovoko'] as $family) {
            $matches = array_values(array_filter($rows, fn ($row) => self::family($row['marketplace'] ?? null) === $family));
            if (count($matches) !== 1) {
                continue;
            }
            $row = $matches[0];
            $values = ['listing_id' => $row['external_listing_id'] ?? null, 'external_offer_id' => $row['external_offer_id'] ?? null,
                'url' => self::url($row), 'status' => $row['status'] ?? null,
                'account' => $row['account_code'] ?? $row['marketplace_account_id'] ?? null,
                'marketplace_account_id' => $row['marketplace_account_id'] ?? null, 'market' => $row['market'] ?? null, 'sku' => $row['sku'] ?? null];
            foreach ($values as $field => $value) {
                $result[$family.'_'.$field] = $value;
            }
        }

        return $result;
    }

    /** Minimal per-part lookup; raw payload remains exclusively in the full listing CSV. */
    public static function identity(array $row): array
    {
        return array_intersect_key($row, array_flip(['id', 'marketplace', 'marketplace_account_id', 'account_code', 'external_listing_id', 'external_offer_id', 'url', 'public_url', 'status', 'sku', 'market']));
    }
}
