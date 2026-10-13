<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\RunFrenchCatalogTranslationBatch;
use App\Models\Part;
use App\Models\User;
use App\Services\Storefront\FrenchCatalogTranslationAdminRunner as Runner;
use App\Services\Storefront\FrenchCatalogTranslationPreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
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
