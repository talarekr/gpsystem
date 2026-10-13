<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\RunFrenchCatalogTranslationBatch;
use App\Models\Part;
use App\Models\User;
use App\Services\Storefront\FrenchCatalogTranslationAdminRunner as Runner;
use App\Services\Storefront\FrenchCatalogTranslationPreviewService;
use App\Services\Storefront\FrenchCatalogTranslationRunStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FrenchCatalogTranslationBrowserRunnerTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/admin/tools/storefront/fr-translations';

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config([
            'storefront-translations.fr_enabled' => true,
            'storefront-translations.admin_runner' => 'browser',
            'queue.connections.storefront-translations.driver' => 'sync',
            'services.google_translate.enabled' => true,
            'services.google_translate.mode' => 'dry_run',
            'services.google_translate.key' => 'browser-test-key',
        ]);
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(['translation.googleapis.com/*' => Http::response([
            'data' => ['translations' => [['translatedText' => 'Moteur']]],
        ])]);
        Role::findOrCreate(UserRole::OwnerAdmin->value, 'web');
        $this->actor = User::create(['name' => 'Admin', 'email' => 'browser@example.test', 'password' => 'password']);
        $this->actor->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($this->actor);
    }

    public function test_browser_start_and_ticks_advance_one_record_without_queue(): void
    {
        $first = $this->part('FIRST');
        $second = $this->part('SECOND');
        $id = $this->start();
        $this->getJson(self::URL.'/status')->assertJsonPath('processed', 0)->assertJsonPath('runner_mode', 'browser');
        Http::assertNothingSent();
        $this->tick($id)->assertJsonPath('processed', 1)->assertJsonPath('batch_size', 1);
        $this->assertSame($first->id, Cache::get(Runner::KEY)['_cursor']['parts']);
        $this->tick($id)->assertJsonPath('processed', 2);
        $this->assertSame($second->id, Cache::get(Runner::KEY)['_cursor']['parts']);
        $this->tick($id)->assertJsonPath('status', 'completed');
        $this->tick($id)->assertJsonPath('processed', 2);
        Http::assertSentCount(2);
        Queue::assertNothingPushed();
    }

    public function test_pause_resume_stop_preserve_existing_run(): void
    {
        $this->part('CONTROL');
        $id = $this->start();
        $this->postJson(self::URL.'/pause', ['run_id' => $id])->assertOk();
        $this->tick($id)->assertJsonPath('processed', 0)->assertJsonPath('status', 'paused');
        Http::assertNothingSent();
        $this->postJson(self::URL.'/resume', ['run_id' => $id])->assertOk()->assertJsonPath('run_id', $id);
        $this->tick($id)->assertJsonPath('processed', 1);
        $this->postJson(self::URL.'/stop', ['run_id' => $id])->assertOk();
        $this->tick($id)->assertJsonPath('status', 'stopped')->assertJsonPath('processed', 1);
        $this->postJson(self::URL.'/resume', ['run_id' => $id])->assertStatus(409);
        Http::assertSentCount(1);
        Queue::assertNothingPushed();
    }

    public function test_processing_lease_blocks_parallel_tick_without_advancing(): void
    {
        $this->part('LOCK');
        $id = $this->start();
        $lock = Cache::lock(Runner::PROCESSING_LOCK, 600);
        $this->assertTrue($lock->get());
        try {
            $this->tick($id)->assertJsonPath('processed', 0);
            Http::assertNothingSent();
            Queue::assertNothingPushed();
        } finally {
            $lock->release();
        }
        $this->tick($id)->assertJsonPath('processed', 1);
    }

    public function test_tick_protects_translations_added_after_dry_run(): void
    {
        $records = [];
        foreach (['translated', 'reviewed', 'failed', 'needs_update'] as $status) {
            $records[$status] = $this->part(strtoupper($status));
        }
        $id = $this->start();
        foreach ($records as $status => $part) {
            $part->translations()->create([
                'locale' => 'fr', 'name' => 'Protected', 'status' => $status,
                'attempts' => 1, 'source_hash' => $part->translationSourceHash(),
            ]);
        }
        for ($i = 1; $i <= 4; $i++) {
            $this->tick($id)->assertJsonPath('processed', $i)->assertJsonPath('skipped', $i);
        }
        foreach ($records as $status => $part) {
            $this->assertSame($status, $part->translations()->first()->status);
            $this->assertSame(1, $part->translations()->first()->attempts);
        }
        Http::assertNothingSent();
    }

    public function test_resume_migrates_legacy_queue_run_and_old_jobs_cannot_execute(): void
    {
        $this->part('LEGACY');
        $id = $this->start();
        $before = Cache::get(Runner::KEY);
        DB::table(FrenchCatalogTranslationRunStore::TABLE)->delete();
        unset($before['runner_mode']);
        Cache::forever(Runner::KEY, $before);
        $this->postJson(self::URL.'/resume', ['run_id' => $id])->assertOk()
            ->assertJsonPath('run_id', $id)->assertJsonPath('runner_mode', 'browser');
        (new RunFrenchCatalogTranslationBatch($id))->handle(app(Runner::class));
        app(Runner::class)->failed($id);
        Http::assertNothingSent();
        $this->assertSame('running', app(Runner::class)->status()['status']);
        $this->assertSame($before['_cursor'], Cache::get(Runner::KEY)['_cursor']);
        $this->tick($id)->assertJsonPath('processed', 1);
        Queue::assertNothingPushed();
    }

    public function test_retry_waits_for_next_tick_without_sleep_or_queue(): void
    {
        $this->part('RETRY');
        $id = $this->start();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['translation.googleapis.com/*' => Http::sequence()
            ->push([], 429)->push(['data' => ['translations' => [['translatedText' => 'Moteur']]]])]);
        $this->tick($id)->assertJsonPath('processed', 0);
        $this->tick($id)->assertJsonPath('processed', 0);
        Http::assertSentCount(1);
        $this->travel(30)->seconds();
        $this->tick($id)->assertJsonPath('processed', 1)->assertJsonPath('translated', 1);
        Http::assertSentCount(2);
        Queue::assertNothingPushed();
    }

    public function test_tick_requires_admin_and_matching_run(): void
    {
        $this->part('AUTH');
        $id = $this->start();
        $this->postJson(self::URL.'/process-next-batch', ['run_id' => 'stale'])->assertStatus(409);
        $user = User::create(['name' => 'Viewer', 'email' => 'viewer@example.test', 'password' => 'password']);
        $this->actingAs($user)->postJson(self::URL.'/process-next-batch', ['run_id' => $id])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_lost_cache_pointer_and_new_session_recover_same_incomplete_browser_run(): void
    {
        $this->part('DURABLE-FIRST');
        $this->part('DURABLE-SECOND');
        $this->part('DURABLE-THIRD');
        $id = $this->start();
        $this->tick($id)->assertJsonPath('processed', 1);
        $this->tick($id)->assertJsonPath('processed', 2);
        $before = Cache::get(Runner::KEY);
        Cache::forget(Runner::KEY);
        $this->flushSession();
        auth()->forgetGuards();
        $this->getJson(self::URL.'/status')->assertUnauthorized();
        $this->actingAs($this->actor);
        $this->getJson(self::URL.'/status')->assertOk()
            ->assertJsonPath('run_id', $id)->assertJsonPath('processed', 2)
            ->assertJsonPath('runner_mode', 'browser')->assertJsonPath('recovery_available', true)
            ->assertJsonPath('checkpoint_source', 'database');
        $this->assertNull(Cache::get(Runner::KEY)); // GET remains read-only.
        $preview = app(FrenchCatalogTranslationPreviewService::class)->dryRun($this->actor->id);
        $this->postJson(self::URL.'/start', [
            'dry_run_id' => $preview['dry_run_id'], 'confirm' => Runner::CONFIRM,
        ])->assertStatus(409)->assertJsonPath('reason', 'active_run');
        $this->get(self::URL)->assertOk()->assertSee('Znaleziono niedokończony run');
        $this->postJson(self::URL.'/resume', ['run_id' => $id])->assertOk()->assertJsonPath('run_id', $id);
        $this->assertSame($before['_cursor'], Cache::get(Runner::KEY)['_cursor']);
        $this->tick($id)->assertJsonPath('processed', 3)->assertJsonPath('translated', 3);
        Http::assertSentCount(3);
        $this->assertDatabaseCount('storefront_fr_translation_runs', 1);
        Queue::assertNothingPushed();
    }

    public function test_database_checkpoint_wins_over_stale_cache_after_failure(): void
    {
        $this->part('STALE');
        $id = $this->start();
        $stale = Cache::get(Runner::KEY);
        $this->tick($id)->assertJsonPath('processed', 1);
        app(Runner::class)->failed($id, true);
        Cache::forever(Runner::KEY, $stale);
        $this->getJson(self::URL.'/status')->assertJsonPath('status', 'failed')
            ->assertJsonPath('processed', 1)->assertJsonPath('recovery_available', true);
        $this->postJson(self::URL.'/resume', ['run_id' => $id])->assertOk()->assertJsonPath('runner_mode', 'browser');
        $this->tick($id)->assertJsonPath('status', 'completed')->assertJsonPath('processed', 1);
        Http::assertSentCount(1);
    }

    public function test_terminal_run_is_not_resurrected_and_new_run_skips_saved_translations(): void
    {
        $first = $this->part('SAVED');
        $second = $this->part('REVIEWED-LATER');
        $this->part('STILL-MISSING');
        $id = $this->start();
        $this->tick($id)->assertJsonPath('processed', 1);
        $second->translations()->create([
            'locale' => 'fr', 'name' => 'Reviewed', 'status' => 'reviewed',
            'attempts' => 1, 'source_hash' => $second->translationSourceHash(),
        ]);
        $this->postJson(self::URL.'/stop', ['run_id' => $id])->assertOk();
        Cache::forget(Runner::KEY);
        $this->getJson(self::URL.'/status')->assertJsonPath('status', 'stopped')->assertJsonPath('recovery_available', false);
        $this->get(self::URL)->assertSee('Aktualne translated/reviewed nie będą tłumaczone ponownie');
        $new = $this->start();
        $this->assertNotSame($id, $new);
        $this->tick($new)->assertJsonPath('skipped', 1);
        $this->tick($new)->assertJsonPath('skipped', 2);
        $this->tick($new)->assertJsonPath('translated', 1);
        $this->tick($new)->assertJsonPath('status', 'completed');
        Cache::forget(Runner::KEY);
        $this->getJson(self::URL.'/status')->assertJsonPath('run_id', $new)->assertJsonPath('status', 'completed');
        $this->assertSame(1, $first->translations()->first()->attempts);
        $this->assertSame('reviewed', $second->translations()->first()->status);
        Http::assertSentCount(2);
    }

    public function test_no_checkpoint_uses_browser_and_explains_safe_new_start(): void
    {
        $part = $this->part('PREVIOUSLY-SAVED');
        $part->translations()->create([
            'locale' => 'fr', 'name' => 'Moteur', 'status' => 'translated',
            'attempts' => 1, 'source_hash' => $part->translationSourceHash(),
        ]);
        Cache::forget(Runner::KEY);
        $this->getJson(self::URL.'/status')->assertJsonPath('run_id', null)
            ->assertJsonPath('runner_mode', 'browser')->assertJsonPath('checkpoint_source', 'none');
        $this->get(self::URL)->assertOk()->assertSee('Brak checkpointu w bazie/cache')
            ->assertSee('Możesz wykonać nowy dry-run i Start');
        $id = $this->start();
        $this->tick($id)->assertJsonPath('translated', 0)->assertJsonPath('skipped', 1);
        Http::assertNothingSent();
    }

    public function test_durable_pending_success_is_recovered_without_repeating_provider_call(): void
    {
        $part = $this->part('PERSISTED-BEFORE-CRASH');
        $id = $this->start();
        $state = Cache::get(Runner::KEY);
        $state['_pending'] = [
            'type' => 'parts', 'id' => $part->id, 'sku' => $part->sku, 'retry_attempt' => 0, 'retry_at' => null,
            'source_hash' => $part->translationSourceHash(), 'in_flight' => true, 'baseline_attempts' => 0,
            'chars_translated' => 0, 'estimated_characters' => mb_strlen($part->name),
        ];
        app(FrenchCatalogTranslationRunStore::class)->save($state);
        $part->translations()->create([
            'locale' => 'fr', 'name' => 'Moteur', 'status' => 'translated',
            'attempts' => 1, 'source_hash' => $part->translationSourceHash(),
        ]);
        Cache::forget(Runner::KEY);
        $this->postJson(self::URL.'/resume', ['run_id' => $id])->assertOk();
        $this->tick($id)->assertJsonPath('processed', 1)->assertJsonPath('translated', 1);
        $this->tick($id)->assertJsonPath('status', 'completed');
        $this->assertSame(1, $part->translations()->first()->attempts);
        Http::assertNothingSent();
    }

    public function test_interrupted_browser_lease_expires_and_resume_recovers_persisted_result_once(): void
    {
        $this->part('BEFORE-LEASE');
        $pendingPart = $this->part('DURING-LEASE');
        $id = $this->start();
        $this->tick($id)->assertJsonPath('processed', 1);
        $lock = Cache::lock(Runner::PROCESSING_LOCK, Runner::PROCESSING_LEASE_SECONDS);
        $this->assertTrue($lock->get());
        $state = Cache::get(Runner::KEY);
        $state['_lease'] = [
            'owner' => hash('sha256', $lock->owner()), 'started_at' => now()->toISOString(),
            'expires_at' => now()->addSeconds(Runner::PROCESSING_LEASE_SECONDS)->toISOString(),
            'last_heartbeat' => now()->toISOString(), 'released_at' => null,
        ];
        $state['_pending'] = [
            'type' => 'parts', 'id' => $pendingPart->id, 'sku' => $pendingPart->sku, 'retry_attempt' => 0,
            'retry_at' => null, 'source_hash' => $pendingPart->translationSourceHash(), 'in_flight' => true,
            'baseline_attempts' => 0, 'chars_translated' => 0, 'estimated_characters' => mb_strlen($pendingPart->name),
        ];
        app(FrenchCatalogTranslationRunStore::class)->save($state);
        $pendingPart->translations()->create([
            'locale' => 'fr', 'name' => 'Persisted', 'status' => 'translated', 'attempts' => 1,
            'source_hash' => $pendingPart->translationSourceHash(),
        ]);
        $status = $this->getJson(self::URL.'/status')->assertJsonPath('lease_state', 'active')
            ->assertJsonPath('recovery_available', false)->assertJsonPath('runner_mode', 'browser');
        $this->assertNotNull($status->json('lease.last_heartbeat'));
        $this->postJson(self::URL.'/resume', ['run_id' => $id])->assertStatus(409)
            ->assertJsonPath('reason', 'busy')->assertDontSee('Worker');
        $this->tick($id)->assertJsonPath('processed', 1);
        $this->travel(Runner::PROCESSING_LEASE_SECONDS + 1)->seconds();
        $this->getJson(self::URL.'/status')->assertJsonPath('lease_state', 'expired')
            ->assertJsonPath('recovery_available', true)->assertSee('Poprzednia partia');
        $this->postJson(self::URL.'/resume', ['run_id' => $id])->assertOk()->assertJsonPath('run_id', $id);
        $this->tick($id)->assertJsonPath('processed', 2)->assertJsonPath('translated', 2);
        $this->tick($id)->assertJsonPath('status', 'completed');
        $this->assertSame(1, $pendingPart->translations()->first()->attempts);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('storefront_fr_translation_runs', 1);
    }

    public function test_browser_fence_stops_further_provider_calls_after_lease_expiry(): void
    {
        $part = $this->part('LONG-REQUEST');
        $part->update(['description' => 'Additional field']);
        $id = $this->start();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['translation.googleapis.com/*' => function () {
            $this->getJson(self::URL.'/status')->assertJsonPath('lease_state', 'active');
            $this->travel(Runner::PROCESSING_LEASE_SECONDS + 1)->seconds();

            return Http::response(['data' => ['translations' => [['translatedText' => 'Moteur']]]]);
        }]);
        $this->tick($id)->assertJsonPath('status', 'stopped_on_error')->assertJsonPath('processed', 1);
        $this->assertSame('interrupted_attempt', $part->translations()->first()->error_code);
        $this->assertSame('released', app(Runner::class)->status()['lease_state']);
        $this->postJson(self::URL.'/resume', ['run_id' => $id])->assertOk();
        $this->tick($id)->assertJsonPath('status', 'completed');
        Http::assertSentCount(1);
    }

    private function part(string $sku): Part
    {
        return Part::create([
            'name' => 'Silnik', 'sku' => 'BROWSER-'.$sku, 'slug' => 'browser-'.strtolower($sku),
            'price' => 100, 'quantity' => 2, 'status' => 'ready', 'needs_listing' => false, 'needs_review' => false,
        ]);
    }

    private function start(): string
    {
        $preview = app(FrenchCatalogTranslationPreviewService::class)->dryRun($this->actor->id);
        $response = $this->postJson(self::URL.'/start', [
            'dry_run_id' => $preview['dry_run_id'], 'confirm' => Runner::CONFIRM,
        ])->assertOk();

        return $response->json('run_id');
    }

    private function tick(string $id): TestResponse
    {
        return $this->postJson(self::URL.'/process-next-batch', ['run_id' => $id])->assertOk();
    }
}
