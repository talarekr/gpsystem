<?php

namespace App\Services\MigrationExport;

use App\Services\Marketplace\ShippingRateAudit\ReadOnlyDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** One private business snapshot; no models/observers, integration credentials or provider calls. */
final class GpswissSnapshot
{
    /** Fixed business scope; schema drift is inventoried, never used to select auth/integration tables. */
    private const OPTIONAL_TABLES = [
        'allegro_parameter_selections', 'marketplace_category_mappings', 'part_translations', 'category_translations',
        'car_images', 'ovoko_car_dictionary_entries', 'orders', 'order_items', 'shipments', 'customer_returns',
        'shop_events', 'local_sales', 'stripe_fr_attempts', 'stripe_fr_events',
        'order_status_history', 'order_status_histories', 'order_status_changes', 'order_histories',
        'payments', 'order_payments', 'refunds', 'order_refunds', 'returns', 'order_returns', 'return_items',
        'shipment_packages', 'order_notes', 'order_discounts', 'order_taxes', 'order_addresses',
    ];

    private const CUSTOMER_FIELDS = ['id', 'name', 'first_name', 'last_name', 'email', 'phone', 'tax_id', 'company_name', 'address_line1', 'address_line2', 'postal_code', 'city', 'country', 'created_at', 'updated_at'];

    public function capture(?callable $progress = null): array
    {
        $db = DB::connection();
        if ($db->transactionLevel() !== 0 && ! app()->environment('testing')) {
            throw new RuntimeException('existing_transaction_not_supported');
        }
        if ($db->getDriverName() === 'mysql') {
            $db->getPdo()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        } elseif ($db->getDriverName() !== 'sqlite') {
            throw new RuntimeException('snapshot_driver_not_supported');
        }

        return ReadOnlyDatabase::run(fn () => $db->transaction(function () use ($db, $progress): array {
            $required = ['parts', 'part_images', 'part_categories', 'cars', 'storage_locations', 'marketplace_listings'];
            $available = array_values(array_filter(self::OPTIONAL_TABLES, fn ($t) => Schema::hasTable($t)));
            $tables = [...$required, ...$available];
            $hasUsers = Schema::hasTable('users');
            if ($hasUsers) {
                $tables[] = 'users';
            }
            $hasAccounts = Schema::hasTable('marketplace_accounts');
            if ($hasAccounts) {
                $tables[] = 'marketplace_accounts';
            }
            if ($db->getDriverName() === 'mysql') {
                $engines = $db->table('information_schema.tables')->where('table_schema', $db->getDatabaseName())->whereIn('table_name', $tables)->get(['table_name as source_table', 'engine as source_engine']);
                if ($engines->count() !== count($tables) || $engines->contains(fn ($t) => strtoupper((string) $t->source_engine) !== 'INNODB')) {
                    throw new RuntimeException('snapshot_requires_innodb');
                }
            }
            $started = now()->toIso8601String();
            $data = [];
            $inventory = [];
            foreach ([...$required, ...$available] as $table) {
                if ($progress) {
                    $progress(['stage' => 'database_snapshot', 'table' => $table, 'tables_processed' => count($data)]);
                }
                [$rows, $columns] = $this->readBusinessTable($table);
                $data[$table] = $rows;
                $inventory[$table] = $columns;
            }
            // Customers only when referenced by exported business records. No passwords, roles or sessions.
            if ($hasUsers) {
                $ids = array_values(array_unique(array_filter([
                    ...array_column($data['orders'] ?? [], 'customer_id'),
                    ...array_column($data['customer_returns'] ?? [], 'user_id'),
                ], fn ($id) => $id !== null && $id !== '')));
                $columns = array_values(array_intersect(self::CUSTOMER_FIELDS, Schema::getColumnListing('users')));
                $data['customers'] = $ids === [] ? [] : DB::table('users')->whereIn('id', $ids)->orderBy('id')->get($columns)->map(fn ($r) => MigrationDataPolicy::clean((array) $r))->all();
                $inventory['customers'] = ['source_table' => 'users', 'exported_columns' => $columns, 'omitted_columns' => array_map(fn ($c) => ['name' => $c, 'reason' => 'authentication_or_non_order_profile_field'], array_values(array_diff(Schema::getColumnListing('users'), $columns)))];
            }
            // Only the non-secret identities required to disambiguate retained offer/order mappings.
            // Never SELECT * from integration accounts or copy their credential/settings blobs.
            $accountFields = ['id', 'marketplace', 'code', 'name', 'status', 'api_mode', 'api_base_url'];
            $accountIds = array_values(array_unique(array_filter([
                ...array_column($data['marketplace_listings'], 'marketplace_account_id'),
                ...array_column($data['orders'] ?? [], 'marketplace_account_id'),
            ], fn ($id) => $id !== null && $id !== '')));
            $accountColumns = $hasAccounts ? array_values(array_intersect($accountFields, Schema::getColumnListing('marketplace_accounts'))) : [];
            $data['marketplace_account_identities'] = ! $hasAccounts || $accountIds === [] ? [] : DB::table('marketplace_accounts')->whereIn('id', $accountIds)->orderBy('id')->get($accountColumns)->map(function ($r) {
                return MigrationDataPolicy::clean((array) $r) + ['automatic_activation_allowed' => false, 'explicit_adoption_required' => true];
            })->all();
            $inventory['marketplace_account_identities'] = ['source_table' => 'marketplace_accounts', 'exported_columns' => $accountColumns,
                'omitted_columns' => $hasAccounts ? array_map(fn ($c) => ['name' => $c, 'reason' => 'integration_credentials_or_non_identity_configuration'], array_values(array_diff(Schema::getColumnListing('marketplace_accounts'), $accountColumns))) : []];
            $parts = $data['parts'];
            $products = [];
            $listings = $data['marketplace_listings'];
            $byListing = [];
            foreach ($listings as &$listing) {
                $raw = is_array($listing['raw_payload'] ?? null) ? $listing['raw_payload'] : [];
                $listing['url'] = self::offerUrl($listing['url'] ?? null);
                $listing['allegro_parameters_from_saved_offer'] = str_starts_with((string) ($listing['marketplace'] ?? ''), 'allegro') ? $this->offerParameters($raw) : [];
                $allegro = str_starts_with((string) ($listing['marketplace'] ?? ''), 'allegro');
                $listing['migration_binding_policy'] = $allegro ? 'historical_only_republish_from_wystawczesc' : 'preserve_identity_pending_explicit_adoption';
                $listing['automatic_activation_allowed'] = false;
                $byListing[$listing['part_id']][] = $listing;
            }
            unset($listing);
            $data['marketplace_listings'] = $listings;
            $byParameter = [];
            foreach ($data['allegro_parameter_selections'] ?? [] as $p) {
                $byParameter[$p['part_id']][] = $p;
            }
            foreach ($parts as $part) {
                $legacy = is_array($part['legacy_payload'] ?? null) ? $part['legacy_payload'] : [];
                $review = is_array($part['review_metadata'] ?? null) ? $part['review_metadata'] : [];
                $part['part_position'] = collect([data_get($review, 'part_position'), data_get($legacy, 'part_position'), data_get($legacy, 'position')])->first(fn ($v) => is_scalar($v) && trim((string) $v) !== '');
                // Preserve the saved native value, and additionally expose the panel-normalized list.
                $part['additional_part_codes_saved'] = $part['additional_part_codes'] ?? null;
                $part['additional_part_codes'] = $this->codes(is_array($part['additional_part_codes'] ?? null) ? $part['additional_part_codes'] : []);
                $part['brand'] ??= is_scalar($legacy['brand'] ?? null) ? $legacy['brand'] : null;
                $part['manufacturer'] ??= is_scalar($legacy['manufacturer'] ?? null) ? $legacy['manufacturer'] : null;
                $part['allegro_parameters'] = $byParameter[$part['id']] ?? [];
                $part['offers'] = $byListing[$part['id']] ?? [];
                $part['is_sold_by_panel_rule'] = ($part['status'] ?? null) === 'sold';
                $part['is_to_list_by_panel_rule'] = (bool) ($part['needs_listing'] ?? false);
                $part['ean'] ??= null;
                $part['vat_rate'] ??= null;
                $part['images'] = [];
                $products[] = $part;
            }
            $cars = $data['cars'];
            foreach ($cars as &$car) {
                $legacy = is_array($car['legacy_payload'] ?? null) ? $car['legacy_payload'] : [];
                $car['ovoko_car_id'] = ($car['source_system'] ?? null) === 'ovoko' ? ($car['external_id'] ?? null) : ($legacy['ovoko_car_id'] ?? null);
                if (! is_scalar($car['ovoko_car_id'])) {
                    $car['ovoko_car_id'] = null;
                }
                if ($car['ovoko_car_id'] !== null) {
                    $car['ovoko_car_id'] = (string) $car['ovoko_car_id'];
                }
            }
            unset($car);
            $data['cars'] = $cars;
            $relations = $this->relations([...$required, ...$available]);
            if (isset($data['customers'])) {
                $relations['orders']['customer_id'] = 'customers';
            }
            if (isset($data['customer_returns'])) {
                $relations['customer_returns']['order_id'] = 'shop_events';
                $relations['customer_returns']['user_id'] = 'customers';
            }
            $relations['marketplace_listings']['marketplace_account_id'] = 'marketplace_account_identities';
            $relations['orders']['marketplace_account_id'] = 'marketplace_account_identities';
            $rules = ['sold_part_ids' => "Part::scopeSold / PartResource::adminSoldPartsQuery: status === 'sold'", 'parts_to_list_ids' => 'PartResource::adminPartsToListQuery: needs_listing = true; no quantity or offer-link inference'];
            $groups = ['sold_part_ids' => array_values(array_column(array_filter($products, fn ($p) => $p['is_sold_by_panel_rule']), 'id')), 'parts_to_list_ids' => array_values(array_column(array_filter($products, fn ($p) => $p['is_to_list_by_panel_rule']), 'id'))];
            $datasets = array_diff_key($data, array_flip(['parts', 'part_categories', 'cars', 'storage_locations']));

            return [
                'snapshot_at' => $started, 'snapshot_finished_at' => now()->toIso8601String(), 'database_consistency' => $db->getDriverName() === 'mysql' ? 'repeatable_read_read_only_innodb' : 'sqlite_read_transaction',
                'source_environment' => app()->environment(), 'source_host' => parse_url((string) config('app.url'), PHP_URL_HOST),
                'products' => $products, 'categories' => $data['part_categories'], 'cars' => $cars, 'storage_locations' => $data['storage_locations'], 'images' => $data['part_images'],
                'marketplace_category_mappings' => $data['marketplace_category_mappings'] ?? [], 'datasets' => $datasets, 'groups' => $groups, 'group_rules' => $rules,
                'relations' => $relations, 'schema_inventory' => $inventory, 'optional_tables_absent' => array_values(array_diff(self::OPTIONAL_TABLES, $available)),
                'source_counts' => ['products' => count($parts), 'photo_rows' => count($data['part_images']), 'categories' => count($data['part_categories']), 'cars' => count($cars), 'storage_locations' => count($data['storage_locations']), 'orders' => count($data['orders'] ?? []), 'order_items' => count($data['order_items'] ?? []), 'sold_parts' => count($groups['sold_part_ids']), 'parts_to_list' => count($groups['parts_to_list_ids']), 'statuses' => array_count_values(array_map(fn ($p) => $p['status'] ?? 'unknown', $parts)), 'dataset_counts' => array_map('count', $datasets)],
                'warnings' => ['sensitive_business_data_private_only', 'native_product_variants_not_supported', 'ean_and_vat_only_when_locally_stored_do_not_infer', 'status_history_refunds_cancellations_retained_if_stored_not_reconstructed', 'customer_returns_order_id_refers_to_shop_events_not_orders'],
            ];
        }));
    }

    private function readBusinessTable(string $table): array
    {
        $schema = Schema::getColumns($table);
        $allowed = [];
        $omitted = [];
        $metadata = [];
        foreach ($schema as $column) {
            $name = $column['name'];
            if (MigrationDataPolicy::sensitiveKey($name)) {
                $omitted[] = ['name' => $name, 'reason' => 'secret_authentication_or_card_capability'];

                continue;
            }
            $allowed[] = $name;
            $metadata[$name] = ['type' => $column['type'] ?? $column['type_name'] ?? 'unknown', 'nullable' => $column['nullable'] ?? null];
        }
        $query = DB::table($table);
        foreach (['id', 'order_id', 'part_id', 'event_id'] as $order) {
            if (in_array($order, $allowed, true)) {
                $query->orderBy($order);
                break;
            }
        }
        $rows = $query->get($allowed)->map(function ($record) use ($metadata) {
            $row = (array) $record;
            foreach ($row as $key => &$value) {
                $type = strtolower((string) $metadata[$key]['type']);
                if ($value !== null && preg_match('/decimal|numeric|real|double|float/', $type)) {
                    $scale = preg_match('/\(\d+,\s*(\d+)\)/', $type, $match) ? (int) $match[1] : (preg_match('/price|total|amount|subtotal|tax|discount|vat|paid|refund/i', $key) ? 2 : null);
                    // MySQL returns DECIMAL as a string. Never route it through float arithmetic.
                    if ($scale !== null) {
                        $value = $this->decimal($value, $scale);
                    }
                }
            }
            unset($value);

            return MigrationDataPolicy::clean($row);
        })->all();

        return [$rows, ['source_table' => $table, 'exported_columns' => $allowed, 'column_definitions' => $metadata, 'omitted_columns' => $omitted]];
    }

    private function decimal(mixed $value, int $scale): string
    {
        $value = (string) $value;
        if (preg_match('/^([+-]?\d+)(?:\.(\d*))?$/D', $value, $match)) {
            return $match[1].($scale > 0 ? '.'.str_pad(substr($match[2] ?? '', 0, $scale), $scale, '0') : '');
        }

        // SQLite test storage may emit scientific notation; source DECIMAL columns do not.
        return number_format((float) $value, $scale, '.', '');
    }

    private function relations(array $tables): array
    {
        $out = [];
        foreach ($tables as $table) {
            foreach (Schema::getForeignKeys($table) as $foreign) {
                $columns = $foreign['columns'] ?? [];
                if (count($columns) === 1) {
                    $out[$table][$columns[0]] = $foreign['foreign_table'];
                }
            }
        }
        $out['orders']['customer_id'] = 'customers';

        return $out;
    }

    private function codes(array $codes): array
    {
        return array_values(array_filter(array_map(fn ($v) => is_scalar($v) ? (string) $v : (is_scalar($v['code'] ?? null) ? (string) $v['code'] : null), $codes), fn ($v) => $v !== null));
    }

    private function offerParameters(array $raw): array
    {
        $out = [];
        foreach (['parameters' => $raw['parameters'] ?? [], 'product_parameters' => data_get($raw, 'productSet.0.product.parameters', [])] as $scope => $rows) {
            if (! is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $out[] = ['scope' => $scope] + $row;
                }
            }
        }

        return $out;
    }

    public static function offerUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $url = parse_url($value);
        $host = strtolower($url['host'] ?? '');
        if (! in_array($url['scheme'] ?? '', ['http', 'https'], true) || ! preg_match('/(?:^|\.)(?:allegro\.pl|allegrolokalnie\.pl|ebay\.(?:com|de|pl|co\.uk|fr|it|es)|ovoko\.(?:pl|com|de|fr|it|es)|rrr\.lt)$/D', $host)) {
            return null;
        }

        return MigrationDataPolicy::clean('https://'.$host.($url['path'] ?? '/').(isset($url['query']) ? '?'.$url['query'] : ''));
    }

    public static function clean(mixed $value): mixed
    {
        return MigrationDataPolicy::clean($value);
    }
}
