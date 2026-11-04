<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Part;
use App\Models\PartImage;
use App\Models\User;
use App\Services\MigrationExport\GpswissMigrationExport;
use App\Services\MigrationExport\GpswissSnapshot;
use App\Services\MigrationExport\MigrationOriginals;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;
use ZipArchive;

class GpswissMigrationExportTest extends TestCase
{
    use RefreshDatabase;

    private string $temporary;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jZQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporary = sys_get_temp_dir().'/gps-export-'.Str::uuid();
        mkdir($this->temporary.'/originals', 0700, true);
        config(['migration_export.root' => $this->temporary.'/exports', 'filesystems.disks.public.root' => $this->temporary.'/originals', 'filesystems.served_public_storage_root' => null]);
        Http::preventStrayRequests();
        Http::fake(fn () => throw new LogicException('Provider forbidden'));
        Bus::fake();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporary);
        parent::tearDown();
    }

    private function fixture(): void
    {
        DB::table('part_categories')->insert([['id' => 1, 'name' => 'Parent', 'slug' => 'parent', 'parent_id' => null], ['id' => 2, 'name' => 'Used part', 'slug' => 'used-part', 'parent_id' => 1], ['id' => 3, 'name' => 'Unreferenced', 'slug' => 'not-needed', 'parent_id' => null]]);
        DB::table('storage_locations')->insert(['id' => 1, 'name' => 'Shelf A', 'is_active' => true]);
        DB::table('cars')->insert(['id' => 8, 'uuid' => (string) Str::uuid(), 'source_system' => 'ovoko', 'external_id' => '1800', 'make' => 'Brand', 'model' => 'Model', 'vin' => 'PRIVATE-VIN', 'seller_name' => 'CUSTOMER_SECRET']);
        for ($id = 1; $id <= 6; $id++) {
            DB::table('parts')->insert(['id' => $id, 'sku' => 'GPS-'.$id, 'name' => 'Part '.$id, 'part_number' => 'CODE-'.$id, 'additional_part_codes' => json_encode(['EXTRA-'.$id]), 'condition_notes' => 'Używany',
                'category_id' => 2, 'storage_location_id' => 1, 'car_id' => 8, 'description' => 'Opis; produkt "'.$id.'"', 'price' => '100.00', 'allegro_price' => '123.45', 'ovoko_price' => '222.22', 'ebay_price' => '333.33', 'currency' => 'PLN', 'quantity' => $id === 3 ? 0 : 1, 'status' => $id === 3 ? 'archived' : 'ready',
                'allegro_shipping_rate_name' => 'LISTY', 'review_metadata' => json_encode(['part_position' => $id === 5 ? 'Lewa strona' : null, 'access_token' => 'REVIEW_SECRET']),
                'legacy_payload' => json_encode(['brand' => 'Brand', 'customer' => ['email' => 'CUSTOMER_SECRET'], 'refresh_token' => 'LEGACY_SECRET'])]);
        }
        foreach ([[1, 1, 'same.png'], [2, 1, 'second.png'], [3, 2, 'missing.png'], [4, 4, 'same.png'], [5, 5, 'https://private.invalid/a.png?token=PHOTO_SECRET']] as [$id,$part,$path]) {
            DB::table('part_images')->insert(['id' => $id, 'part_id' => $part, 'path' => $path, 'sort_order' => $id === 2 ? 1 : 0, 'is_primary' => $id !== 2, 'alt_text' => 'Original name']);
        }
        foreach (['same.png', 'second.png'] as $name) {
            file_put_contents($this->temporary.'/originals/'.$name, base64_decode(self::PNG));
        }
        DB::table('allegro_parameter_selections')->insert(['part_id' => 4, 'allegro_category_id' => 'cat', 'parameter_id' => 'param', 'parameter_name' => 'Stan', 'value_id' => 'used', 'value_label' => 'Używany']);
        DB::table('marketplace_listings')->insert(['marketplace' => 'allegro', 'part_id' => 1, 'external_offer_id' => 'offer-1', 'url' => 'https://user:PRIVATE_PASSWORD@allegro.pl/oferta/offer-1?access_token=URL_SECRET#secret', 'status' => 'active',
            'raw_payload' => json_encode(['buyer' => ['name' => 'CUSTOMER_SECRET'], 'access_token' => 'PAYLOAD_SECRET', 'parameters' => [['id' => '5', 'values' => ['A']]], 'productSet' => [['product' => ['parameters' => [['id' => '6', 'valuesIds' => ['B']]]]]]])]);
        DB::table('marketplace_listings')->insert(['marketplace' => 'ebay_de', 'part_id' => 1, 'external_offer_id' => 'ebay-1', 'url' => 'https://www.ebay.de/itm/123?token=EBAY_SECRET', 'status' => 'active']);
        DB::table('marketplace_listings')->insert(['marketplace' => 'ovoko', 'part_id' => 1, 'external_offer_id' => 'ovoko-1', 'url' => 'https://ovoko.pl/czesci/123', 'status' => 'active']);
    }

    private function finish(array $state): array
    {
        for ($i = 0; $i < 20 && $state['state'] !== 'completed'; $i++) {
            $state = app(GpswissMigrationExport::class)->tick(1, $state['run_id']);
            $this->assertNull($state['last_error']);
        }
        $this->assertSame('completed', $state['state']);

        return $state;
    }

    private function archive(array $state, string $kind): ZipArchive
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(app(GpswissMigrationExport::class)->download(1, $state['run_id'], $kind)));

        return $zip;
    }

    public function test_real_sample_and_full_share_snapshot_all_requested_fields_and_verified_originals_without_side_effects(): void
    {
        $this->fixture();
        $before = [];
        foreach (['parts', 'part_images', 'cars', 'part_categories', 'storage_locations', 'marketplace_listings', 'marketplace_accounts', 'marketplace_sync_logs', 'allegro_parameter_selections'] as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        Part::saving(fn () => throw new LogicException('No Part hook'));
        PartImage::saving(fn () => throw new LogicException('No image hook'));
        $mutations = [];
        DB::listen(function ($event) use (&$mutations) {
            if (preg_match('/^\s*(insert|update|delete)/i', $event->sql)) {
                $mutations[] = $event->sql;
            }
        });
        $service = app(GpswissMigrationExport::class);
        $id = (string) Str::uuid();
        $state = $service->start(1, $id);
        $this->assertSame($id, $service->start(1, $id)['run_id']); // lost start response can be replayed
        $this->assertSame(6, $state['source_counts']['products']);
        $this->assertSame(5, $state['source_counts']['photo_rows']);
        $state = $this->finish($state);
        $this->assertCount(5, $state['sample_ids']);
        $full = $this->archive($state, 'full');
        $sample = $this->archive($state, 'sample');
        $manifest = json_decode($full->getFromName('manifest.json'), true);
        $sampleManifest = json_decode($sample->getFromName('manifest.json'), true);
        $this->assertSame($manifest['run_id'], $sampleManifest['run_id']);
        $this->assertSame($manifest['snapshot_at'], $sampleManifest['snapshot_at']);
        $this->assertSame(6, $manifest['products_count']);
        $this->assertSame(5, $sampleManifest['products_count']);
        $this->assertSame(3, $manifest['verified_originals']);
        $this->assertSame(2, $manifest['missing_or_blocked_originals']);
        foreach (['automatic_import_allowed', 'automatic_publish', 'synchronization_enabled', 'provider_calls', 'database_writes', 'source_file_writes', 'cutover_ready'] as $flag) {
            $this->assertFalse($manifest[$flag]);
        }
        $products = json_decode($full->getFromName('products.json'), true);
        $p = $products[0];
        $this->assertSame('GPS-1', $p['sku']);
        $this->assertSame('CODE-1', $p['part_number']);
        $this->assertSame(['EXTRA-1'], $p['additional_part_codes']);
        $this->assertSame('Used part', $p['category_name']);
        $this->assertSame('Shelf A', $p['storage_location_name']);
        $this->assertSame('Używany', $p['quality']);
        $this->assertSame('Lewa strona', $products[4]['part_position']);
        $this->assertSame(8, $p['local_car_id']);
        $this->assertSame('1800', $p['ovoko_car_id']);
        $this->assertSame(['100.00', '123.45', '222.22', '333.33'], [$p['price'], $p['allegro_price'], $p['ovoko_price'], $p['ebay_price']]);
        $this->assertSame('LISTY', $p['allegro_shipping_rate_name']);
        $this->assertCount(3, $p['offers']);
        $this->assertSame('https://allegro.pl/oferta/offer-1', $p['offers'][0]['url']);
        $this->assertCount(2, $p['offers'][0]['allegro_parameters_from_saved_offer']);
        $this->assertSame('used', $products[3]['allegro_parameters'][0]['value_id']);
        $this->assertSame([1, 2], array_column(json_decode($full->getFromName('categories.json'), true), 'id'));
        $photos = json_decode($full->getFromName('photos.json'), true);
        $this->assertSame('same.png', $photos[0]['original_filename']);
        $this->assertTrue($photos[0]['is_primary']);
        $this->assertSame(1, $photos[1]['sort_order']);
        $this->assertNotSame($photos[0]['package_path'], $photos[3]['package_path']);
        foreach ($photos as $photo) {
            if ($photo['status'] === 'COPIED_VERIFIED') {
                $bytes = $full->getFromName($photo['package_path']);
                $this->assertSame($photo['sha256'], hash('sha256', $bytes));
                $this->assertSame(strlen($bytes), $photo['bytes']);
            }
        }
        foreach ($manifest['file_sha256'] as $name => $hash) {
            $this->assertSame($hash, hash('sha256', $full->getFromName($name)));
        }
        for ($i = 0; $i < $full->numFiles; $i++) {
            $bytes = $full->getFromIndex($i);
            foreach (['CUSTOMER_SECRET', 'PRIVATE-VIN', 'PRIVATE_PASSWORD', 'REVIEW_SECRET', 'LEGACY_SECRET', 'PHOTO_SECRET', 'URL_SECRET', 'EBAY_SECRET', 'PAYLOAD_SECRET'] as $secret) {
                $this->assertStringNotContainsString($secret, $bytes, $full->getNameIndex($i));
            }
        }
        foreach ($before as $table => $json) {
            $this->assertSame($json, DB::table($table)->get()->toJson(), $table);
        }
        $this->assertSame([], $mutations);
        $this->assertSame(base64_decode(self::PNG), file_get_contents($this->temporary.'/originals/same.png'));
        $full->close();
        $sample->close();
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_resume_uses_frozen_database_and_reports_changed_files_instead_of_mixing_them(): void
    {
        $this->fixture();
        $service = app(GpswissMigrationExport::class);
        $state = $service->start(1);
        DB::table('parts')->where('id', 1)->update(['price' => '999.99', 'sku' => 'LATER']);
        file_put_contents($this->temporary.'/originals/same.png', 'changed');
        $state = $this->finish($state);
        $zip = $this->archive($state, 'full');
        $p = json_decode($zip->getFromName('products.json'), true)[0];
        $this->assertSame('100.00', $p['price']);
        $this->assertSame('GPS-1', $p['sku']);
        $photos = json_decode($zip->getFromName('photos.json'), true);
        $this->assertSame('SOURCE_CHANGED', $photos[0]['status']);
        $this->assertFalse($zip->getFromName($photos[0]['package_path']));
        $zip->close();
        Http::assertNothingSent();
    }

    public function test_private_http_access_and_unknown_export_cannot_download_or_start_import(): void
    {
        $this->fixture();
        $this->seed(RoleSeeder::class);
        $user = User::create(['name' => 'Owner', 'email' => 'migration@example.test', 'password' => 'password']);
        $user->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $base = '/admin/tools/gpswiss-migration-export';
        $id = (string) Str::uuid();
        $this->get($base)->assertOk();
        $this->postJson($base.'/'.$id, ['action' => 'start'])->assertOk()->assertJsonPath('run_id', $id)->assertJsonPath('database_writes', false);
        $this->postJson($base.'/'.$id, ['action' => 'start'])->assertOk()->assertJsonPath('run_id', $id);
        $this->get($base.'/'.$id.'?download=full')->assertStatus(409);
        $this->postJson($base.'/'.$id, ['action' => 'import'])->assertUnprocessable();
        $this->postJson($base.'/'.Str::uuid(), ['action' => 'tick'])->assertNotFound();
        $other = User::create(['name' => 'Other', 'email' => 'other-migration@example.test', 'password' => 'password']);
        $other->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($other);
        $this->getJson($base.'/'.$id)->assertNotFound();
        $other->removeRole(UserRole::OwnerAdmin->value);
        $this->get($base)->assertForbidden();
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_cli_export_and_free_text_secret_redaction(): void
    {
        $this->fixture();
        $this->artisan('gps:migration-export', ['--owner-id' => 1])->assertSuccessful();
        $text = GpswissSnapshot::clean('https://user:URL_SECRET@allegro.pl/path?token=QUERY_SECRET email@example.test access_token=TEXT_SECRET');
        foreach (['URL_SECRET', 'QUERY_SECRET', 'TEXT_SECRET', 'email@example.test'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_original_reader_rejects_traversal_remote_and_conflicting_mirrors(): void
    {
        $this->fixture();
        $originals = app(MigrationOriginals::class);
        $this->assertSame('UNSAFE_OR_EMPTY_PATH', $originals->locate('../secret')['status']);
        $this->assertSame('REMOTE_NOT_FETCHED', $originals->locate('https://host.invalid/image?token=secret')['status']);
        mkdir($this->temporary.'/mirror');
        file_put_contents($this->temporary.'/mirror/same.png', 'different');
        config(['filesystems.served_public_storage_root' => $this->temporary.'/mirror']);
        mkdir($this->temporary.'/copy');
        $task = $originals->task(['id' => 99, 'part_id' => 1, 'path' => 'same.png'], ['part_number' => 'CODE']);
        $this->assertSame('CONFLICTING_COPIES', $originals->copy($task, $this->temporary.'/copy')['status']);
        symlink($this->temporary.'/mirror/same.png', $this->temporary.'/originals/outside.png');
        config(['filesystems.served_public_storage_root' => null]);
        $this->assertSame('UNREADABLE_OR_UNSAFE', $originals->locate('outside.png')['status']);
        Http::assertNothingSent();
    }
}
