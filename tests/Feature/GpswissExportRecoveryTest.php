<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
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

/** Force real CSRF verification in this test only; production protection is never bypassed. */
class VerifyMigrationExportCsrf extends ValidateCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}

class GpswissExportRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private string $temporary;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jZQAAAABJRU5ErkJggg==';

    private const BASE = '/admin/tools/gpswiss-migration-export';

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporary = sys_get_temp_dir().'/gps-recovery-'.Str::uuid();
        mkdir($this->temporary.'/originals', 0700, true);
        config(['migration_export.root' => $this->temporary.'/exports', 'filesystems.disks.public.root' => $this->temporary.'/originals', 'filesystems.served_public_storage_root' => null]);
        Http::preventStrayRequests();
        Http::fake(fn () => throw new LogicException('No provider calls'));
        Bus::fake();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporary);
        parent::tearDown();
    }

    private function owner(): User
    {
        $this->seed(RoleSeeder::class);
        $user = User::create(['name' => 'Export owner', 'email' => 'recovery@example.test', 'password' => 'password']);
        $user->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }

    private function fixture(int $imageCount = 25): void
    {
        for ($part = 1; $part <= 6; $part++) {
            DB::table('parts')->insert(['id' => $part, 'sku' => 'RECOVERY-'.$part, 'name' => 'Recovery '.$part, 'status' => 'ready', 'quantity' => 1]);
        }
        file_put_contents($this->temporary.'/originals/a.png', base64_decode(self::PNG));
        for ($image = 1; $image <= $imageCount; $image++) {
            DB::table('part_images')->insert(['id' => $image, 'part_id' => 1, 'path' => 'a.png', 'sort_order' => $image - 1, 'is_primary' => $image === 1]);
        }
    }

    private function file(string $id, string $name): string
    {
        return $this->temporary.'/exports/'.$id.'/'.$name;
    }

    private function finish(int $owner, string $id): array
    {
        $service = app(GpswissMigrationExport::class);
        for ($i = 0; $i < 12; $i++) {
            $status = $service->tick($owner, $id);
            $this->assertNull($status['last_error']);
            if ($status['state'] === 'completed') {
                return $status;
            }
        }
        $this->fail('Existing export did not complete');
    }

    public function test_419_before_start_explains_phantom_uuid_and_real_csrf_remains_enabled(): void
    {
        $this->fixture();
        $this->owner();
        $this->app->bind(ValidateCsrfToken::class, VerifyMigrationExportCsrf::class);
        $id = (string) Str::uuid(); // old JS writes this hash BEFORE its rejected POST
        $this->withSession(['_token' => 'current-session-token']);
        $this->postJson(self::BASE.'/'.$id, ['action' => 'start'])->assertStatus(419)->assertJsonPath('reason_code', 'SESSION_EXPIRED');
        $this->assertFileDoesNotExist($this->file($id, 'state.json'));
        $this->getJson(self::BASE.'/'.$id)->assertNotFound()->assertJsonPath('reason_code', 'run_not_found');
        $this->getJson(self::BASE.'?format=status')->assertOk()->assertJsonPath('existing_run_found', false);
        $this->assertCount(0, glob($this->temporary.'/exports/*/state.json') ?: []);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_expired_csrf_after_batches_preserves_checkpoint_then_login_reload_discovers_and_finishes_same_run(): void
    {
        $this->fixture();
        $owner = $this->owner();
        $service = app(GpswissMigrationExport::class);
        $id = $service->start($owner->id)['run_id'];
        $first = $service->tick($owner->id, $id);
        $this->assertGreaterThan(0, $first['processed']);
        $this->assertGreaterThan(0, $first['remaining']);
        $snapshotHash = hash_file('sha256', $this->file($id, 'snapshot.json'));
        $checkpoint = file_get_contents($this->file($id, 'state.json'));
        $this->app->bind(ValidateCsrfToken::class, VerifyMigrationExportCsrf::class);
        $this->withSession(['_token' => 'renewed-session-token']);
        $this->postJson(self::BASE.'/'.$id, ['action' => 'tick'], ['X-CSRF-TOKEN' => 'old-expired-token'])->assertStatus(419)->assertJsonPath('reason_code', 'SESSION_EXPIRED');
        $this->assertSame($checkpoint, file_get_contents($this->file($id, 'state.json')));
        $found = $this->getJson(self::BASE.'?format=status')->assertOk()->assertJsonPath('existing_run_found', true)->assertJsonPath('run_id', $id)->json();
        $this->assertTrue($found['resume_allowed']);
        $this->assertSame($first['processed'], $found['processed']);
        foreach (['phase', 'processed', 'remaining', 'current_item', 'last_success', 'last_error', 'owner_match', 'artifacts_present', 'blocked_reason'] as $key) {
            $this->assertArrayHasKey($key, $found);
        }
        $this->postJson(self::BASE.'/'.$id, ['action' => 'resume'], ['X-CSRF-TOKEN' => 'renewed-session-token'])->assertOk()->assertJsonPath('run_id', $id)->assertJsonPath('processed', $first['processed']);
        $this->postJson(self::BASE.'/'.$id, ['action' => 'tick'], ['X-CSRF-TOKEN' => 'renewed-session-token'])->assertOk()->assertJsonPath('run_id', $id);
        $end = $this->finish($owner->id, $id);
        $this->assertSame(25, $end['processed']);
        $this->assertSame(0, $end['remaining']);
        $this->assertSame($snapshotHash, hash_file('sha256', $this->file($id, 'snapshot.json')));
        $this->assertCount(1, glob($this->temporary.'/exports/*/state.json'));
        $lines = array_filter(file($this->file($id, 'photos.jsonl'), FILE_IGNORE_NEW_LINES));
        $this->assertCount(25, $lines);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_another_start_is_blocked_while_existing_owner_export_can_resume_without_business_writes(): void
    {
        $this->fixture();
        $owner = $this->owner();
        $service = app(GpswissMigrationExport::class);
        $first = $service->start($owner->id);
        $second = (string) Str::uuid();
        $mutations = [];
        DB::listen(function ($event) use (&$mutations): void {
            if (preg_match('/^\s*(insert|update|delete)/i', $event->sql)) {
                $mutations[] = $event->sql;
            }
        });
        $this->postJson(self::BASE.'/'.$second, ['action' => 'start'])->assertStatus(409)->assertJsonPath('reason_code', 'existing_export_unfinished')->assertJsonPath('run_id', $first['run_id']);
        $this->assertFileDoesNotExist($this->file($second, 'state.json'));
        $this->getJson(self::BASE.'?format=status')->assertOk()->assertJsonPath('run_id', $first['run_id']);
        $this->finish($owner->id, $first['run_id']);
        $this->assertSame([], $mutations);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_safe_diagnostics_distinguish_route_run_checkpoint_owner_and_artifact(): void
    {
        $this->fixture();
        $owner = $this->owner();
        $service = app(GpswissMigrationExport::class);
        $id = $service->start($owner->id)['run_id'];
        $this->getJson(self::BASE.'/not-a-uuid')->assertNotFound()->assertJsonPath('reason_code', 'invalid_run_id');
        $this->getJson(self::BASE.'/'.$id.'/unexpected')->assertNotFound()->assertJsonPath('reason_code', 'route_not_found');
        $this->getJson(self::BASE.'/'.Str::uuid())->assertNotFound()->assertJsonPath('reason_code', 'run_not_found');
        $other = User::create(['name' => 'Other', 'email' => 'other-recovery@example.test', 'password' => 'password']);
        $other->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($other);
        $this->getJson(self::BASE.'/'.$id)->assertNotFound()->assertJsonPath('reason_code', 'run_owner_mismatch')->assertJsonMissingPath('source_counts');
        $this->actingAs($owner);
        $this->finish($owner->id, $id);
        unlink($this->file($id, 'full.zip'));
        $this->getJson(self::BASE.'/'.$id.'?download=full')->assertNotFound()->assertJsonPath('reason_code', 'artifact_not_found');
        unlink($this->file($id, 'state.json'));
        $this->getJson(self::BASE.'/'.$id)->assertNotFound()->assertJsonPath('reason_code', 'checkpoint_missing');
        Http::assertNothingSent();
    }

    public function test_completed_run_does_not_reprocess_or_rewrite_valid_metadata(): void
    {
        $this->fixture();
        $service = app(GpswissMigrationExport::class);
        $id = $service->start(1)['run_id'];
        $state = $this->finish(1, $id);
        $this->assertFalse($state['resume_allowed']);
        $this->assertSame('export_already_completed', $state['blocked_reason']);
        $files = [$this->file($id, 'sample/products.json'), $this->file($id, 'full/products.csv'), $this->file($id, 'full.zip')];
        foreach ($files as $file) {
            touch($file, 1000000000);
        }
        $before = array_map(fn ($f) => [hash_file('sha256', $f), filemtime($f)], $files);
        $again = $service->tick(1, $id);
        $this->assertSame('completed', $again['state']);
        clearstatcache();
        $this->assertSame($before, array_map(fn ($f) => [hash_file('sha256', $f), filemtime($f)], $files));
        $this->assertFalse($service->discover(1)['existing_run_found']);
    }

    public function test_photo_journal_replay_reuses_verified_bytes_even_if_cursor_write_was_lost(): void
    {
        $this->fixture();
        $service = app(GpswissMigrationExport::class);
        $id = $service->start(1)['run_id'];
        $service->tick(1, $id);
        $lines = file($this->file($id, 'photos.jsonl'), FILE_IGNORE_NEW_LINES);
        $photo = json_decode($lines[0], true);
        $copied = $this->file($id, $photo['package_path']);
        touch($copied, 1000000000);
        $checkpoint = json_decode(file_get_contents($this->file($id, 'state.json')), true);
        $checkpoint['processed_images'] = 0; // simulate process loss after fs journal append but before cursor replacement
        file_put_contents($this->file($id, 'state.json'), json_encode($checkpoint));
        $end = $this->finish(1, $id);
        clearstatcache(true, $copied);
        $this->assertSame(1000000000, filemtime($copied));
        $this->assertSame(25, $end['processed']);
        $all = array_filter(file($this->file($id, 'photos.jsonl'), FILE_IGNORE_NEW_LINES));
        $this->assertCount(25, $all);
        Http::assertNothingSent();
    }

    public function test_crash_before_completed_checkpoint_reuses_verified_archives_and_metadata(): void
    {
        $this->fixture();
        $service = app(GpswissMigrationExport::class);
        $id = $service->start(1)['run_id'];
        $this->finish(1, $id);
        $photo = json_decode(file($this->file($id, 'photos.jsonl'), FILE_IGNORE_NEW_LINES)[0], true);
        $files = array_map(fn ($path) => $this->file($id, $path), [
            'snapshot.json', 'sample/products.json', 'sample/products.csv',
            'full/products.json', 'full/products.csv', 'sample.zip', 'full.zip', $photo['package_path'],
        ]);
        foreach ($files as $file) {
            touch($file, 1000000000);
        }
        $before = array_map(fn ($file) => [hash_file('sha256', $file), filemtime($file)], $files);
        $checkpoint = json_decode(file_get_contents($this->file($id, 'state.json')), true);
        $checkpoint['state'] = 'archive'; // archives committed, process lost before final checkpoint
        unset($checkpoint['archives']);
        file_put_contents($this->file($id, 'state.json'), json_encode($checkpoint));

        $after = $service->tick(1, $id);

        $this->assertSame('completed', $after['state']);
        $this->assertNull($after['last_error']);
        clearstatcache();
        $this->assertSame($before, array_map(fn ($file) => [hash_file('sha256', $file), filemtime($file)], $files));
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_resume_queues_corrupt_copy_for_repair_without_resetting_cursor_or_recopied_good_photo(): void
    {
        $this->fixture();
        $service = app(GpswissMigrationExport::class);
        $id = $service->start(1)['run_id'];
        $first = $service->tick(1, $id);
        $records = array_map(fn ($line) => json_decode($line, true), file($this->file($id, 'photos.jsonl'), FILE_IGNORE_NEW_LINES));
        $broken = $this->file($id, $records[0]['package_path']);
        $good = $this->file($id, $records[1]['package_path']);
        $snapshotHash = hash_file('sha256', $this->file($id, 'snapshot.json'));
        file_put_contents($broken, 'corrupt copied bytes');
        touch($good, 1000000000);

        $resumed = $service->resume(1, $id);

        $this->assertSame($first['processed'], $resumed['processed']);
        $this->assertSame('verification', $resumed['phase']);
        $this->assertSame($first['processed'], $resumed['verification_remaining']);
        $this->assertNotSame($records[0]['sha256'], hash_file('sha256', $broken));
        $verified = $service->tick(1, $id);
        $this->assertSame($first['processed'], $verified['processed']);
        $this->assertSame($first['remaining'] + 1, $verified['remaining']);
        $checkpoint = json_decode(file_get_contents($this->file($id, 'state.json')), true);
        $this->assertSame([0], $checkpoint['repair_queue']);
        $completed = $this->finish(1, $id);
        $this->assertSame(25, $completed['processed']);
        $this->assertSame(0, $completed['remaining']);
        $this->assertSame($records[0]['sha256'], hash_file('sha256', $broken));
        clearstatcache(true, $good);
        $this->assertSame(1000000000, filemtime($good));
        $this->assertSame($snapshotHash, hash_file('sha256', $this->file($id, 'snapshot.json')));
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_invalid_checkpoint_with_intact_owner_marker_is_discovered_and_blocks_new_export(): void
    {
        $this->fixture();
        $owner = $this->owner();
        $service = app(GpswissMigrationExport::class);
        $id = $service->start($owner->id)['run_id'];
        $checkpoint = $this->file($id, 'state.json');
        $snapshotHash = hash_file('sha256', $this->file($id, 'snapshot.json'));
        file_put_contents($checkpoint, '{torn checkpoint');

        $this->getJson(self::BASE.'?format=status')->assertOk()
            ->assertJsonPath('existing_run_found', true)->assertJsonPath('run_id', $id)
            ->assertJsonPath('state', 'blocked')->assertJsonPath('resume_allowed', false)
            ->assertJsonPath('diagnostic_detail', 'checkpoint_invalid');
        $new = (string) Str::uuid();
        $this->postJson(self::BASE.'/'.$new, ['action' => 'start'])->assertStatus(409)
            ->assertJsonPath('reason_code', 'existing_export_unfinished')->assertJsonPath('run_id', $id);
        $this->assertFileDoesNotExist($this->file($new, 'state.json'));
        $this->assertSame('{torn checkpoint', file_get_contents($checkpoint));
        $this->assertSame($snapshotHash, hash_file('sha256', $this->file($id, 'snapshot.json')));
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_verification_is_bounded_checkpointed_and_repeated_resume_does_not_reset_it(): void
    {
        $this->fixture(61);
        $service = app(GpswissMigrationExport::class);
        $id = $service->start(1)['run_id'];
        $service->tick(1, $id);
        $copied = $service->tick(1, $id);
        $this->assertSame(40, $copied['processed']);
        $resumed = $service->resume(1, $id);
        $this->assertSame('verification', $resumed['phase']);
        $this->assertSame(40, $resumed['verification_remaining']);
        $this->assertSame(0, $resumed['verification_cursor']);
        $snapshotHash = hash_file('sha256', $this->file($id, 'snapshot.json'));
        $journalHash = hash_file('sha256', $this->file($id, 'photos.jsonl'));

        $first = $service->tick(1, $id);

        $this->assertSame(40, $first['processed']);
        $this->assertSame(20, $first['verification_cursor']);
        $this->assertSame(20, $first['verification_remaining']);
        $checkpoint = json_decode(file_get_contents($this->file($id, 'state.json')), true);
        $this->assertSame(20, $checkpoint['verification_cursor']);
        $again = $service->resume(1, $id);
        $this->assertSame(20, $again['verification_cursor']);
        $this->assertSame(20, $again['verification_remaining']);
        $second = $service->tick(1, $id);
        $this->assertSame(40, $second['processed']);
        $this->assertSame(0, $second['verification_remaining']);
        $this->assertSame('photos', $second['phase']);
        $this->assertSame($snapshotHash, hash_file('sha256', $this->file($id, 'snapshot.json')));
        $this->assertSame($journalHash, hash_file('sha256', $this->file($id, 'photos.jsonl')));
        $completed = $this->finish(1, $id);
        $this->assertSame(61, $completed['processed']);
        $this->assertSame(0, $completed['remaining']);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }
}
