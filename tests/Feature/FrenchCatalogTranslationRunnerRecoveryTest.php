<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\RunFrenchCatalogTranslationBatch;
use App\Models\Part;
use App\Models\User;
use App\Services\Storefront\FrenchCatalogTranslationAdminRunner;
use App\Services\Storefront\FrenchCatalogTranslationPreviewService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FrenchCatalogTranslationRunnerRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config([
            'storefront-translations.fr_enabled' => true,
            'services.google_translate.enabled' => true,
            'services.google_translate.mode' => 'dry_run',
            'services.google_translate.key' => 'recovery-test-key',
        ]);
        Queue::fake();
        Http::preventStrayRequests();
        Role::findOrCreate(UserRole::OwnerAdmin->value, 'web');
        $this->actor = User::create([
            'name' => 'Recovery Admin', 'email' => 'fr-recovery@example.test', 'password' => 'password',
        ]);
        $this->actor->assignRole(UserRole::OwnerAdmin->value);
    }

    public static function controlsDuringProviderCall(): array
    {
        return ['pause' => ['pause', 'paused'], 'stop' => ['stop', 'stopped']];
    }

    #[DataProvider('controlsDuringProviderCall')]
    public function test_in_flight_result_preserves_control_and_does_not_translate_next_record(string $action, string $status): void
    {
        $first = $this->part('FIRST');
        $second = $this->part('SECOND');
        $runner = app(FrenchCatalogTranslationAdminRunner::class);
        $runId = $this->start();
        Http::fake(['translation.googleapis.com/*' => function () use ($runner, $runId, $action) {
            $result = $runner->control($runId, $action);
            $this->assertTrue($result['ok']);

            return Http::response(['data' => ['translations' => [['translatedText' => 'Moteur']]]]);
        }]);

        $runner->runBatch($runId);

        $progress = $runner->status();
        $this->assertSame($status, $progress['status']);
        $this->assertSame(1, $progress['processed']);
        $this->assertSame(1, $progress['translated']);
        $this->assertSame(1, $progress['remaining']);
        $this->assertNull($progress['next_batch']);
        $this->assertDatabaseHas('part_translations', ['part_id' => $first->id, 'status' => 'translated']);
        $this->assertDatabaseMissing('part_translations', ['part_id' => $second->id]);
        Http::assertSentCount(1);
        Queue::assertPushed(RunFrenchCatalogTranslationBatch::class, 1);
    }

    public function test_new_start_cannot_replace_terminal_run_while_old_worker_holds_processing_lock(): void
    {
        $this->part('LEASE');
        $runner = app(FrenchCatalogTranslationAdminRunner::class);
        $runId = $this->start();
        $this->assertTrue($runner->control($runId, 'stop')['ok']);
        $preview = app(FrenchCatalogTranslationPreviewService::class)->dryRun($this->actor->id);
        $lock = Cache::lock(FrenchCatalogTranslationAdminRunner::PROCESSING_LOCK, 600);
        $this->assertTrue($lock->get());
        try {
            $result = $runner->start($this->actor->id, $preview['dry_run_id'], FrenchCatalogTranslationAdminRunner::CONFIRM);

            $this->assertFalse($result['ok']);
            $this->assertSame('busy', $result['reason']);
            $this->assertSame($runId, $runner->status()['run_id']);
            $this->assertSame('stopped', $runner->status()['status']);
            $this->assertFalse(app(FrenchCatalogTranslationPreviewService::class)->saved($this->actor->id)['consumed']);
            Queue::assertPushed(RunFrenchCatalogTranslationBatch::class, 1);
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_recovery_counts_persisted_success_once_without_another_provider_request(): void
    {
        $part = $this->part('RECOVERED');
        $runId = $this->start();
        $this->checkpoint($part);
        $part->translations()->create([
            'locale' => 'fr', 'name' => 'Moteur', 'status' => 'translated', 'attempts' => 1,
            'source_hash' => $part->translationSourceHash(), 'translated_at' => now(),
        ]);
        $runner = app(FrenchCatalogTranslationAdminRunner::class);

        $runner->runBatch($runId);
        $progress = $runner->status();
        $this->assertSame('completed', $progress['status']);
        $this->assertSame(1, $progress['processed']);
        $this->assertSame(1, $progress['translated']);
        $this->assertSame(0, $progress['remaining']);
        $this->assertSame(mb_strlen($part->name), $progress['chars_translated']);
        $this->assertSame($part->id, $progress['last_success']['part_id']);
        $runner->runBatch($runId);
        $this->assertSame($progress, $runner->status());
        $this->assertSame(1, $part->translations()->first()->attempts);
        Http::assertNothingSent();
    }

    public function test_uncertain_queued_attempt_is_failed_and_resume_never_repeats_its_provider_call(): void
    {
        $part = $this->part('UNCERTAIN');
        $runId = $this->start();
        $this->checkpoint($part);
        $part->translations()->create([
            'locale' => 'fr', 'status' => 'queued', 'attempts' => 1, 'source_hash' => $part->translationSourceHash(),
        ]);
        $runner = app(FrenchCatalogTranslationAdminRunner::class);

        $runner->runBatch($runId);

        $progress = $runner->status();
        $this->assertSame('stopped_on_error', $progress['status']);
        $this->assertSame(1, $progress['failed']);
        $this->assertSame(1, $progress['processed']);
        $this->assertSame(0, $progress['remaining']);
        $this->assertSame('interrupted_attempt', $progress['last_error']['error_code']);
        $this->assertSame($part->sku, $progress['failed_examples'][0]['sku']);
        $this->assertDatabaseHas('part_translations', [
            'part_id' => $part->id, 'status' => 'failed', 'attempts' => 1, 'error_code' => 'interrupted_attempt',
        ]);
        $this->assertTrue($runner->control($runId, 'resume')['ok']);
        $runner->runBatch($runId);
        $this->assertSame('completed', $runner->status()['status']);
        $this->assertSame(1, $runner->status()['processed']);
        $this->assertSame(1, $part->translations()->first()->attempts);
        Http::assertNothingSent();
    }

    public function test_recovery_conditional_write_preserves_review_added_after_reading_queued_attempt(): void
    {
        $part = $this->part('REVIEWED');
        $runId = $this->start();
        $this->checkpoint($part);
        $translation = $part->translations()->create([
            'locale' => 'fr', 'status' => 'queued', 'attempts' => 1, 'source_hash' => $part->translationSourceHash(),
        ]);
        $reviewed = false;
        DB::listen(function ($query) use ($translation, &$reviewed): void {
            if (! $reviewed && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, '"part_translations"')) {
                // The SELECT has returned queued data; the runner has not issued its recovery UPDATE yet.
                $reviewed = true;
                DB::table('part_translations')->where('id', $translation->id)->update([
                    'status' => 'reviewed', 'name' => 'Texte relu', 'reviewed_at' => now(),
                ]);
            }
        });

        app(FrenchCatalogTranslationAdminRunner::class)->runBatch($runId);

        $this->assertTrue($reviewed);
        $this->assertSame('reviewed', $translation->fresh()->status);
        $this->assertSame('Texte relu', $translation->fresh()->name);
        $this->assertNull($translation->fresh()->error_code);
        $progress = app(FrenchCatalogTranslationAdminRunner::class)->status();
        $this->assertSame('completed', $progress['status']);
        $this->assertSame(1, $progress['skipped']);
        $this->assertSame(0, $progress['failed']);
        Http::assertNothingSent();
    }

    public function test_busy_record_lock_defers_work_and_preserves_cursor_until_the_same_record_finishes(): void
    {
        $part = $this->part('BUSY');
        $runId = $this->start();
        $runner = app(FrenchCatalogTranslationAdminRunner::class);
        $lock = Cache::lock('storefront:fr:parts:'.$part->id, 600);
        $this->assertTrue($lock->get());
        try {
            $runner->runBatch($runId);

            $state = Cache::get(FrenchCatalogTranslationAdminRunner::KEY);
            $this->assertSame('running', $state['status']);
            $this->assertSame(0, $state['processed']);
            $this->assertSame(0, $state['_cursor']['parts']);
            $this->assertSame($part->id, $state['_pending']['id']);
            $this->assertFalse($state['_pending']['in_flight']);
            $this->assertSame(now()->addSeconds(30)->timestamp, Carbon::parse($state['next_batch'])->timestamp);
            Queue::assertPushed(RunFrenchCatalogTranslationBatch::class, 2);
            $this->assertDatabaseCount('part_translations', 0);
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
        Http::fake(['translation.googleapis.com/*' => Http::response([
            'data' => ['translations' => [['translatedText' => 'Moteur']]],
        ])]);
        $this->travel(30)->seconds();

        $runner->runBatch($runId);

        $this->assertSame('completed', $runner->status()['status']);
        $this->assertSame(1, $runner->status()['processed']);
        $this->assertSame(1, $runner->status()['translated']);
        $this->assertSame(1, $part->translations()->first()->attempts);
        Http::assertSentCount(1);
    }

    public function test_deleted_snapshot_records_count_as_skipped_without_changing_total(): void
    {
        $first = $this->part('DELETED-FIRST');
        $second = $this->part('DELETED-SECOND');
        $runId = $this->start();
        $first->delete();
        $second->delete();

        app(FrenchCatalogTranslationAdminRunner::class)->runBatch($runId);

        $progress = app(FrenchCatalogTranslationAdminRunner::class)->status();
        $this->assertSame('completed', $progress['status']);
        $this->assertSame(2, $progress['total']);
        $this->assertSame(2, $progress['processed']);
        $this->assertSame(2, $progress['skipped']);
        $this->assertSame(0, $progress['remaining']);
        $this->assertSame(0, $progress['translated']);
        Http::assertNothingSent();
    }

    public function test_disabling_flag_during_active_run_stops_before_any_provider_call(): void
    {
        $this->part('FLAG');
        $runId = $this->start();
        config(['storefront-translations.fr_enabled' => false]);

        app(FrenchCatalogTranslationAdminRunner::class)->runBatch($runId);

        $progress = app(FrenchCatalogTranslationAdminRunner::class)->status();
        $this->assertSame('stopped_on_error', $progress['status']);
        $this->assertSame('translations_disabled', $progress['last_error']['error_code']);
        $this->assertSame(0, $progress['processed']);
        $this->assertSame(1, $progress['remaining']);
        $this->assertNull($progress['next_batch']);
        $this->assertDatabaseCount('part_translations', 0);
        Queue::assertPushed(RunFrenchCatalogTranslationBatch::class, 1);
        Http::assertNothingSent();
    }

    private function part(string $sku): Part
    {
        return Part::create([
            'name' => 'Silnik', 'sku' => 'FR-RECOVERY-'.$sku, 'slug' => 'fr-recovery-'.strtolower($sku),
            'price' => 100, 'quantity' => 2, 'status' => 'ready', 'needs_listing' => false, 'needs_review' => false,
        ]);
    }

    private function start(): string
    {
        $preview = app(FrenchCatalogTranslationPreviewService::class)->dryRun($this->actor->id);
        $result = app(FrenchCatalogTranslationAdminRunner::class)->start(
            $this->actor->id, $preview['dry_run_id'], FrenchCatalogTranslationAdminRunner::CONFIRM,
        );
        $this->assertTrue($result['ok'], json_encode($result));

        return $result['run_id'];
    }

    private function checkpoint(Part $part): void
    {
        $state = Cache::get(FrenchCatalogTranslationAdminRunner::KEY);
        $state['_pending'] = [
            'type' => 'parts', 'id' => $part->id, 'sku' => $part->sku, 'retry_attempt' => 0, 'retry_at' => null,
            'source_hash' => $part->translationSourceHash(), 'in_flight' => true, 'baseline_attempts' => 0,
            'chars_translated' => 0, 'estimated_characters' => mb_strlen($part->name),
        ];
        $state['current_type'] = 'parts';
        $state['current_id'] = $part->id;
        Cache::forever(FrenchCatalogTranslationAdminRunner::KEY, $state);
    }
}
