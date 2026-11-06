<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\MigrationExport\GpswissMigrationExport;
use App\Services\MigrationExport\MigrationExportException;
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
use RuntimeException;
use Tests\TestCase;

/** Tests the production CSRF middleware rather than its normal PHPUnit shortcut. */
class VerifySnapshotRecoveryCsrf extends ValidateCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}

class GpswissSnapshotRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/admin/tools/gpswiss-migration-export';

    private const RUN = '8de6e27c-ad7c-470b-830b-b9ecee9446ba';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jZQAAAABJRU5ErkJggg==';

    private string $temporary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporary = sys_get_temp_dir().'/gps-snapshot-recovery-'.Str::uuid();
        mkdir($this->temporary.'/originals', 0700, true);
        config(['migration_export.root' => $this->temporary.'/exports', 'migration_export.php_cli' => null,
            'filesystems.disks.public.root' => $this->temporary.'/originals', 'filesystems.served_public_storage_root' => null]);
        Http::preventStrayRequests();
        Http::fake(fn () => throw new LogicException('Provider calls forbidden'));
        Bus::fake();
        DB::table('parts')->insert(['id' => 1, 'sku' => '0000123', 'name' => 'Fresh DB product', 'quantity' => 1, 'status' => 'ready']);
        file_put_contents($this->temporary.'/originals/a.png', base64_decode(self::PNG));
        DB::table('part_images')->insert(['id' => 1, 'part_id' => 1, 'path' => 'a.png', 'sort_order' => 0, 'is_primary' => true]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporary);
        parent::tearDown();
    }

    private function file(string $name, string $id = self::RUN): string
    {
        return $this->temporary.'/exports/'.$id.'/'.$name;
    }

    private function owner(): User
    {
        $this->seed(RoleSeeder::class);
        $user = User::create(['name' => 'Snapshot owner', 'email' => 'snapshot-recovery@example.test', 'password' => 'password']);
        $user->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }

    private function interrupted(int $owner = 1): array
    {
        mkdir(dirname($this->file('state.json')), 0700, true);
        $state = ['schema_version' => 'gpswiss-wystawczesc-2', 'contains_customer_data' => true,
            'run_id' => self::RUN, 'owner_id' => $owner, 'state' => 'snapshot', 'processed_images' => 0,
            'photo_results' => [], 'last_error' => null, 'snapshot_attempt_id' => (string) Str::uuid()];
        file_put_contents($this->file('owner.json'), json_encode(['owner_id' => $owner, 'run_id' => self::RUN]));
        file_put_contents($this->file('state.json'), json_encode($state));

        return $state;
    }

    private function blocked(callable $action): void
    {
        try {
            $action();
            $this->fail('Unsafe snapshot recovery was accepted');
        } catch (MigrationExportException $e) {
            $this->assertSame(409, $e->httpStatus);
        }
    }

    private function finish(int $owner, string $id): array
    {
        $service = app(GpswissMigrationExport::class);
        for ($i = 0; $i < 12; $i++) {
            $state = $service->tick($owner, $id);
            $this->assertNull($state['last_error']);
            if ($state['state'] === 'completed') {
                return $state;
            }
        }
        $this->fail('Recovered export did not complete');
    }

    public function test_legacy_interrupted_snapshot_is_discovered_with_explicit_safe_retry(): void
    {
        $owner = $this->owner();
        $this->interrupted($owner->id);
        $data = $this->getJson(self::BASE.'?format=status')->assertOk()->assertJsonPath('run_id', self::RUN)->json();
        $this->assertSame('snapshot_failed', $data['snapshot_state']);
        $this->assertFalse($data['busy']);
        $this->assertFalse($data['snapshot_process_active']);
        $this->assertTrue($data['snapshot_retry_allowed']);
        $this->assertTrue($data['snapshot_abort_allowed']);
        $this->assertFalse($data['snapshot_file_exists']);
        $this->assertFalse($data['snapshot_tasks_file_exists']);
        $this->assertSame(0, $data['processed']);
        $this->assertFileDoesNotExist($this->file('snapshot.json'));
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_retry_discards_partial_temps_and_captures_fresh_database_in_same_uuid_without_business_writes(): void
    {
        $old = $this->interrupted();
        foreach (['snapshot.json.tmp', 'photo-tasks.json.tmp', 'snapshot-ready.json.tmp'] as $name) {
            file_put_contents($this->file($name), '{"stale_partial":"NEVER_REUSE"');
        }
        $mutations = [];
        DB::listen(function ($event) use (&$mutations): void {
            if (preg_match('/^\s*(insert|update|delete)/i', $event->sql)) {
                $mutations[] = $event->sql;
            }
        });
        $service = app(GpswissMigrationExport::class);
        $pending = $service->retrySnapshot(1, self::RUN, $old['snapshot_attempt_id']);
        $this->assertSame(self::RUN, $pending['run_id']);
        $this->assertSame('snapshot_pending', $pending['snapshot_state']);
        $this->assertNotSame($old['snapshot_attempt_id'], $pending['snapshot_attempt_id']);
        foreach (['snapshot.json.tmp', 'photo-tasks.json.tmp', 'snapshot-ready.json.tmp'] as $name) {
            $this->assertFileDoesNotExist($this->file($name));
        }
        $captured = $service->runSnapshot(1, self::RUN, $pending['snapshot_attempt_id']);
        $this->assertSame('photos', $captured['state']);
        $this->assertSame('snapshot_completed', $captured['snapshot_state']);
        $this->assertFileExists($this->file('snapshot-ready.json'));
        $snapshot = json_decode(file_get_contents($this->file('snapshot.json')), true);
        $this->assertSame('Fresh DB product', $snapshot['products'][0]['name']);
        $this->assertSame('0000123', $snapshot['products'][0]['sku']);
        $this->assertStringNotContainsString('NEVER_REUSE', file_get_contents($this->file('snapshot.json')));
        $this->assertSame(self::RUN, $this->finish(1, self::RUN)['run_id']);
        $this->assertSame([], $mutations);
        $this->assertSame(1, DB::table('parts')->count());
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_http_start_prepares_browser_snapshot_without_business_read_or_worker(): void
    {
        $owner = $this->owner();
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        $this->postJson(self::BASE.'/'.self::RUN, ['action' => 'start'])->assertOk()
            ->assertJsonPath('snapshot_state', 'snapshot_pending')->assertJsonPath('snapshot_mode', 'browser')->assertJsonPath('next_action', 'snapshot_tick');
        $businessReads = array_filter($queries, fn ($sql) => preg_match('/\b(from|join)\s+["`]?parts["`]?\b/i', $sql));
        $this->assertSame([], array_values($businessReads));
        $this->assertFileExists($this->file('state.json'));
        $this->assertFileDoesNotExist($this->file('snapshot.json'));
        $this->assertSame($owner->id, json_decode(file_get_contents($this->file('owner.json')), true)['owner_id']);
        $status = app(GpswissMigrationExport::class)->status($owner->id, self::RUN);
        $this->assertSame('browser', $status['snapshot_mode']);
        $this->assertSame('snapshot_tick', $status['next_action']);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_optional_legacy_cli_snapshot_remains_available_outside_http(): void
    {
        $service = app(GpswissMigrationExport::class);
        $status = $service->prepare(1, self::RUN);
        $this->assertSame('php artisan gps:migration-snapshot --owner-id=1 --run='.self::RUN.' --attempt='.$status['snapshot_attempt_id'], $status['snapshot_manual_command']);
        $this->artisan('gps:migration-snapshot', ['--owner-id' => 1, '--run' => self::RUN, '--attempt' => $status['snapshot_attempt_id']])->assertExitCode(0);
        $captured = $service->status(1, self::RUN);
        $this->assertSame('snapshot_completed', $captured['snapshot_state']);
        $this->assertSame('photos', $captured['state']);
        $this->assertSame(0, $captured['processed']);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_stale_duplicate_retry_and_live_snapshot_lock_cannot_start_competing_attempt(): void
    {
        $old = $this->interrupted();
        $service = app(GpswissMigrationExport::class);
        $pending = $service->retrySnapshot(1, self::RUN, $old['snapshot_attempt_id']);
        $this->blocked(fn () => $service->retrySnapshot(1, self::RUN, $old['snapshot_attempt_id']));
        $this->assertSame($pending['snapshot_attempt_id'], $service->status(1, self::RUN)['snapshot_attempt_id']);
        $lock = fopen($this->file('lock'), 'c');
        flock($lock, LOCK_EX);
        try {
            $this->assertTrue($service->status(1, self::RUN)['snapshot_process_active']);
            $this->blocked(fn () => $service->retrySnapshot(1, self::RUN, $pending['snapshot_attempt_id']));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->assertSame($pending['snapshot_attempt_id'], $service->status(1, self::RUN)['snapshot_attempt_id']);
        Http::assertNothingSent();
    }

    public function test_final_artifact_blocks_retry_and_explicit_abort_retains_files_then_allows_conscious_new_run(): void
    {
        $old = $this->interrupted();
        file_put_contents($this->file('snapshot.json'), '{"frozen":"retain verbatim"}');
        $service = app(GpswissMigrationExport::class);
        $status = $service->status(1, self::RUN);
        $this->assertFalse($status['snapshot_retry_allowed']);
        $this->assertTrue($status['snapshot_abort_allowed']);
        $this->blocked(fn () => $service->retrySnapshot(1, self::RUN, $old['snapshot_attempt_id']));
        $aborted = $service->abortSnapshot(1, self::RUN, $old['snapshot_attempt_id']);
        $this->assertSame('snapshot_aborted', $aborted['snapshot_state']);
        $this->assertSame('{"frozen":"retain verbatim"}', file_get_contents($this->file('snapshot.json')));
        $this->assertFalse($service->discover(1)['existing_run_found']);
        $new = (string) Str::uuid();
        $this->assertSame($new, $service->prepare(1, $new)['run_id']);
        $this->assertFileExists($this->file('state.json'));
        Http::assertNothingSent();
    }

    public function test_real_419_on_retry_preserves_checkpoint_and_partial_temp_and_does_not_launch_worker(): void
    {
        $owner = $this->owner();
        $old = $this->interrupted($owner->id);
        file_put_contents($this->file('snapshot.json.tmp'), 'partial');
        $before = file_get_contents($this->file('state.json'));
        $this->app->bind(ValidateCsrfToken::class, VerifySnapshotRecoveryCsrf::class);
        $this->withSession(['_token' => 'new-session']);
        $this->postJson(self::BASE.'/'.self::RUN, ['action' => 'retry_snapshot', 'confirm_restart' => true,
            'expected_snapshot_attempt' => $old['snapshot_attempt_id']], ['X-CSRF-TOKEN' => 'expired'])->assertStatus(419)->assertJsonPath('reason_code', 'SESSION_EXPIRED');
        $this->assertSame($before, file_get_contents($this->file('state.json')));
        $this->assertSame('partial', file_get_contents($this->file('snapshot.json.tmp')));
        $renewed = $this->postJson(self::BASE.'/'.self::RUN, ['action' => 'retry_snapshot', 'confirm_restart' => true,
            'expected_snapshot_attempt' => $old['snapshot_attempt_id']], ['X-CSRF-TOKEN' => 'new-session'])->assertOk()
            ->assertJsonPath('run_id', self::RUN)->assertJsonPath('snapshot_state', 'snapshot_pending')
            ->assertJsonPath('snapshot_mode', 'browser')->assertJsonPath('next_action', 'snapshot_tick')->json();
        $this->assertNotSame($old['snapshot_attempt_id'], $renewed['snapshot_attempt_id']);
        $this->assertFileDoesNotExist($this->file('snapshot.json.tmp'));
        $this->assertFileDoesNotExist($this->file('snapshot.json'));
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_capture_exception_records_safe_failure_and_retry_starts_new_transaction_from_zero(): void
    {
        $service = app(GpswissMigrationExport::class);
        $pending = $service->prepare(1, self::RUN);
        $failOnce = true;
        DB::listen(function ($event) use (&$failOnce): void {
            if ($failOnce && preg_match('/\bfrom\s+["`]?parts["`]?\b/i', $event->sql)) {
                $failOnce = false;
                throw new RuntimeException('SECRET_SQL_CONNECTION_DETAILS');
            }
        });
        $failed = $service->runSnapshot(1, self::RUN, $pending['snapshot_attempt_id']);
        $this->assertSame('snapshot_failed', $failed['snapshot_state']);
        $this->assertTrue($failed['snapshot_retry_allowed']);
        $this->assertStringNotContainsString('SECRET_SQL_CONNECTION_DETAILS', json_encode($failed));
        $this->assertFileDoesNotExist($this->file('snapshot.json'));
        $retried = $service->retrySnapshot(1, self::RUN, $pending['snapshot_attempt_id']);
        $this->assertSame('photos', $service->runSnapshot(1, self::RUN, $retried['snapshot_attempt_id'])['state']);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_valid_atomic_receipt_recovers_crash_before_checkpoint_without_recapturing_database(): void
    {
        $service = app(GpswissMigrationExport::class);
        $pending = $service->prepare(1, self::RUN);
        $service->runSnapshot(1, self::RUN, $pending['snapshot_attempt_id']);
        $snapshotHash = hash_file('sha256', $this->file('snapshot.json'));
        $tasksHash = hash_file('sha256', $this->file('photo-tasks.json'));
        $state = json_decode(file_get_contents($this->file('state.json')), true);
        $state['state'] = 'snapshot_running';
        file_put_contents($this->file('state.json'), json_encode($state));
        $businessReads = [];
        DB::listen(function ($event) use (&$businessReads): void {
            if (preg_match('/\bfrom\s+["`]?parts["`]?\b/i', $event->sql)) {
                $businessReads[] = $event->sql;
            }
        });
        $resumed = $service->resume(1, self::RUN);
        $this->assertSame('photos', $resumed['state']);
        $this->assertSame('snapshot_completed', $resumed['snapshot_state']);
        $this->assertSame($snapshotHash, hash_file('sha256', $this->file('snapshot.json')));
        $this->assertSame($tasksHash, hash_file('sha256', $this->file('photo-tasks.json')));
        $this->assertSame([], $businessReads);
        Http::assertNothingSent();
    }

    public function test_http_retry_and_abort_require_explicit_confirmation_without_altering_checkpoint(): void
    {
        $owner = $this->owner();
        $old = $this->interrupted($owner->id);
        $before = file_get_contents($this->file('state.json'));
        $this->postJson(self::BASE.'/'.self::RUN, ['action' => 'retry_snapshot', 'expected_snapshot_attempt' => $old['snapshot_attempt_id']])
            ->assertStatus(422)->assertJsonPath('reason_code', 'snapshot_restart_confirmation_required');
        $this->postJson(self::BASE.'/'.self::RUN, ['action' => 'abort_snapshot', 'confirm_abort' => false, 'expected_snapshot_attempt' => $old['snapshot_attempt_id']])
            ->assertStatus(422)->assertJsonPath('reason_code', 'snapshot_abort_confirmation_required');
        $this->assertSame($before, file_get_contents($this->file('state.json')));
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_invalid_receipt_never_activates_or_recaptures_a_partially_published_snapshot(): void
    {
        $service = app(GpswissMigrationExport::class);
        $pending = $service->prepare(1, self::RUN);
        $service->runSnapshot(1, self::RUN, $pending['snapshot_attempt_id']);
        $snapshotHash = hash_file('sha256', $this->file('snapshot.json'));
        $receipt = json_decode(file_get_contents($this->file('snapshot-ready.json')), true);
        $receipt['snapshot_sha256'] = str_repeat('0', 64);
        file_put_contents($this->file('snapshot-ready.json'), json_encode($receipt));
        $state = json_decode(file_get_contents($this->file('state.json')), true);
        $state['state'] = 'snapshot_running';
        file_put_contents($this->file('state.json'), json_encode($state));
        $businessReads = [];
        DB::listen(function ($event) use (&$businessReads): void {
            if (preg_match('/\bfrom\s+["`]?parts["`]?\b/i', $event->sql)) {
                $businessReads[] = $event->sql;
            }
        });
        $status = $service->status(1, self::RUN);
        $this->assertFalse($status['resume_allowed']);
        $this->assertFalse($status['snapshot_retry_allowed']);
        $this->assertSame('snapshot_failed', $status['snapshot_state']);
        $this->assertSame($snapshotHash, hash_file('sha256', $this->file('snapshot.json')));
        $this->assertSame([], $businessReads);
        Http::assertNothingSent();
    }

    public function test_tick_promotes_a_verified_receipt_after_crash_and_progresses_without_recapturing(): void
    {
        $service = app(GpswissMigrationExport::class);
        $pending = $service->prepare(1, self::RUN);
        $service->runSnapshot(1, self::RUN, $pending['snapshot_attempt_id']);
        $snapshotHash = hash_file('sha256', $this->file('snapshot.json'));
        $state = json_decode(file_get_contents($this->file('state.json')), true);
        $state['state'] = 'snapshot_running';
        file_put_contents($this->file('state.json'), json_encode($state));
        $businessReads = [];
        DB::listen(function ($event) use (&$businessReads): void {
            if (preg_match('/\bfrom\s+["`]?parts["`]?\b/i', $event->sql)) {
                $businessReads[] = $event->sql;
            }
        });

        $progress = $service->tick(1, self::RUN);

        $this->assertSame('snapshot_completed', $progress['snapshot_state']);
        $this->assertSame(1, $progress['processed']);
        $this->assertSame($snapshotHash, hash_file('sha256', $this->file('snapshot.json')));
        $this->assertSame(self::RUN, $this->finish(1, self::RUN)['run_id']);
        $this->assertSame([], $businessReads);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_worker_timeout_is_safe_to_retry_and_stale_failure_cannot_mark_new_attempt_failed(): void
    {
        $old = $this->interrupted();
        $state = $old;
        $state['state'] = 'snapshot_running';
        file_put_contents($this->file('state.json'), json_encode($state));
        $service = app(GpswissMigrationExport::class);

        $service->snapshotFailure(1, self::RUN, $old['snapshot_attempt_id'], 'snapshot_timeout');

        $failed = $service->status(1, self::RUN);
        $this->assertSame('snapshot_failed', $failed['snapshot_state']);
        $this->assertSame('snapshot_timeout', $failed['last_error']);
        $this->assertTrue($failed['snapshot_retry_allowed']);
        $new = $service->retrySnapshot(1, self::RUN, $old['snapshot_attempt_id']);
        $state = json_decode(file_get_contents($this->file('state.json')), true);
        $state['state'] = 'snapshot_running';
        file_put_contents($this->file('state.json'), json_encode($state));
        $before = file_get_contents($this->file('state.json'));

        $service->snapshotFailure(1, self::RUN, $old['snapshot_attempt_id'], 'snapshot_memory_exhausted');

        $this->assertSame($before, file_get_contents($this->file('state.json')));
        $this->assertSame($new['snapshot_attempt_id'], $service->status(1, self::RUN)['snapshot_attempt_id']);
        $service->snapshotFailure(1, self::RUN, $new['snapshot_attempt_id'], 'snapshot_memory_exhausted');
        $this->assertSame('snapshot_memory_exhausted', $service->status(1, self::RUN)['last_error']);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_worker_with_different_source_context_fails_before_business_read(): void
    {
        $service = app(GpswissMigrationExport::class);
        $pending = $service->prepare(1, self::RUN);
        $state = json_decode(file_get_contents($this->file('state.json')), true);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $state['snapshot_source_fingerprint']);
        $state['snapshot_source_fingerprint'] = str_repeat('0', 64);
        file_put_contents($this->file('state.json'), json_encode($state));
        $businessReads = [];
        DB::listen(function ($event) use (&$businessReads): void {
            if (preg_match('/\bfrom\s+["`]?parts["`]?\b/i', $event->sql)) {
                $businessReads[] = $event->sql;
            }
        });

        $failed = $service->runSnapshot(1, self::RUN, $pending['snapshot_attempt_id']);

        $this->assertSame('snapshot_failed', $failed['snapshot_state']);
        $this->assertSame('snapshot_source_context_mismatch', $failed['last_error']);
        $this->assertSame([], $businessReads);
        $this->assertFileDoesNotExist($this->file('snapshot.json'));
        $this->assertFileDoesNotExist($this->file('photo-tasks.json'));
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }
}
