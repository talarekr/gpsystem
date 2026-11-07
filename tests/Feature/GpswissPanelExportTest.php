<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\MigrationExport\BrowserMigrationSnapshot;
use App\Services\MigrationExport\GpswissMigrationExport;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;
use ZipArchive;

class VerifyPanelExportCsrf extends ValidateCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}

/** Synthetic fixtures are confined to tests; no production or external API access. */
class GpswissPanelExportTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/admin/tools/gpswiss-migration-export';

    private const RUN = '8de6e27c-ad7c-470b-830b-b9ecee9446ba';

    private const ATTEMPT = '7bd47b3f-7600-4dd1-aaa3-5c3d4b595c87';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jZQAAAABJRU5ErkJggg==';

    private string $temporary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporary = sys_get_temp_dir().'/gps-panel-export-'.Str::uuid();
        mkdir($this->temporary.'/originals/a', 0700, true);
        mkdir($this->temporary.'/originals/b', 0700, true);
        config(['migration_export.root' => $this->temporary.'/exports', 'migration_export.php_cli' => '/unavailable/php',
            'migration_export.browser_snapshot_rows' => 2, 'migration_export.archive_payload_bytes' => 1024,
            'filesystems.disks.public.root' => $this->temporary.'/originals', 'filesystems.served_public_storage_root' => null]);
        Http::preventStrayRequests();
        Http::fake(fn () => throw new LogicException('No provider calls'));
        Bus::fake();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporary);
        parent::tearDown();
    }

    private function fixture(): void
    {
        DB::table('users')->insert(['id' => 50, 'name' => 'Private Buyer', 'email' => 'buyer@example.test', 'password' => 'PASSWORD_SECRET_PANEL']);
        DB::table('part_categories')->insert(['id' => 1, 'name' => 'Części', 'slug' => 'czesci']);
        DB::table('storage_locations')->insert(['id' => 1, 'name' => 'Półka 0001', 'is_active' => true]);
        foreach ([8, 9] as $id) {
            DB::table('cars')->insert(['id' => $id, 'uuid' => (string) Str::uuid(), 'make' => 'Brand', 'model' => 'Donor '.$id,
                'source_system' => 'ovoko', 'external_id' => '000'.$id, 'status' => $id === 9 ? 'archived' : 'kupiony']);
        }
        for ($id = 1; $id <= 5; $id++) {
            DB::table('parts')->insert(['id' => $id, 'sku' => '000SKU-'.$id, 'name' => 'Część '.$id,
                'part_number' => '000CODE-'.$id, 'additional_part_codes' => json_encode(['000EXTRA-'.$id]),
                'category_id' => 1, 'storage_location_id' => 1, 'car_id' => 8, 'quantity' => in_array($id, [1, 3], true) ? 0 : 1,
                'status' => $id === 1 ? 'sold' : ($id === 5 ? 'archived' : 'ready'), 'needs_listing' => $id === 2,
                'description' => 'Opis części '.$id, 'price' => $id === 2 ? null : '0.00', 'allegro_price' => '100.01',
                'ebay_price' => '200.02', 'ovoko_price' => '300.03', 'currency' => 'PLN', 'allegro_shipping_rate_name' => 'LISTY',
                'legacy_payload' => json_encode(['ean' => '0001234567890', 'tax_rate' => '23.00', 'custom_attribute' => 'keep-me', 'access_token' => 'PART_SECRET_PANEL'])]);
        }
        foreach (['a/Oryginał 0001.png', 'b/Oryginał 0001.png'] as $name) {
            file_put_contents($this->temporary.'/originals/'.$name, base64_decode(self::PNG));
        }
        foreach ([[1, 'a/Oryginał 0001.png'], [2, 'b/Oryginał 0001.png'], [3, 'missing.png']] as [$id, $path]) {
            DB::table('part_images')->insert(['id' => $id, 'part_id' => 1, 'path' => $path, 'sort_order' => $id - 1, 'is_primary' => $id === 1]);
        }
        DB::table('allegro_parameter_selections')->insert(['part_id' => 2, 'allegro_category_id' => '000CAT', 'parameter_id' => '000PARAM', 'value_id' => '000USED', 'value_label' => 'Używany']);
        foreach ([['ebay_de', '000EBAY'], ['ovoko', '000OVOKO'], ['allegro', '000ALLEGRO']] as [$marketplace, $offer]) {
            DB::table('marketplace_listings')->insert(['part_id' => 2, 'marketplace' => $marketplace, 'external_offer_id' => $offer, 'status' => 'active',
                'url' => 'https://example.test/'.$offer.'?token=OFFER_SECRET_PANEL', 'raw_payload' => json_encode(['parameters' => [['id' => '000PARAM']], 'refresh_token' => 'LISTING_SECRET_PANEL'])]);
        }
        foreach ([1, 2] as $id) {
            DB::table('orders')->insert(['id' => $id, 'customer_id' => 50, 'order_number' => '000ORDER-'.$id, 'marketplace' => $id === 1 ? 'ebay_de' : 'ovoko',
                'marketplace_order_id' => '000REMOTE-'.$id, 'status' => $id === 1 ? 'completed' : 'cancelled', 'customer_name' => 'Private Buyer',
                'email' => 'buyer@example.test', 'phone' => '+48123456789', 'address_line1' => 'Private street 1', 'postal_code' => '00-001', 'city' => 'Warszawa', 'country' => 'PL',
                'currency' => 'PLN', 'subtotal' => '0.00', 'shipping_total' => '0.00', 'total' => '0.00',
                'raw_payload' => json_encode(['payment' => ['card_number' => '4111111111111111', 'cvv' => 'CARD_SECRET_PANEL'], 'client_secret' => 'ORDER_SECRET_PANEL'])]);
            DB::table('order_items')->insert(['id' => $id, 'order_id' => $id, 'part_id' => $id === 1 ? 1 : null,
                'sku' => '000HISTORY-'.$id, 'product_name' => $id === 1 ? 'Stored name' : 'Deleted historical product', 'quantity' => 1, 'unit_price' => '0.00', 'line_total' => '0.00']);
        }
        // Model a legacy dangling reference; the outer SQLite test transaction is rolled back.
        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table('order_items')->insert(['id' => 3, 'order_id' => 2, 'part_id' => 9999, 'sku' => '000MISSING-3',
            'product_name' => 'Historical missing part', 'quantity' => 1, 'unit_price' => '0.00', 'line_total' => '0.00']);
    }

    private function owner(): User
    {
        $this->seed(RoleSeeder::class);
        $user = User::create(['name' => 'Export owner', 'email' => 'panel-export@example.test', 'password' => 'password']);
        $user->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }

    private function directory(): string
    {
        return $this->temporary.'/exports/'.self::RUN;
    }

    private function engine(): BrowserMigrationSnapshot
    {
        return app(BrowserMigrationSnapshot::class);
    }

    private function engineStart(): array
    {
        mkdir($this->directory(), 0700, true);

        return $this->engine()->begin($this->directory(), self::ATTEMPT);
    }

    private function engineFinish(array $index): array
    {
        for ($tick = 0; $tick < 1000 && ! $index['done'] && ! $index['failed']; $tick++) {
            $index = $this->engine()->tick($this->directory(), self::ATTEMPT);
        }

        return $index;
    }

    private function step(array $status): array
    {
        $action = $status['next_action'];
        $this->assertContains($action, ['snapshot_tick', 'tick', 'resume']);

        return $this->postJson(self::BASE.'/'.self::RUN, ['action' => $action,
            ...($action === 'snapshot_tick' ? ['expected_snapshot_attempt' => $status['snapshot_attempt_id']] : [])])
            ->assertOk()->assertJsonPath('run_id', self::RUN)->json();
    }

    public function test_logical_snapshot_freezes_max_ids_and_exports_verified_bounded_json_csv_without_source_writes(): void
    {
        $this->fixture();
        $writes = [];
        DB::listen(function ($event) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)/i', $event->sql)) {
                $writes[] = $event->sql;
            }
        });
        $index = $this->engineStart();
        while ($index['stage'] === 'boundaries') {
            $index = $this->engine()->tick($this->directory(), self::ATTEMPT);
        }
        $this->assertSame(['id' => 5], $index['datasets']['parts']['max_key']);
        $this->assertSame(5, $index['datasets']['parts']['total']);
        // A source write is a fixture action, outside the exporter's observed writes.
        $writesBeforeFixture = count($writes);
        DB::table('parts')->insert(['id' => 999, 'sku' => 'AFTER-CUTOFF', 'name' => 'Excluded late source row', 'quantity' => 1, 'status' => 'ready']);
        array_splice($writes, $writesBeforeFixture, 1);
        $index = $this->engineFinish($index);
        $this->assertTrue($index['done'], $index['last_error'] ?? 'incomplete');
        $this->assertSame('frozen_logical_per_dataset_boundaries_verified_second_pass_not_transactional', $index['database_consistency']);
        $rows = iterator_to_array($this->engine()->rows($this->directory(), 'parts', self::ATTEMPT));
        $this->assertSame(['1', '2', '3', '4', '5'], array_column($rows, 'id'));
        $this->assertSame('000SKU-1', $rows[0]['sku']);
        $this->assertSame('0.00', $rows[0]['price']);
        $this->assertNull($rows[1]['price']);
        foreach ($index['datasets'] as $dataset) {
            $this->assertSame($dataset['processed'], $dataset['verified']);
            foreach ($dataset['shards'] as $shard) {
                $this->assertLessThanOrEqual(2, $shard['rows']);
                $this->assertSame($shard['sha256'], hash_file('sha256', $this->directory().'/'.$shard['path']));
                $this->assertSame($shard['csv_sha256'], hash_file('sha256', $this->directory().'/'.$shard['csv_path']));
                $this->assertStringStartsWith("\xEF\xBB\xBF", file_get_contents($this->directory().'/'.$shard['csv_path']));
            }
        }
        $this->assertSame([], $writes);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_plain_part_update_without_updated_at_is_reconciled_by_second_pass(): void
    {
        $this->fixture();
        $index = $this->engineStart();
        for ($tick = 0; $tick < 1000 && ($index['datasets']['parts']['processed'] ?? 0) === 0 && ! $index['failed']; $tick++) {
            $index = $this->engine()->tick($this->directory(), self::ATTEMPT);
        }
        $this->assertSame(2, $index['datasets']['parts']['processed']);
        DB::table('parts')->where('id', 1)->update(['description' => 'Changed without timestamp']);
        $failed = $this->engineFinish($index);
        $this->assertTrue($failed['done'], $failed['last_error'] ?? '');
        $this->assertFalse($failed['failed']);
        $this->assertFileExists($this->directory().'/snapshot.json');
        $rows = iterator_to_array($this->engine()->rows($this->directory(), 'parts', self::ATTEMPT));
        $this->assertSame('Changed without timestamp', collect($rows)->firstWhere('id', '1')['description']);
        Http::assertNothingSent();
    }

    public function test_deleted_part_is_reported_by_tombstone_and_removed_from_derived_relations(): void
    {
        $this->fixture();
        $index = $this->engineStart();
        for ($tick = 0; $tick < 1000 && ($index['datasets']['parts']['processed'] ?? 0) < 4 && ! $index['failed']; $tick++) {
            $index = $this->engine()->tick($this->directory(), self::ATTEMPT);
        }
        DB::table('parts')->where('id', 3)->delete();
        $failed = $this->engineFinish($index);
        $this->assertTrue($failed['done'], $failed['last_error'] ?? '');
        $this->assertFalse($failed['failed']);
        $this->assertFileExists($this->directory().'/snapshot.json');
        $this->assertContains('3', $failed['datasets']['parts']['shards'][1]['deleted_ids']);
        $relations = iterator_to_array($this->engine()->rows($this->directory(), 'part_car_relations', self::ATTEMPT));
        $this->assertNotContains('3', array_column($relations, 'part_id'));
        Http::assertNothingSent();
    }

    public function test_http_only_export_finishes_all_datasets_photos_and_private_archives_without_cli_or_provider(): void
    {
        $this->fixture();
        $this->owner();
        $writes = [];
        DB::listen(function ($event) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)/i', $event->sql)) {
                $writes[] = $event->sql;
            }
        });
        $status = $this->postJson(self::BASE.'/'.self::RUN, ['action' => 'start'])->assertOk()->assertJsonPath('snapshot_mode', 'browser')->json();
        $attempt = $status['snapshot_attempt_id'];
        $seen = [];
        for ($tick = 0; $tick < 2000 && $status['state'] !== 'completed'; $tick++) {
            $seen[$status['phase']] = true;
            $status = $this->step($status);
            $this->assertSame($attempt, $status['snapshot_attempt_id']);
            $this->assertNull($status['last_error']);
        }
        $this->assertSame('completed', $status['state']);
        $this->assertSame('download', $status['next_action']);
        $index = json_decode(file_get_contents($this->directory().'/snapshot.json'), true);
        foreach (['parts' => 5, 'cars' => 2, 'part_car_relations' => 5, 'part_categories' => 1, 'storage_locations' => 1,
            'orders' => 2, 'order_items' => 3, 'marketplace_listings' => 3, 'part_images' => 3, 'customers' => 1] as $dataset => $count) {
            $rows = iterator_to_array($this->engine()->rows($this->directory(), $dataset, $attempt));
            $this->assertCount($count, $rows, $dataset);
            $this->assertSame($count, $index['datasets'][$dataset]['processed']);
        }
        $orders = iterator_to_array($this->engine()->rows($this->directory(), 'orders', $attempt));
        $this->assertSame('buyer@example.test', $orders[0]['email']);
        $items = iterator_to_array($this->engine()->rows($this->directory(), 'order_items', $attempt));
        $this->assertNull($items[1]['part_id']);
        $this->assertSame('000HISTORY-2', $items[1]['sku']);
        $this->assertSame('9999', $items[2]['part_id']);
        $this->assertSame('000MISSING-3', $items[2]['sku']);
        $partCars = iterator_to_array($this->engine()->rows($this->directory(), 'part_car_relations', $attempt));
        $this->assertSame(['1', '2', '3', '4', '5'], array_column($partCars, 'part_id'));
        $this->assertSame(['8'], array_values(array_unique(array_column($partCars, 'car_id'))));
        foreach ($this->engine()->files($this->directory(), $attempt) as $file) {
            $body = file_get_contents($this->directory().'/'.$file['path']);
            foreach (['PASSWORD_SECRET_PANEL', 'PART_SECRET_PANEL', 'OFFER_SECRET_PANEL', 'LISTING_SECRET_PANEL', 'CARD_SECRET_PANEL', 'ORDER_SECRET_PANEL', '4111111111111111'] as $secret) {
                $this->assertStringNotContainsString($secret, $body);
            }
        }
        $photos = array_map(fn ($line) => json_decode($line, true), array_filter(file($this->directory().'/photos.jsonl', FILE_IGNORE_NEW_LINES)));
        $this->assertCount(3, $photos);
        $this->assertCount(2, array_filter($photos, fn ($photo) => $photo['status'] === 'COPIED_VERIFIED'));
        $this->assertNotSame($photos[0]['package_path'], $photos[1]['package_path']);
        foreach ($photos as $photo) {
            if ($photo['status'] === 'COPIED_VERIFIED') {
                $this->assertSame('Oryginał 0001.png', $photo['original_filename']);
                $this->assertSame($photo['sha256'], hash_file('sha256', $this->directory().'/'.$photo['package_path']));
            }
        }
        $this->assertNotEmpty($status['download_artifacts']);
        $zipVolumes = 0;
        $archivedFragments = [];
        $sampleFragments = [];
        foreach ($status['download_artifacts'] as $artifact) {
            $response = $this->get(self::BASE.'/'.self::RUN.'?download=artifact&artifact='.urlencode($artifact['key']))->assertOk();
            $path = $response->baseResponse->getFile()->getPathname();
            $this->assertSame($artifact['sha256'], hash_file('sha256', $path));
            $this->assertSame($artifact['bytes'], filesize($path));
            $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
            if (str_ends_with($artifact['name'], '.zip')) {
                $zip = new ZipArchive;
                $this->assertTrue($zip->open($path));
                $descriptor = json_decode($zip->getFromName('__gpswiss_volume_manifest.json'), true);
                $this->assertNotEmpty($descriptor['entries']);
                foreach ($descriptor['entries'] as $entry) {
                    $bytes = $zip->getFromName($entry['zip_entry']);
                    $this->assertSame($entry['sha256'], hash('sha256', $bytes));
                    if (str_starts_with($artifact['key'], 'sample-')) {
                        $this->assertArrayNotHasKey($entry['offset'], $sampleFragments[$entry['archive_path']] ?? [], 'No duplicate sample fragment processing');
                        $sampleFragments[$entry['archive_path']][$entry['offset']] = $bytes;
                    } else {
                        $this->assertArrayNotHasKey($entry['offset'], $archivedFragments[$entry['archive_path']] ?? [], 'No duplicate full fragment processing');
                        $archivedFragments[$entry['archive_path']][$entry['offset']] = $bytes;
                    }
                }
                $zip->close();
                $zipVolumes++;
            }
        }
        $this->assertGreaterThan(1, $zipVolumes);
        foreach ($this->engine()->files($this->directory(), $attempt) as $file) {
            $this->assertArrayHasKey($file['path'], $archivedFragments, 'Every dataset JSON/CSV shard must reach the download archives');
            ksort($archivedFragments[$file['path']]);
            $this->assertSame($file['sha256'], hash('sha256', implode('', $archivedFragments[$file['path']])));
        }
        foreach ($photos as $photo) {
            if ($photo['status'] === 'COPIED_VERIFIED') {
                $this->assertArrayHasKey($photo['package_path'], $archivedFragments);
                ksort($archivedFragments[$photo['package_path']]);
                $this->assertSame($photo['sha256'], hash('sha256', implode('', $archivedFragments[$photo['package_path']])));
            }
        }
        $manifest = json_decode(file_get_contents($this->directory().'/migration-manifest.json'), true);
        $this->assertSame('gpswiss-wystawczesc-3', $manifest['schema_version']);
        $this->assertSame(self::RUN, $manifest['run_id']);
        $this->assertSame('historical_only_never_activate', $manifest['allegro_mapping_policy']);
        $this->assertTrue($manifest['source_remains_independent_copy']);
        foreach (['cutover_ready', 'automatic_import_allowed', 'automatic_publish', 'synchronization_enabled', 'provider_calls', 'database_writes', 'source_file_writes'] as $flag) {
            $this->assertFalse($manifest[$flag]);
        }
        foreach (['sold-part-ids.csv' => ['1'], 'parts-to-list-ids.csv' => ['2']] as $name => $expectedIds) {
            $stream = fopen($this->directory().'/'.$name, 'rb');
            $header = fgetcsv($stream, null, ';');
            $this->assertSame('part_id', ltrim($header[0], "\xEF\xBB\xBF"));
            $ids = [];
            while (($row = fgetcsv($stream, null, ';')) !== false) {
                $ids[] = $row[0];
            }
            fclose($stream);
            $this->assertSame($expectedIds, $ids, $name.' must use panel status/needs_listing rules');
        }
        $dictionary = json_decode(file_get_contents($this->directory().'/fields.generated.json'), true);
        $this->assertContains('sku', $dictionary['datasets']['parts']['exported_columns']);
        $statuses = json_decode(file_get_contents($this->directory().'/statuses.json'), true);
        $this->assertArrayHasKey('sold', $statuses['part_statuses']);
        $this->assertArrayHasKey('archived', $statuses['part_statuses']);
        $issues = array_map(fn ($line) => json_decode($line, true), array_filter(file($this->directory().'/relation-issues.jsonl', FILE_IGNORE_NEW_LINES)));
        $missingPart = array_values(array_filter($issues, fn ($issue) => $issue['source_dataset'] === 'order_items' && $issue['source_id'] === '3' && $issue['column'] === 'part_id'));
        $this->assertCount(1, $missingPart);
        $this->assertSame('9999', $missingPart[0]['referenced_id']);
        $this->assertSame('missing_source_reference', $missingPart[0]['reason_code']);
        foreach (['sold-part-ids.csv', 'parts-to-list-ids.csv', 'fields.generated.json', 'statuses.json', 'relation-issues.jsonl'] as $name) {
            $this->assertArrayHasKey($name, $archivedFragments);
            ksort($archivedFragments[$name]);
            $this->assertSame(hash_file('sha256', $this->directory().'/'.$name), hash('sha256', implode('', $archivedFragments[$name])));
        }
        $sampleManifest = json_decode(file_get_contents($this->directory().'/sample/manifest.json'), true);
        $this->assertSame(5, $sampleManifest['products_count']);
        $this->assertCount(5, array_unique($sampleManifest['sample_source_ids']));
        foreach (['sold', 'to_list', 'ebay', 'ovoko', 'multiple_photos'] as $case) {
            $this->assertArrayHasKey($case, $sampleManifest['case_selection']);
        }
        $this->assertSame('1', $sampleManifest['case_selection']['sold']);
        $this->assertSame('2', $sampleManifest['case_selection']['to_list']);
        $sampleProducts = [];
        foreach (glob($this->directory().'/sample/datasets/parts/*.json') as $file) {
            $sampleProducts = [...$sampleProducts, ...json_decode(file_get_contents($file), true)];
        }
        $this->assertCount(5, $sampleProducts);
        $this->assertSame('000SKU-1', $sampleProducts[0]['sku']);
        $this->assertNotEmpty(glob($this->directory().'/sample/datasets/orders/*.json'));
        $this->assertNotEmpty(glob($this->directory().'/sample/datasets/cars/*.json'));
        foreach (array_filter(file($this->directory().'/sample/files.jsonl', FILE_IGNORE_NEW_LINES)) as $line) {
            $file = json_decode($line, true);
            $archivePath = $file['archive_path'] ?? $file['path'];
            $this->assertArrayHasKey($archivePath, $sampleFragments, 'Every actual sample file must reach its own ZIP volumes');
            ksort($sampleFragments[$archivePath]);
            $this->assertSame($file['sha256'], hash('sha256', implode('', $sampleFragments[$archivePath])));
        }
        $this->assertCount(1, array_filter(file($this->directory().'/missing-originals.jsonl', FILE_IGNORE_NEW_LINES)));
        $this->assertSame([], $writes);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_419_snapshot_tick_preserves_checkpoint_and_login_reload_continues_same_attempt(): void
    {
        $this->fixture();
        $owner = $this->owner();
        $status = app(GpswissMigrationExport::class)->prepareBrowser($owner->id, self::RUN);
        $status = app(GpswissMigrationExport::class)->browserSnapshotTick($owner->id, self::RUN, $status['snapshot_attempt_id']);
        $before = file_get_contents($this->directory().'/state.json');
        $this->app->bind(ValidateCsrfToken::class, VerifyPanelExportCsrf::class);
        $this->withSession(['_token' => 'renewed']);
        $payload = ['action' => 'snapshot_tick', 'expected_snapshot_attempt' => $status['snapshot_attempt_id']];
        $this->postJson(self::BASE.'/'.self::RUN, $payload, ['X-CSRF-TOKEN' => 'expired'])->assertStatus(419)->assertJsonPath('reason_code', 'SESSION_EXPIRED');
        $this->assertSame($before, file_get_contents($this->directory().'/state.json'));
        $this->getJson(self::BASE.'?format=status')->assertOk()->assertJsonPath('run_id', self::RUN)->assertJsonPath('snapshot_attempt_id', $status['snapshot_attempt_id']);
        $this->postJson(self::BASE.'/'.self::RUN, $payload, ['X-CSRF-TOKEN' => 'renewed'])->assertOk()->assertJsonPath('snapshot_attempt_id', $status['snapshot_attempt_id']);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_existing_worker_pending_run_requires_explicit_conversion_and_preserves_production_uuid(): void
    {
        $this->fixture();
        $owner = $this->owner();
        mkdir($this->directory(), 0700, true);
        $legacy = ['schema_version' => 'gpswiss-wystawczesc-2', 'contains_customer_data' => true,
            'run_id' => self::RUN, 'owner_id' => $owner->id, 'state' => 'snapshot_pending', 'processed_images' => 0,
            'photo_results' => [], 'last_error' => null, 'snapshot_attempt_id' => self::ATTEMPT, 'snapshot_launch_status' => 'worker_launch_unavailable'];
        file_put_contents($this->directory().'/owner.json', json_encode(['owner_id' => $owner->id, 'run_id' => self::RUN]));
        file_put_contents($this->directory().'/state.json', json_encode($legacy));
        $before = file_get_contents($this->directory().'/state.json');
        $this->getJson(self::BASE.'?format=status')->assertOk()->assertJsonPath('run_id', self::RUN)->assertJsonPath('snapshot_attempt_id', self::ATTEMPT);
        $this->assertSame($before, file_get_contents($this->directory().'/state.json'));

        $pending = $this->postJson(self::BASE.'/'.self::RUN, ['action' => 'retry_snapshot', 'confirm_restart' => true,
            'expected_snapshot_attempt' => self::ATTEMPT])->assertOk()->assertJsonPath('run_id', self::RUN)
            ->assertJsonPath('snapshot_mode', 'browser')->assertJsonPath('next_action', 'snapshot_tick')->json();

        $this->assertNotSame(self::ATTEMPT, $pending['snapshot_attempt_id']);
        $this->assertCount(1, glob($this->temporary.'/exports/*/state.json'));
        $this->postJson(self::BASE.'/'.self::RUN, ['action' => 'snapshot_tick', 'expected_snapshot_attempt' => self::ATTEMPT])
            ->assertStatus(409)->assertJsonPath('reason_code', 'stale_snapshot_attempt');
        $this->assertSame($pending['snapshot_attempt_id'], json_decode(file_get_contents($this->directory().'/state.json'), true)['snapshot_attempt_id']);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_http_photo_retry_repairs_real_transient_copy_failure_without_recapture_or_touching_good_files(): void
    {
        $this->fixture();
        $this->owner();
        config(['migration_export.archive_payload_bytes' => 8 * 1024 * 1024]);
        $sourceBytes = base64_decode(self::PNG).str_repeat('photo-byte', 350000);
        file_put_contents($this->temporary.'/originals/b/Oryginał 0001.png', $sourceBytes);
        $status = $this->postJson(self::BASE.'/'.self::RUN, ['action' => 'start'])->assertOk()->json();
        for ($tick = 0; $tick < 1000 && $status['phase'] !== 'photos'; $tick++) {
            $status = $this->step($status);
        }
        $this->assertSame('photos', $status['phase']);
        $tasks = array_map(fn ($line) => json_decode($line, true), array_filter(file($this->directory().'/photo-tasks.jsonl', FILE_IGNORE_NEW_LINES)));
        $badTask = array_values(array_filter($tasks, fn ($task) => (string) $task['image_id'] === '2'))[0];
        $partial = $this->directory().'/'.$badTask['package_path'].'.partial';
        for ($tick = 0; $tick < 30 && ! is_file($partial); $tick++) {
            $status = $this->step($status);
        }
        $this->assertFileExists($partial);
        // A damaged private partial is transient; the frozen original remains byte-identical.
        $handle = fopen($partial, 'r+b');
        fwrite($handle, 'damaged partial bytes');
        fclose($handle);
        for ($tick = 0; $tick < 2000 && $status['state'] !== 'completed'; $tick++) {
            $status = $this->step($status);
            $this->assertNull($status['last_error']);
        }
        $this->assertSame('completed', $status['state']);
        $this->assertTrue($status['photo_retry_allowed']);
        $this->assertSame(1, $status['photo_results']['COPY_FAILED']);
        $attempt = $status['snapshot_attempt_id'];
        $snapshotHash = hash_file('sha256', $this->directory().'/snapshot.json');
        $oldArchiveHash = hash_file('sha256', $this->directory().'/archive-manifest.json');
        $photos = array_map(fn ($line) => json_decode($line, true), array_filter(file($this->directory().'/photos.jsonl', FILE_IGNORE_NEW_LINES)));
        $good = array_values(array_filter($photos, fn ($row) => (string) $row['image_id'] === '1'))[0];
        $goodPath = $this->directory().'/'.$good['package_path'];
        touch($goodPath, 1000000000);
        $goodHash = hash_file('sha256', $goodPath);
        $goodInode = fileinode($goodPath);
        $reads = $writes = [];
        DB::listen(function ($event) use (&$reads, &$writes): void {
            if (preg_match('/\bfrom\s+["`]?(parts|cars|orders|order_items)["`]?\b/i', $event->sql)) {
                $reads[] = $event->sql;
            }
            if (preg_match('/^\s*(insert|update|delete)/i', $event->sql)) {
                $writes[] = $event->sql;
            }
        });

        $status = $this->postJson(self::BASE.'/'.self::RUN, ['action' => 'retry_photos', 'confirm_retry_photos' => true])
            ->assertOk()->assertJsonPath('run_id', self::RUN)->assertJsonPath('snapshot_attempt_id', $attempt)->json();
        $raw = json_decode(file_get_contents($this->directory().'/state.json'), true);
        $history = $raw['photo_retry']['history'];
        for ($tick = 0; $tick < 2000 && $status['state'] !== 'completed'; $tick++) {
            $status = $this->step($status);
            $this->assertNull($status['last_error']);
            $this->assertSame($attempt, $status['snapshot_attempt_id']);
        }

        $this->assertSame('completed', $status['state']);
        $this->assertFalse($status['photo_retry_allowed']);
        $this->assertSame(2, $status['photo_results']['COPIED_VERIFIED']);
        $this->assertArrayNotHasKey('COPY_FAILED', $status['photo_results']);
        $this->assertSame($snapshotHash, hash_file('sha256', $this->directory().'/snapshot.json'));
        $this->assertSame($oldArchiveHash, hash_file('sha256', $this->directory().'/'.$history.'/archive-manifest.json'));
        $this->assertSame($goodHash, hash_file('sha256', $goodPath));
        clearstatcache(true, $goodPath);
        $this->assertSame(1000000000, filemtime($goodPath));
        $this->assertSame($goodInode, fileinode($goodPath));
        $this->assertSame(hash('sha256', $sourceBytes), hash_file('sha256', $this->directory().'/'.$badTask['package_path']));
        $this->assertSame($sourceBytes, file_get_contents($this->temporary.'/originals/b/Oryginał 0001.png'));
        $manifest = array_map(fn ($line) => json_decode($line, true), array_filter(file($this->directory().'/photo-manifest.jsonl', FILE_IGNORE_NEW_LINES)));
        $repaired = array_values(array_filter($manifest, fn ($row) => (string) $row['image_id'] === '2'));
        $this->assertCount(1, $repaired);
        $this->assertSame('COPIED_VERIFIED', $repaired[0]['status']);
        $generations = json_decode(file_get_contents($this->directory().'/state.json'), true)['photo_retry_generations'];
        $this->assertCount(1, $generations);
        $this->assertSame(1, $generations[0]['planned']);
        $this->assertSame(['COPIED_VERIFIED' => 1], $generations[0]['results']);
        $this->assertSame([], $reads);
        $this->assertSame([], $writes);
        $this->assertCount(1, glob($this->temporary.'/exports/*/state.json'));
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }
}
