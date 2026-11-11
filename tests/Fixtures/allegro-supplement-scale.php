<?php

use App\Services\MigrationExport\AllegroOfferParameterReadback;
use App\Services\MigrationExport\AllegroParametersSupplement;
use App\Services\MigrationExport\AllegroSchemaRefresh;
use App\Services\MigrationExport\GpswissMigrationExport;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

// Synthetic initialization regression, not a completed export or a production connection.
$root = $argv[1];
$run = '8de6e27c-ad7c-470b-830b-b9ecee9446ba';
$attempt = '9cb33c92-3f1e-4db0-b08d-052715aa5f4c';
$a = '30000000-0000-4000-8000-000000000003';
$b = 'a927b518-f49c-48ba-abdb-9bf87051fcdd';
$parent = $root.'/exports/'.$run;
$write = static function (string $path, array $value): void {
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0700, true);
    }
    file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    chmod($path, 0600);
};
if ($argv[2] === 'generate') {
    $counts = ['parts' => 7404, 'cars' => 521, 'part_car_relations' => 7404, 'part_categories' => 775, 'storage_locations' => 1592,
        'allegro_parameter_selections' => 117, 'marketplace_category_mappings' => 3433, 'part_translations' => 7197, 'category_translations' => 775,
        'car_images' => 0, 'ovoko_car_dictionary_entries' => 4123, 'orders' => 693, 'order_items' => 706, 'shipments' => 34,
        'customer_returns' => 0, 'shop_events' => 7, 'local_sales' => 7, 'stripe_fr_attempts' => 0, 'stripe_fr_events' => 0,
        'order_status_history' => 0, 'order_status_histories' => 0, 'order_status_changes' => 0, 'order_histories' => 0,
        'payments' => 0, 'order_payments' => 0, 'refunds' => 0, 'order_refunds' => 0, 'returns' => 0, 'order_returns' => 0,
        'return_items' => 0, 'shipment_packages' => 0, 'order_notes' => 0, 'order_discounts' => 0, 'order_taxes' => 0,
        'order_addresses' => 0, 'marketplace_listings' => 24609, 'part_images' => 20556, 'customers' => 0, 'marketplace_account_identities' => 4];
    $datasets = [];
    foreach ($counts as $name => $total) {
        $shards = [];
        for ($i = 0; $i < (int) ceil($total / 5); $i++) {
            $path = 'logical-snapshot/'.$attempt.'/datasets/'.$name.'/'.sprintf('%06d.json', $i);
            $shards[] = ['path' => $path, 'sha256' => str_repeat('a', 64), 'bytes' => 21000, 'rows' => min(5, $total - $i * 5), 'cursor_end' => ['id' => (string) min($total, ($i + 1) * 5)],
                'csv_path' => substr($path, 0, -5).'.csv', 'csv_sha256' => str_repeat('b', 64), 'csv_bytes' => 18000,
                'checksum_before' => str_repeat('c', 64), 'checksum_after' => str_repeat('d', 64), 'dataset_boundary_id' => str_repeat('e', 36), 'dataset_generation' => 1];
        }
        $datasets[$name] = ['total' => $total, 'processed' => $total, 'verified' => 0, 'shards' => $shards, 'csv_columns' => ['id'], 'relations' => [], 'cursor' => ['id' => (string) $total], 'done' => true];
    }
    $source = $parent.'/logical-snapshot/'.$attempt.'/checkpoint.json';
    $write($source, ['format' => 'gpswiss-wystawczesc-logical-3', 'attempt_id' => $attempt, 'stage' => 'completed', 'dataset_index' => count($counts), 'dataset_names' => array_keys($counts), 'datasets' => $datasets, 'last_error' => null]);
    $write($parent.'/owner.json', ['owner_id' => 7, 'run_id' => $run]);
    $write($parent.'/state.json', ['owner_id' => 7, 'run_id' => $run, 'state' => 'snapshot_completed', 'snapshot_attempt_id' => $attempt, 'snapshot_mode' => 'browser', 'snapshot_source_fingerprint' => 'SET_BY_RUNNER']);
    foreach (['allegro-schema-refresh' => $a, 'allegro-offer-readback' => $b] as $kind => $plan) {
        $directory = $root.'/exports/.'.$kind.'/'.$run.'/'.$attempt;
        $write($directory.'/current.json', ['plan_id' => $plan]);
        $directory .= '/plans/'.$plan;
        $write($directory.'/parent-index.json', []);
        $state = ['plan_id' => $plan, 'parent_run_id' => $run, 'parent_attempt_id' => $attempt,
            'parent_sha256' => hash_file('sha256', $source), 'index_sha256' => hash_file('sha256', $directory.'/parent-index.json'), 'phase' => 'completed', 'blocked_reason' => null];
        if ($kind === 'allegro-schema-refresh') {
            $categories = [];
            for ($i = 0; $i < 334; $i++) {
                $categories[(string) (100 + $i)] = ['category_id' => (string) (100 + $i), 'sources' => ['canonical_category_mapping' => true], 'refresh_required' => true,
                    'endpoints' => array_fill_keys(['parameters', 'product-parameters'], ['ok' => true, 'confirmed' => true, 'provider_gets' => 1, 'http_status' => 200, 'attempts' => 1, 'count_429' => 0, 'count_errors' => 0, 'fetched_at' => '2026-11-11T10:00:00Z'])];
            }
            $state += ['categories' => $categories, 'category_offset' => 334, 'endpoint_offset' => 0];
            $definition = ['plan_id' => $plan, 'endpoints' => ['parameters', 'product-parameters'], 'categories' => array_values($categories)];
        } else {
            $offers = $outcomes = $parts = [];
            for ($i = 0; $i < 6326; $i++) {
                $id = (string) (100000 + $i);
                $offers[$id] = ['offer_id' => $id, 'account_code' => 'allegro_main', 'links' => [(string) ($i + 1) => ['part_id' => (string) ($i + 1), 'listing_id' => (string) ($i + 1), 'marketplace' => 'allegro_main', 'marketplace_account_id' => '2', 'account_source' => 'frozen_account_identity']]];
                $outcomes[$id] = ['ok' => $i < 5462, 'http_status' => $i < 5462 ? 200 : 404, 'reason_code' => $i < 5462 ? 'offer_fetched' : 'offer_404', 'confirmed' => true, 'provider_gets' => 1, 'count_429' => 0, 'count_errors' => 0, 'attempts' => 1];
                $parts[(string) ($i + 1)] = true;
            }
            $state += ['offers' => $offers, 'outcomes' => $outcomes, 'parts_with_allegro' => $parts, 'offset' => 6326,
                'invalid_offer_ids' => 0, 'conflicting_id_fields' => 0, 'orphan_listings_excluded' => 0, 'unsupported_accounts' => 0, 'duplicate_offer_ids' => 0];
            $definition = ['plan_id' => $plan, 'offers' => array_values($offers)];
        }
        $write($directory.'/plan-definition.json', $definition);
        $state['plan_digest'] = hash_file('sha256', $directory.'/plan-definition.json');
        $write($directory.'/checkpoint.json', $state);
    }
    exit;
}
foreach (['APP_ENV' => 'testing', 'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'GPS_INTEGRATIONS_ENABLED' => 'false', 'GPS_EXTERNAL_API_WRITES_ENABLED' => 'false'] as $key => $value) {
    putenv($key.'='.$value);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['migration_export.root' => $root.'/exports']);
Http::preventStrayRequests();
$parents = app(GpswissMigrationExport::class);
$state = json_decode(file_get_contents($parent.'/state.json'), true);
$state['snapshot_source_fingerprint'] = (new ReflectionMethod($parents, 'snapshotSourceFingerprint'))->invoke($parents);
$write($parent.'/state.json', $state);
unset($state);
$supplement = app(AllegroParametersSupplement::class);
$diagnostic = $supplement->diagnostics(7, $run, $attempt, 'plan', $a, $b);
$status = app(AllegroSchemaRefresh::class)->withCompletedPlan(7, $run, $attempt, $a,
    fn () => app(AllegroOfferParameterReadback::class)->withCompletedPlan(7, $run, $attempt, $b,
        fn () => $supplement->plan(7, $run, $attempt)));
$diagnostic->success();
Http::assertNothingSent();
echo json_encode(['phase' => $status['phase'], 'plan' => $status['plan'], 'peak_memory_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR);
