<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\MigrationExport\MigrationExportException;
use App\Services\MigrationExport\PartPhotoStorageDiagnostic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PartPhotoStorageDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/photo-diagnostic-'.Str::uuid();
        config(['filesystems.disks.public.driver' => 'local', 'filesystems.disks.public.root' => $this->root]);
        Http::preventStrayRequests();
        Http::fake(fn () => throw new \LogicException('Provider calls forbidden'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_exact_allowlist_and_missing_root_are_read_only(): void
    {
        config(['filesystems.disks.public.secret' => 'SECRET_FILESYSTEM', 'filesystems.disks.s3.key' => 'SECRET_OTHER_DISK']);
        Storage::shouldReceive('disk')->never();
        $queries = [];
        DB::listen(function ($e) use (&$queries) {
            $queries[] = $e->sql;
        });
        $data = (new PartPhotoStorageDiagnostic)->inspect();
        $this->assertSame(['base_path', 'storage_path', 'storage_path_app_public', 'public_path', 'public_path_storage', 'part_images_disk', 'disk_root', 'example', 'known_roots', 'known_product', 'sample_status', 'sample_images', 'original_locations', 'scope'], array_keys($data));
        $this->assertSame(['db_path', 'disk', 'resolved_absolute_path', 'exists', 'relative_path', 'path_status', 'candidates', 'found_paths', 'resolution_status'], array_keys($data['example']));
        $this->assertSame(base_path(), $data['base_path']);
        $this->assertSame(storage_path(), $data['storage_path']);
        $this->assertSame(storage_path('app/public'), $data['storage_path_app_public']);
        $this->assertSame(public_path(), $data['public_path']);
        $this->assertSame(public_path('storage'), $data['public_path_storage']);
        $this->assertSame('public', $data['part_images_disk']);
        $this->assertSame($this->root, $data['disk_root']);
        $this->assertSame(PartPhotoStorageDiagnostic::EXAMPLE, $data['example']['db_path']);
        $this->assertSame('public', $data['example']['disk']);
        $this->assertSame($this->root.'/'.PartPhotoStorageDiagnostic::EXAMPLE, $data['example']['resolved_absolute_path']);
        $this->assertFalse($data['example']['exists']);
        $this->assertDirectoryDoesNotExist($this->root);
        $this->assertStringNotContainsString('SECRET', json_encode($data));
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/^select\b/i', $sql);
        }
        Http::assertNothingSent();
    }

    public function test_existing_example_resolves_real_file_without_modifying_it(): void
    {
        $path = $this->root.'/'.PartPhotoStorageDiagnostic::EXAMPLE;
        File::makeDirectory(dirname($path), 0755, true);
        file_put_contents($path, 'synthetic fixture');
        $before = hash_file('sha256', $path);
        $data = (new PartPhotoStorageDiagnostic)->inspect();
        $this->assertTrue($data['example']['exists']);
        $this->assertSame(realpath($path), $data['example']['resolved_absolute_path']);
        $this->assertSame($before, hash_file('sha256', $path));
    }

    public function test_symlink_root_reports_actual_physical_file(): void
    {
        $actual = $this->root.'/actual';
        $link = $this->root.'/public-link';
        $path = $actual.'/'.PartPhotoStorageDiagnostic::EXAMPLE;
        File::makeDirectory(dirname($path), 0755, true);
        file_put_contents($path, 'unchanged fixture');
        symlink($actual, $link);
        config(['filesystems.disks.public.root' => $link]);
        $data = (new PartPhotoStorageDiagnostic)->inspect();
        $this->assertSame($link, $data['disk_root']);
        $this->assertSame(realpath($path), $data['example']['resolved_absolute_path']);
        $this->assertTrue($data['example']['exists']);
        $this->assertTrue(is_link($link));
        $this->assertSame('unchanged fixture', file_get_contents($path));
    }

    public function test_owner_page_only_exposes_allowlisted_diagnostic(): void
    {
        $url = '/admin/tools/product-migration-export';
        $this->getJson($url)->assertUnauthorized();
        $user = User::query()->create(['name' => 'Reader', 'email' => 'photo-reader@test.invalid', 'password' => 'password']);
        $this->actingAs($user)->getJson($url)->assertForbidden();
        $role = Role::findOrCreate(UserRole::OwnerAdmin->value, 'web');
        $user->assignRole($role);
        config(['filesystems.disks.public.password' => 'SECRET_PASSWORD']);
        Storage::shouldReceive('disk')->never();
        $queries = [];
        DB::listen(function ($e) use (&$queries) {
            $queries[] = $e->sql;
        });
        $response = $this->actingAs($user)->get($url)->assertOk()->assertSee('Ścieżki oryginalnych zdjęć')->assertSee($this->root);
        $response->assertSee('resolved_absolute_path')->assertSee('false')->assertDontSee('SECRET_PASSWORD');
        $response->assertViewHas('photoStorageDiagnostic', fn ($d) => $d['part_images_disk'] === 'public' && $d['example']['exists'] === false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('noindex, noarchive', $response->headers->get('X-Robots-Tag'));
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/^select\b/i', $sql);
        }
        $this->assertDirectoryDoesNotExist($this->root);
        Http::assertNothingSent();
    }

    private function image(int $id, string $path, array $presentation = [], string $source = 'test'): void
    {
        DB::table('parts')->insertOrIgnore(['id' => 1, 'name' => 'Synthetic part']);
        DB::table('part_images')->insert(['id' => $id, 'part_id' => 1, 'path' => $path, 'source_system' => $source, 'legacy_payload' => json_encode(['presentation' => $presentation, 'credentials' => 'SECRET_PAYLOAD'])]);
    }

    private function fixture(string $root, string $relative): string
    {
        $path = $root.'/'.$relative;
        File::makeDirectory(dirname($path), 0755, true, true);
        file_put_contents($path, 'unchanged fixture');

        return $path;
    }

    public function test_five_real_rows_and_exact_known_product_find_original_in_mirror_only(): void
    {
        $original = 'parts/photos/imported/actual/source.jpg';
        $listing = str_replace('/product/', '/listing/', PartPhotoStorageDiagnostic::KNOWN_PRODUCT);
        $this->image(20081, $original, ['product_path' => PartPhotoStorageDiagnostic::KNOWN_PRODUCT, 'listing_path' => $listing, 'source_path' => $original]);
        for ($id = 1; $id <= 8; $id++) {
            $this->image($id, 'parts/photos/imported/'.$id.'/actual.jpg');
        }
        $mirror = $this->root.'/mirror';
        config(['filesystems.served_public_storage_root' => $mirror]);
        $file = $this->fixture($mirror, $original);
        $this->fixture($mirror, PartPhotoStorageDiagnostic::KNOWN_PRODUCT);
        $this->fixture($this->root, $listing);
        $before = hash_file('sha256', $file);
        $queries = [];
        DB::listen(function ($e) use (&$queries) {
            $queries[] = $e->sql;
        });
        Storage::shouldReceive('disk')->never();
        $data = (new PartPhotoStorageDiagnostic)->inspect();
        $this->assertCount(5, $data['sample_images']);
        $this->assertSame(['20081', '1', '2', '3', '4'], array_column($data['sample_images'], 'image_id'));
        $row = $data['sample_images'][0];
        $this->assertSame('1', $row['part_id']);
        $this->assertSame($original, $row['db_path']);
        $this->assertFalse($row['exists']);
        $this->assertSame([realpath($file)], $row['original']['found_paths']);
        $this->assertFalse($row['product']['exists']);
        $this->assertNotEmpty($row['product']['found_paths']);
        $this->assertTrue($row['listing']['exists']);
        $this->assertSame('exact_stored_product_path', $data['known_product']['source_match']);
        $this->assertSame($original, $data['known_product']['original']['db_path']);
        $this->assertNotEmpty($data['known_product']['product']['found_paths']);
        $this->assertSame($listing, $data['known_product']['listing']['relative_path']);
        $this->assertSame(realpath($file), $data['original_locations'][0]['files'][0]['resolved_absolute_path']);
        $this->assertSame($before, hash_file('sha256', $file));
        $this->assertStringNotContainsString('SECRET_PAYLOAD', json_encode($data));
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/^select\b/i', $sql);
        }
        $this->assertStringContainsString('limit 4', implode('\n', $queries));
        Http::assertNothingSent();
    }

    public function test_stale_variant_does_not_invent_corresponding_original(): void
    {
        $this->image(20081, 'parts/photos/original.jpg', ['product_path' => 'parts/photos/presentation/product/20081-other.jpg']);
        $this->fixture($this->root, PartPhotoStorageDiagnostic::KNOWN_PRODUCT);
        $data = (new PartPhotoStorageDiagnostic)->inspect();
        $this->assertTrue($data['known_product']['product']['exists']);
        $this->assertSame('image_id_hint_only_not_verified', $data['known_product']['source_match']);
        $this->assertNull($data['known_product']['original']);
    }

    public function test_remote_unsafe_paths_and_hidden_payload_are_not_probed_or_exposed(): void
    {
        $this->image(1, 'https://external.invalid/secret.jpg?token=SECRET_TOKEN', ['source_path' => '../../.env']);
        $this->image(2, '../outside.jpg');
        $this->image(3, 'parts/photos/token=SECRET_LOCAL.jpg');
        $data = (new PartPhotoStorageDiagnostic)->inspect();
        $this->assertSame('remote_or_unmapped_url_not_fetched', $data['sample_images'][0]['original']['path_status']);
        $this->assertSame([], $data['sample_images'][0]['original']['candidates']);
        $this->assertSame([], $data['sample_images'][0]['presentation_source']['candidates']);
        $this->assertSame('unsafe_path', $data['sample_images'][1]['original']['path_status']);
        $this->assertSame([], $data['sample_images'][1]['original']['candidates']);
        $this->assertSame('sensitive_path_not_probed', $data['sample_images'][2]['original']['path_status']);
        $this->assertSame([], $data['sample_images'][2]['original']['candidates']);
        $this->assertStringNotContainsString('SECRET', json_encode($data));
        Http::assertNothingSent();
    }

    public function test_known_local_url_and_historical_flat_upload_candidates(): void
    {
        $this->image(1, 'https://gpswiss.pl/storage/parts/photos/flat.jpg?token=SECRET_TOKEN', [], 'workshop_quick_create');
        $target = 'parts/photos/imported/1/flat.jpg';
        $this->fixture($this->root, $target);
        $data = (new PartPhotoStorageDiagnostic)->inspect();
        $row = $data['sample_images'][0];
        $this->assertSame('parts/photos/flat.jpg', $row['original']['relative_path']);
        $this->assertFalse($row['original']['exists']);
        $this->assertCount(2, $row['historical_candidates']);
        $this->assertSame('workshop_original_copy', $row['historical_candidates'][1]['rule']);
        $this->assertTrue($row['historical_candidates'][1]['lookup']['exists']);
        $this->assertTrue($row['historical_candidates'][1]['candidate_only_not_content_verified']);
        $this->assertStringNotContainsString('SECRET', json_encode($data));
    }

    public function test_distinct_copies_are_reported_without_selecting_one(): void
    {
        $relative = 'parts/photos/multiple.jpg';
        $this->image(1, $relative);
        $this->fixture($this->root, $relative);
        $mirror = $this->root.'/mirror';
        $this->fixture($mirror, $relative);
        config(['filesystems.served_public_storage_root' => $mirror]);
        $data = (new PartPhotoStorageDiagnostic)->inspect();
        $original = $data['sample_images'][0]['original'];
        $this->assertCount(2, $original['found_paths']);
        $this->assertSame('multiple_physical_copies_not_compared', $original['resolution_status']);
    }

    public function test_remote_disk_cannot_trigger_provider_requests(): void
    {
        config(['filesystems.disks.public.driver' => 's3']);
        Storage::shouldReceive('disk')->never();
        $this->expectException(MigrationExportException::class);
        (new PartPhotoStorageDiagnostic)->inspect();
    }
}
