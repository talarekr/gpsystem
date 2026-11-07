<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\MigrationExport\BrowserMigrationSnapshot;
use App\Services\MigrationExport\ExternalPhotoArchive;
use App\Services\MigrationExport\GpswissMigrationExport;
use App\Services\MigrationExport\MigrationOriginals;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class GpswissExternalPhotoArchiveTest extends TestCase
{
    use RefreshDatabase;

    private const RUN = BrowserMigrationSnapshot::DRIFT_RUN;

    private const BASE = '/admin/tools/gpswiss-migration-export/';

    private string $tmp;

    private string $dir;

    private string $attempt;

    private int $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/external-photos-'.Str::uuid();
        $this->dir = $this->tmp.'/exports/'.self::RUN;
        File::ensureDirectoryExists($this->tmp.'/originals/parts/photos', 0700);
        config(['migration_export.root' => $this->tmp.'/exports', 'migration_export.browser_snapshot_rows' => 5,
            'migration_export.archive_payload_bytes' => 128 * 1024, 'filesystems.disks.public.root' => $this->tmp.'/originals',
            'filesystems.served_public_storage_root' => null]);
        $this->seed(RoleSeeder::class);
        $user = User::create(['name' => 'Owner', 'email' => 'external@example.test', 'password' => 'password']);
        $user->assignRole(UserRole::OwnerAdmin->value);
        $this->owner = $user->id;
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        DB::table('cars')->insert(['id' => 8, 'uuid' => (string) Str::uuid(), 'make' => 'Brand', 'model' => 'Donor', 'main_photo_path' => 'cars/original.jpg']);
        for ($id = 1; $id <= 5; $id++) {
            DB::table('parts')->insert(['id' => $id, 'sku' => '000SKU-'.$id, 'name' => 'Part '.$id, 'status' => $id === 1 ? 'sold' : 'ready',
                'needs_listing' => $id === 2, 'quantity' => 1, 'car_id' => 8, 'code_photo_path' => $id === 1 ? 'parts/code-photos/code.jpg' : null]);
        }
        foreach ([['ebay', '000EBAY'], ['ovoko', '000OVOKO']] as [$market, $offer]) {
            DB::table('marketplace_listings')->insert(['part_id' => 2, 'marketplace' => $market, 'external_offer_id' => $offer, 'status' => 'active']);
        }
        for ($id = 1; $id <= 5; $id++) {
            $path = 'parts/photos/original-'.$id.'.png';
            file_put_contents($this->tmp.'/originals/'.$path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jZQAAAABJRU5ErkJggg=='));
            DB::table('part_images')->insert(['id' => $id, 'part_id' => 1, 'path' => $path, 'sort_order' => $id - 1, 'is_primary' => $id === 1]);
        }
        Http::preventStrayRequests();
        $start = $this->service()->prepareBrowser($this->owner, self::RUN);
        $this->attempt = $start['snapshot_attempt_id'];
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): GpswissMigrationExport
    {
        return app(GpswissMigrationExport::class);
    }

    private function root(?array $state = null): array
    {
        if ($state !== null) {
            file_put_contents($this->dir.'/state.json', json_encode($state));
        }

        return json_decode(file_get_contents($this->dir.'/state.json'), true);
    }

    private function untilPhase(string $phase): array
    {
        for ($n = 0; $n < 1200; $n++) {
            $state = $this->root();
            if ($state['phase'] === $phase) {
                return $state;
            }
            $this->assertNull($state['last_error']);
            if (in_array($state['state'], ['photos', 'archive'], true)) {
                $this->service()->tick($this->owner, self::RUN);
            } else {
                $this->service()->browserSnapshotTick($this->owner, self::RUN, $this->attempt);
            }
        }
        $this->fail('bounded test pipeline did not reach '.$phase);
    }

    private function rows(string $file): array
    {
        return array_map(fn ($s) => json_decode($s, true), file($this->dir.'/'.$file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    public function test_existing_relation_run_preserves_checkpoint_and_finishes_data_and_sample_without_any_original_copies(): void
    {
        $root = $this->untilPhase('relation_audit');
        $capture = $this->dir.'/logical-snapshot/'.$this->attempt.'/checkpoint.json';
        $before = hash_file('sha256', $capture);
        $root['consistency_exception'] = ['accepted_by_operator' => true, 'recorded_ids' => ['1937', '2262']];
        $root['exported_records'] = $root['record_capture_total'] = 79957;
        $root['verification_processed'] = 2155;
        $this->root($root);
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        $selected = $this->service()->useExternalPhotos($this->owner, self::RUN, $this->attempt);
        $this->assertSame('external_archive', $selected['photo_delivery']['mode']);
        $this->assertSame('relation_audit', $selected['phase']);
        $this->assertSame($before, hash_file('sha256', $capture));
        $again = $this->root();
        $this->service()->useExternalPhotos($this->owner, self::RUN, $this->attempt);
        $this->assertSame($again, $this->root());
        $this->untilPhase('completed');
        $this->assertSame([], $queries);
        $this->assertSame($before, hash_file('sha256', $capture));
        $this->assertSame($root['consistency_exception'], $this->root()['consistency_exception']);
        $this->assertSame(79957, $this->root()['exported_records']);
        $this->assertSame(2155, $this->root()['verification_processed']);
        $this->assertFileDoesNotExist($this->dir.'/images');
        $this->assertFileDoesNotExist($this->dir.'/photos.jsonl');
        $this->assertFileDoesNotExist($this->dir.'/photo-checkpoint.json');
        $photos = $this->rows('photo-manifest.jsonl');
        $this->assertCount(7, $photos); // five gallery images, a code image and donor main photo
        $this->assertSame([0, 1, 2, 3, 4], array_column(array_slice($photos, 0, 5), 'sort_order'));
        $this->assertTrue($photos[0]['is_primary']);
        $this->assertSame('parts/photos/original-1.png', $photos[0]['external_relative_path']);
        foreach ($photos as $row) {
            $this->assertSame(ExternalPhotoArchive::STATUS, $row['status']);
            foreach (['package_path', 'bytes', 'sha256', 'mime'] as $key) {
                $this->assertNull($row[$key]);
            }
            $this->assertArrayNotHasKey('source', $row);
            $this->assertSame('not_checked', $row['original_availability']);
        }
        $this->assertSame('', file_get_contents($this->dir.'/missing-originals.jsonl'));
        foreach (['migration-manifest.json', 'sample/manifest.json', 'migration-package-manifest.json'] as $path) {
            $manifest = json_decode(file_get_contents($this->dir.'/'.$path), true);
            $this->assertSame('external_archive', $manifest['photo_delivery']['mode']);
            $this->assertFalse($manifest['photo_delivery']['originals_included']);
        }
        $catalog = json_decode(file_get_contents($this->dir.'/migration-package-manifest.json'), true);
        $this->assertFalse($catalog['originals_complete']);
        $this->assertTrue($catalog['technical_package_ready']);
        $sample = json_decode(file_get_contents($this->dir.'/sample/manifest.json'), true);
        $this->assertSame('1', $sample['case_selection']['multiple_photos']);
        $this->assertCount(5, $sample['sample_source_ids']);
        foreach (['archive-input.jsonl', 'sample-archive-input.jsonl'] as $path) {
            foreach ($this->rows($path) as $file) {
                $this->assertFalse(str_starts_with($file['path'], 'images/'));
                $this->assertSame($file['sha256'], hash_file('sha256', $this->dir.'/'.$file['path']));
            }
        }
        Http::assertNothingSent();
    }

    public function test_switch_after_partial_copy_keeps_existing_files_but_never_copies_or_packages_more_originals(): void
    {
        $this->untilPhase('photos');
        $this->service()->tick($this->owner, self::RUN);
        $before = hash_file('sha256', $this->dir.'/photos.jsonl');
        $copied = File::allFiles($this->dir.'/images');
        $this->assertNotEmpty($copied);
        $files = array_map(fn ($f) => [$f->getPathname(), hash_file('sha256', $f->getPathname())], $copied);
        $this->service()->useExternalPhotos($this->owner, self::RUN, $this->attempt);
        $this->untilPhase('completed');
        $this->assertSame($before, hash_file('sha256', $this->dir.'/photos.jsonl'));
        foreach ($files as [$path, $sha]) {
            $this->assertSame($sha, hash_file('sha256', $path));
        }
        $this->assertCount(count($files), File::allFiles($this->dir.'/images'));
        $this->assertSame([ExternalPhotoArchive::STATUS], array_values(array_unique(array_column($this->rows('photo-manifest.jsonl'), 'status'))));
        $this->assertFalse(str_contains(file_get_contents($this->dir.'/archive-input.jsonl'), '"path":"images/'));
        Http::assertNothingSent();
    }

    public function test_panel_requires_owner_confirmation_and_current_attempt_then_persists_selection_on_reload(): void
    {
        $this->untilPhase('relation_audit');
        $payload = ['action' => 'use_external_photos', 'expected_snapshot_attempt' => $this->attempt];
        $this->postJson(self::BASE.self::RUN, $payload)->assertStatus(422)->assertJsonPath('reason_code', 'external_photo_mode_confirmation_required');
        $payload['confirm_external_photos'] = true;
        $this->postJson(self::BASE.self::RUN, array_replace($payload, ['expected_snapshot_attempt' => (string) Str::uuid()]))
            ->assertStatus(409)->assertJsonPath('reason_code', 'stale_snapshot_attempt');
        $this->postJson(self::BASE.self::RUN, $payload)->assertOk()->assertJsonPath('photo_delivery.mode', 'external_archive');
        $this->getJson(self::BASE.self::RUN.'?format=compact')->assertOk()->assertJsonPath('photo_delivery.mode', 'external_archive')
            ->assertJsonPath('external_photos_allowed', false)->assertJsonPath('snapshot_attempt_id', $this->attempt);
        $this->actingAs(User::create(['name' => 'Not owner', 'email' => 'other@example.test', 'password' => 'password']));
        $this->postJson(self::BASE.self::RUN, $payload)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_paused_run_retains_phase_and_resumes_existing_attempt_with_external_mode(): void
    {
        $this->untilPhase('relation_audit');
        $this->service()->pauseBrowserSnapshot($this->owner, self::RUN);
        $this->service()->useExternalPhotos($this->owner, self::RUN, $this->attempt);
        $this->assertSame('snapshot_paused', $this->root()['state']);
        $this->service()->resume($this->owner, self::RUN, $this->attempt);
        $this->untilPhase('archive_index');
        $this->assertSame($this->attempt, $this->root()['snapshot_attempt_id']);
        $this->assertFileDoesNotExist($this->dir.'/photo-checkpoint.json');
    }

    public function test_packaging_already_started_cannot_change_delivery_mode(): void
    {
        $this->untilPhase('archive_index');
        $before = file_get_contents($this->dir.'/state.json');
        $this->postJson(self::BASE.self::RUN, ['action' => 'use_external_photos', 'expected_snapshot_attempt' => $this->attempt, 'confirm_external_photos' => true])
            ->assertStatus(409)->assertJsonPath('reason_code', 'external_photo_mode_not_allowed');
        $this->assertSame($before, file_get_contents($this->dir.'/state.json'));
    }

    public function test_reference_mode_retains_safe_paths_without_inspecting_missing_originals_and_blocks_unsafe_paths(): void
    {
        $originals = app(MigrationOriginals::class);
        $image = ['id' => '01', 'part_id' => '0002', 'path' => '/storage/parts/photos/not-present.jpg'];
        $task = $originals->task($image, [], false);
        $this->assertSame(['status' => 'REFERENCE_ONLY', 'relative_path' => 'parts/photos/not-present.jpg'], $task['source']);
        $this->assertSame('parts/photos/not-present.jpg', ExternalPhotoArchive::row($task)['external_relative_path']);
        foreach (['../secret', '/etc/passwd', 'parts/../../secret', 'https://example.test/image.jpg'] as $path) {
            $task = $originals->task(array_replace($image, ['path' => $path]), [], false);
            $this->assertNull(ExternalPhotoArchive::row($task)['external_relative_path']);
            $this->assertArrayNotHasKey('copies', $task['source']);
        }
        Http::assertNothingSent();
    }
}
