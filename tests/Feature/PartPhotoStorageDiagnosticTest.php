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
        DB::connection()->beforeExecuting(fn () => throw new \LogicException('SQL forbidden in diagnostic'));
        $data = (new PartPhotoStorageDiagnostic)->inspect();
        $this->assertSame(['base_path', 'storage_path', 'storage_path_app_public', 'public_path', 'public_path_storage', 'part_images_disk', 'disk_root', 'example'], array_keys($data));
        $this->assertSame(['db_path', 'disk', 'resolved_absolute_path', 'exists'], array_keys($data['example']));
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

    public function test_remote_disk_cannot_trigger_provider_requests(): void
    {
        config(['filesystems.disks.public.driver' => 's3']);
        Storage::shouldReceive('disk')->never();
        $this->expectException(MigrationExportException::class);
        (new PartPhotoStorageDiagnostic)->inspect();
    }
}
