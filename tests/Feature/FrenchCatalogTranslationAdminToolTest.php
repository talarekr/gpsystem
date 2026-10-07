<?php

namespace Tests\Feature;

use App\Jobs\RunFrenchCatalogTranslationBatch;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\User;
use App\Services\Marketplace\GoogleTranslateService;
use App\Services\Storefront\FrenchCatalogTranslationAdminRunner;
use App\Services\Storefront\FrenchCatalogTranslationPreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FrenchCatalogTranslationAdminToolTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/admin/tools/storefront/fr-translations';

    private const CONFIRM = 'TRANSLATE-ALL-GPSWISS-FR-CATALOG';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'storefront-translations.fr_enabled' => true,
            'services.google_translate.enabled' => true,
            'services.google_translate.mode' => 'dry_run',
            'services.google_translate.key' => 'admin-test-key-never-exposed',
        ]);
        Cache::flush();
        Http::preventStrayRequests();
        Queue::fake();
        Sleep::fake();
    }

    private function user(?string $role = 'owner_admin'): User
    {
        static $number = 0;
        $number++;
        $user = User::query()->create(['name' => 'Admin '.$number,
            'email' => 'fr-admin-'.$number.'@example.test', 'password' => 'secret']);
        if ($role !== null) {
            Role::findOrCreate($role, 'web');
            $user->assignRole($role);
        }

        return $user;
    }

    private function part(array $attributes = []): Part
    {
        static $number = 0;
        $number++;

        return Part::query()->create($attributes + ['name' => 'Silnik', 'slug' => 'fr-admin-part-'.$number,
            'sku' => 'FR-ADMIN-'.$number, 'price' => 100, 'quantity' => 2, 'status' => 'ready',
            'needs_listing' => false, 'needs_review' => false]);
    }

    private function category(array $attributes = []): PartCategory
    {
        static $number = 0;
        $number++;

        return PartCategory::query()->create($attributes + ['name' => 'Silniki '.$number,
            'slug' => 'fr-admin-category-'.$number, 'is_visible' => true]);
    }

    private function translation(Part|PartCategory $record, string $status, array $attributes = []): void
    {
        $record->translations()->create($attributes + ['locale' => 'fr', 'name' => 'Nom validé',
            'status' => $status, 'source_hash' => $record->translationSourceHash(),
            'reviewed_at' => $status === 'reviewed' ? now() : null]);
    }

    private function fakeSuccess(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['translation.googleapis.com/*' => fn ($request) => Http::response([
            'data' => ['translations' => [['translatedText' => 'FR '.$request['q']]]],
        ])]);
    }

    private function preview(User $user): array
    {
        return $this->actingAs($user)->postJson(self::URL.'/dry-run')->assertOk()
            ->assertJsonPath('ok', true)->json('dry_run');
    }

    private function start(User $user): array
    {
        $preview = $this->preview($user);

        return $this->actingAs($user)->postJson(self::URL.'/start', [
            'dry_run_id' => $preview['dry_run_id'], 'confirm' => self::CONFIRM,
        ])->assertOk()->assertJsonPath('ok', true)->json();
    }

    private function runner(): FrenchCatalogTranslationAdminRunner
    {
        return app(FrenchCatalogTranslationAdminRunner::class);
    }

    private function snapshot(array $tables): array
    {
        return collect($tables)->mapWithKeys(fn ($table) => [
            $table => DB::table($table)->orderBy('id')->get()->toJson(),
        ])->all();
    }

    private function assertNoCatalogWrites(array $writes): void
    {
        foreach ($writes as $sql) {
            $this->assertDoesNotMatchRegularExpression(
                '/^\s*(?:insert\s+into|update|delete\s+from)\s+["`]*(?:parts|part_categories|part_translations|category_translations|orders|order_items|marketplace_[a-z_]+|ovoko_stock_[a-z_]+)\b/i',
                $sql,
            );
        }
    }

    public static function allowedRoles(): array
    {
        return [['owner_admin'], ['manager']];
    }

    #[DataProvider('allowedRoles')]
    public function test_owner_and_manager_can_open_preview_and_read_status(string $role): void
    {
        $user = $this->user($role);
        $this->part();
        $this->actingAs($user)->get(self::URL)->assertOk();
        $this->preview($user);
        $this->getJson(self::URL.'/status')->assertOk()->assertJsonPath('status', 'idle');
        foreach (['index', 'status', 'dry-run', 'start', 'pause', 'resume', 'stop'] as $action) {
            $route = app('router')->getRoutes()->getByName('admin.tools.storefront.fr-translations.'.$action);
            $this->assertNotNull($route);
            $this->assertContains('admin.panel', $route->gatherMiddleware());
        }
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function deniedRoles(): array
    {
        return [['viewer'], ['warehouse_product_staff'], ['pricing_staff'], [null]];
    }

    #[DataProvider('deniedRoles')]
    public function test_other_roles_cannot_read_or_control_translation_tool(?string $role): void
    {
        $this->actingAs($this->user($role));
        foreach (['', '/status'] as $path) {
            $this->getJson(self::URL.$path)->assertForbidden();
        }
        foreach (['dry-run', 'start', 'pause', 'resume', 'stop'] as $action) {
            $this->postJson(self::URL.'/'.$action, [
                'dry_run_id' => 'untrusted', 'confirm' => self::CONFIRM, 'run_id' => 'untrusted',
            ])->assertForbidden();
        }
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_guest_is_denied_every_tool_endpoint(): void
    {
        foreach (['', '/status'] as $path) {
            $this->getJson(self::URL.$path)->assertUnauthorized();
        }
        foreach (['dry-run', 'start', 'pause', 'resume', 'stop'] as $action) {
            $this->postJson(self::URL.'/'.$action)->assertUnauthorized();
        }
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function publicHosts(): array
    {
        return [['gpswiss.fr'], ['www.gpswiss.fr']];
    }

    #[DataProvider('publicHosts')]
    public function test_public_french_host_denies_even_an_authenticated_owner(string $host): void
    {
        $this->actingAs($this->user());
        foreach (['', '/status'] as $path) {
            $this->getJson('https://'.$host.self::URL.$path)->assertForbidden();
        }
        foreach (['dry-run', 'start', 'pause', 'resume', 'stop'] as $action) {
            $this->postJson('https://'.$host.self::URL.'/'.$action, [
                'dry_run_id' => 'untrusted', 'confirm' => self::CONFIRM, 'run_id' => 'untrusted',
            ])->assertForbidden();
        }
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_dry_run_is_read_only_and_uses_entire_catalog_with_protected_statuses(): void
    {
        $user = $this->user();
        $this->part(['description' => 'Opis', 'short_description' => 'Skrót', 'condition_notes' => 'Rysa',
            'price' => 0, 'quantity' => 0, 'is_visible_storefront' => false]);
        $this->category(['name' => 'Silniki', 'description' => 'Kategorie', 'is_visible' => false]);
        foreach (['reviewed', 'translated', 'failed', 'needs_update'] as $status) {
            $part = $this->part();
            $this->translation($part, $status);
        }
        $stale = $this->category();
        $this->translation($stale, 'translated', ['source_hash' => str_repeat('0', 64)]);
        $tables = ['parts', 'part_categories', 'part_translations', 'category_translations',
            'orders', 'order_items', 'marketplace_accounts', 'marketplace_listings', 'marketplace_sync_logs'];
        $before = $this->snapshot($tables);
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $this->actingAs($user)->get(self::URL)->assertOk()->assertDontSee('admin-test-key-never-exposed');
        $report = $this->preview($user);
        $this->getJson(self::URL.'/status')->assertOk();
        $this->assertSame(5, $report['products']['total']);
        $this->assertSame(1, $report['products']['eligible']);
        $this->assertSame(4, $report['products']['skipped']);
        $this->assertSame(4, $report['products']['fields']);
        $this->assertSame(19, $report['products']['estimated_characters']);
        $this->assertSame(2, $report['categories']['total']);
        $this->assertSame(1, $report['categories']['eligible']);
        $this->assertSame(2, $report['categories']['fields']);
        $this->assertSame(16, $report['categories']['estimated_characters']);
        $this->assertFalse($report['consumed']);
        $this->assertNotEmpty($report['dry_run_id']);
        $this->assertSame($before, $this->snapshot($tables));
        $this->assertNoCatalogWrites($writes);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_dry_run_does_not_resolve_provider_and_works_with_flag_disabled(): void
    {
        config(['storefront-translations.fr_enabled' => false]);
        $this->part();
        $this->category();
        $this->app->bind(GoogleTranslateService::class, fn () => throw new \RuntimeException('Dry-run resolved provider'));
        $report = $this->preview($this->user());
        $this->assertSame(1, $report['products']['eligible']);
        $this->assertSame(1, $report['categories']['eligible']);
        $this->assertDatabaseCount('part_translations', 0);
        $this->assertDatabaseCount('category_translations', 0);
        Http::assertNothingSent();
    }

    public function test_missing_translation_schema_is_reported_without_recreating_it(): void
    {
        Schema::drop('part_translations');
        Schema::drop('category_translations');
        $this->actingAs($this->user())->get(self::URL)->assertOk();
        $this->postJson(self::URL.'/dry-run')->assertUnprocessable();
        $this->assertFalse(Schema::hasTable('part_translations'));
        $this->assertFalse(Schema::hasTable('category_translations'));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_start_requires_saved_dry_run_and_exact_confirmation(): void
    {
        $user = $this->user();
        $this->part();
        $this->actingAs($user)->postJson(self::URL.'/start', ['confirm' => self::CONFIRM])
            ->assertUnprocessable()->assertJsonValidationErrors('dry_run_id');
        $this->postJson(self::URL.'/start', ['dry_run_id' => 'unsaved', 'confirm' => self::CONFIRM])
            ->assertUnprocessable()->assertJsonPath('ok', false);
        $preview = $this->preview($user);
        $this->postJson(self::URL.'/start', ['dry_run_id' => $preview['dry_run_id'], 'confirm' => 'wrong'])
            ->assertUnprocessable()->assertJsonPath('ok', false);
        $this->postJson(self::URL.'/start', ['dry_run_id' => $preview['dry_run_id']])
            ->assertUnprocessable()->assertJsonValidationErrors('confirm');
        $this->assertDatabaseCount('part_translations', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function blockedConfiguration(): array
    {
        return [
            'flag disabled' => ['storefront-translations.fr_enabled', false],
            'provider disabled' => ['services.google_translate.enabled', false],
            'key missing' => ['services.google_translate.key', ''],
            'unsupported provider mode' => ['services.google_translate.mode', 'live'],
        ];
    }

    #[DataProvider('blockedConfiguration')]
    public function test_start_requires_enabled_flag_and_provider_configuration(string $key, mixed $value): void
    {
        $user = $this->user();
        $this->part();
        $preview = $this->preview($user);
        config([$key => $value]);
        $this->postJson(self::URL.'/start', ['dry_run_id' => $preview['dry_run_id'], 'confirm' => self::CONFIRM])
            ->assertUnprocessable()->assertJsonPath('ok', false)->assertDontSee('admin-test-key-never-exposed');
        $this->assertFalse(app(FrenchCatalogTranslationPreviewService::class)->saved($user->id)['consumed']);
        $this->assertDatabaseCount('part_translations', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_saved_preview_is_user_bound_replaced_and_expires_after_one_hour(): void
    {
        $owner = $this->user();
        $manager = $this->user('manager');
        $this->part();
        $first = $this->preview($owner);
        $this->actingAs($manager)->postJson(self::URL.'/start', [
            'dry_run_id' => $first['dry_run_id'], 'confirm' => self::CONFIRM,
        ])->assertUnprocessable()->assertJsonPath('ok', false);
        $second = $this->preview($owner);
        $this->assertNotSame($first['dry_run_id'], $second['dry_run_id']);
        $this->postJson(self::URL.'/start', [
            'dry_run_id' => $first['dry_run_id'], 'confirm' => self::CONFIRM,
        ])->assertUnprocessable()->assertJsonPath('ok', false);
        $this->travel(61)->minutes();
        $this->postJson(self::URL.'/start', [
            'dry_run_id' => $second['dry_run_id'], 'confirm' => self::CONFIRM,
        ])->assertUnprocessable()->assertJsonPath('ok', false);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_start_is_asynchronous_on_separate_translation_connection(): void
    {
        $user = $this->user();
        $this->part();
        $this->category();
        $run = $this->start($user);
        $this->assertSame('running', $run['status']);
        $this->assertSame(0, $run['processed']);
        $this->assertSame(100, $run['batch_size']);
        $this->assertNotEmpty($run['run_id']);
        $this->assertTrue(app(FrenchCatalogTranslationPreviewService::class)->saved($user->id)['consumed']);
        Queue::assertPushed(RunFrenchCatalogTranslationBatch::class, function ($job) use ($run): bool {
            $this->assertSame($run['run_id'], $job->runId);
            $this->assertSame('storefront-translations', $job->connection);
            $this->assertSame('storefront-fr-translations', $job->queue);
            $this->assertTrue($job->afterCommit);
            $this->assertGreaterThan($job->timeout, config('queue.connections.storefront-translations.retry_after'));

            return true;
        });
        Queue::assertPushed(RunFrenchCatalogTranslationBatch::class, 1);
        $this->assertDatabaseCount('part_translations', 0);
        $this->assertDatabaseCount('category_translations', 0);
        Http::assertNothingSent();
    }

    public function test_start_cannot_override_default_selection_or_batch_size(): void
    {
        $user = $this->user();
        $this->part();
        $preview = $this->preview($user);
        foreach (['type' => 'parts', 'chunk' => 1000, 'only_missing' => false,
            'include_failed' => true, 'include_needs_update' => true,
            'options' => ['include_failed' => true]] as $key => $value) {
            $this->postJson(self::URL.'/start', [
                'dry_run_id' => $preview['dry_run_id'], 'confirm' => self::CONFIRM, $key => $value,
            ])->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_active_run_and_consumed_preview_cannot_start_duplicate_workers(): void
    {
        $user = $this->user();
        $this->part();
        $run = $this->start($user);
        $consumed = app(FrenchCatalogTranslationPreviewService::class)->saved($user->id);
        $this->postJson(self::URL.'/start', ['dry_run_id' => $consumed['dry_run_id'], 'confirm' => self::CONFIRM])
            ->assertStatus(409)->assertJsonPath('ok', false);
        $fresh = $this->preview($user);
        $this->postJson(self::URL.'/start', ['dry_run_id' => $fresh['dry_run_id'], 'confirm' => self::CONFIRM])
            ->assertStatus(409)->assertJsonPath('ok', false);
        $this->postJson(self::URL.'/pause', ['run_id' => $run['run_id']])->assertOk();
        $this->postJson(self::URL.'/start', ['dry_run_id' => $fresh['dry_run_id'], 'confirm' => self::CONFIRM])
            ->assertStatus(409)->assertJsonPath('ok', false);
        $this->postJson(self::URL.'/stop', ['run_id' => $run['run_id']])->assertOk();
        $this->postJson(self::URL.'/start', ['dry_run_id' => $consumed['dry_run_id'], 'confirm' => self::CONFIRM])
            ->assertUnprocessable()->assertJsonPath('ok', false);
        Queue::assertPushed(RunFrenchCatalogTranslationBatch::class, 1);
        Http::assertNothingSent();
    }

    public function test_refresh_and_status_polling_do_not_start_or_advance_a_run(): void
    {
        $user = $this->user();
        $this->part();
        $run = $this->start($user);
        for ($i = 0; $i < 3; $i++) {
            $this->get(self::URL)->assertOk();
            $this->getJson(self::URL.'/status')->assertOk()
                ->assertJsonPath('run_id', $run['run_id'])->assertJsonPath('processed', 0);
        }
        Queue::assertPushed(RunFrenchCatalogTranslationBatch::class, 1);
        $this->assertDatabaseCount('part_translations', 0);
        Http::assertNothingSent();
    }

    public function test_completed_run_cannot_reuse_the_same_users_consumed_preview(): void
    {
        $this->fakeSuccess();
        $user = $this->user();
        $this->part();
        $run = $this->start($user);
        $preview = app(FrenchCatalogTranslationPreviewService::class)->saved($user->id);
        $this->runner()->runBatch($run['run_id']);
        $this->assertSame('completed', $this->runner()->status()['status']);
        $this->postJson(self::URL.'/start', [
            'dry_run_id' => $preview['dry_run_id'], 'confirm' => self::CONFIRM,
        ])->assertUnprocessable()->assertJsonPath('reason', 'dry_run_consumed');
        Queue::assertPushed(RunFrenchCatalogTranslationBatch::class, 1);
        Http::assertSentCount(1);
    }

    public function test_batches_checkpoint_one_hundred_records_then_finish_categories_and_progress(): void
    {
        $this->fakeSuccess();
        $user = $this->user();
        foreach (range(1, 105) as $i) {
            $this->part();
        }
        foreach (range(1, 3) as $i) {
            $this->category();
        }
        $run = $this->start($user);
        (new RunFrenchCatalogTranslationBatch($run['run_id']))->handle($this->runner());
        $this->getJson(self::URL.'/status')->assertOk()->assertJsonPath('status', 'running')
            ->assertJsonPath('total', 108)->assertJsonPath('processed', 100)
            ->assertJsonPath('translated', 100)->assertJsonPath('failed', 0)
            ->assertJsonStructure(['run_id', 'status', 'total', 'processed', 'translated', 'skipped', 'failed',
                'chars_translated', 'started_at', 'last_success', 'last_error', 'next_batch']);
        $this->assertDatabaseCount('part_translations', 100);
        $this->assertDatabaseCount('category_translations', 0);
        $this->runner()->runBatch($run['run_id']);
        $this->getJson(self::URL.'/status')->assertOk()->assertJsonPath('status', 'completed')
            ->assertJsonPath('processed', 108)->assertJsonPath('translated', 108)
            ->assertJsonPath('skipped', 0)->assertJsonPath('failed', 0);
        $this->assertDatabaseCount('part_translations', 105);
        $this->assertDatabaseCount('category_translations', 3);
        Http::assertSentCount(108);
        $this->runner()->runBatch($run['run_id']);
        Http::assertSentCount(108);
    }

    public function test_new_records_after_preview_wait_for_next_run(): void
    {
        $this->fakeSuccess();
        $user = $this->user();
        $first = $this->part();
        $preview = $this->preview($user);
        $later = $this->part();
        $run = $this->postJson(self::URL.'/start', [
            'dry_run_id' => $preview['dry_run_id'], 'confirm' => self::CONFIRM,
        ])->assertOk()->json();
        $this->runner()->runBatch($run['run_id']);
        $this->assertDatabaseHas('part_translations', ['part_id' => $first->id, 'status' => 'translated']);
        $this->assertDatabaseMissing('part_translations', ['part_id' => $later->id]);
        $this->assertSame(1, $this->runner()->status()['total']);
        $next = $this->start($user);
        $this->runner()->runBatch($next['run_id']);
        $this->assertDatabaseHas('part_translations', ['part_id' => $later->id, 'status' => 'translated']);
        Http::assertSentCount(2);
    }

    public function test_pause_resume_stop_preserve_checkpoint_and_ignore_stale_run_ids(): void
    {
        $this->fakeSuccess();
        $user = $this->user();
        foreach (range(1, 102) as $i) {
            $this->part();
        }
        $run = $this->start($user);
        $this->runner()->runBatch($run['run_id']);
        foreach (['pause', 'resume', 'stop'] as $action) {
            $this->postJson(self::URL.'/'.$action, ['run_id' => 'previous-run'])
                ->assertStatus(409)->assertJsonPath('ok', false);
        }
        $this->postJson(self::URL.'/pause', ['run_id' => $run['run_id']])->assertOk()
            ->assertJsonPath('status', 'paused')->assertJsonPath('processed', 100);
        $this->runner()->runBatch($run['run_id']);
        Http::assertSentCount(100);
        $this->postJson(self::URL.'/resume', ['run_id' => $run['run_id']])->assertOk()
            ->assertJsonPath('status', 'running')->assertJsonPath('processed', 100);
        $this->postJson(self::URL.'/stop', ['run_id' => $run['run_id']])->assertOk()
            ->assertJsonPath('status', 'stopped')->assertJsonPath('processed', 100);
        $this->runner()->runBatch($run['run_id']);
        $this->postJson(self::URL.'/resume', ['run_id' => $run['run_id']])->assertStatus(409);
        Http::assertSentCount(100);
        $this->assertDatabaseCount('part_translations', 100);
    }

    public function test_run_is_idempotent_and_protects_reviewed_current_failed_and_needs_update(): void
    {
        $this->fakeSuccess();
        $user = $this->user();
        $missingPart = $this->part();
        $missingCategory = $this->category();
        $protected = [];
        foreach (['reviewed', 'translated', 'failed', 'needs_update'] as $status) {
            foreach ([$this->part(), $this->category()] as $record) {
                $this->translation($record, $status, ['attempts' => 3]);
                $protected[] = [$record, $record->translations()->first()->toArray()];
            }
        }
        $reviewedStale = $this->part();
        $this->translation($reviewedStale, 'reviewed', ['source_hash' => str_repeat('0', 64)]);
        $protected[] = [$reviewedStale, $reviewedStale->translations()->first()->toArray()];
        $run = $this->start($user);
        $this->runner()->runBatch($run['run_id']);
        $this->assertSame(2, $this->runner()->status()['translated']);
        $this->assertSame(9, $this->runner()->status()['skipped']);
        foreach ($protected as [$record, $before]) {
            $this->assertSame($before, $record->translations()->first()->toArray());
        }
        foreach ([$missingPart, $missingCategory] as $record) {
            $this->assertSame('translated', $record->translations()->first()->status);
            $this->assertSame(1, $record->translations()->first()->attempts);
        }
        $next = $this->start($user);
        $this->runner()->runBatch($next['run_id']);
        $this->assertSame(0, $this->runner()->status()['translated']);
        Http::assertSentCount(2);
    }

    public function test_catalog_run_changes_only_translation_tables_and_keeps_orders_stock_and_marketplaces(): void
    {
        $this->fakeSuccess();
        $user = $this->user();
        $part = $this->part(['description' => 'Opis', 'short_description' => 'Skrót', 'condition_notes' => 'Rysa']);
        $this->category(['description' => 'Opis kategorii']);
        $account = MarketplaceAccount::query()->create(['marketplace' => 'allegro', 'code' => 'fr-protected',
            'name' => 'Protected account', 'api_enabled' => false]);
        MarketplaceListing::query()->create(['marketplace' => 'allegro', 'marketplace_account_id' => $account->id,
            'part_id' => $part->id, 'external_offer_id' => 'protected-offer', 'quantity' => 2, 'status' => 'active']);
        $orderId = DB::table('orders')->insertGetId(['order_number' => 'FR-PROTECTED-1', 'status' => 'new',
            'currency' => 'PLN', 'subtotal' => 100, 'total' => 100, 'customer_name' => 'Customer',
            'email' => 'customer@example.test', 'phone' => '123456789', 'address_line1' => 'Testowa 1',
            'postal_code' => '00-001', 'city' => 'Warszawa']);
        DB::table('order_items')->insert(['order_id' => $orderId, 'part_id' => $part->id,
            'product_name' => $part->name, 'unit_price' => 100, 'quantity' => 1, 'line_total' => 100]);
        $tables = ['parts', 'part_categories', 'orders', 'order_items', 'marketplace_accounts',
            'marketplace_listings', 'marketplace_sync_logs', 'ovoko_stock_sync_runs', 'ovoko_stock_sync_run_items'];
        $before = $this->snapshot($tables);
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $run = $this->start($user);
        $this->runner()->runBatch($run['run_id']);
        $this->assertSame($before, $this->snapshot($tables));
        $this->assertNotEmpty($writes);
        foreach ($writes as $sql) {
            $this->assertMatchesRegularExpression(
                '/^\s*(?:insert\s+into|update|delete\s+from)\s+["`]*(?:part_translations|category_translations|cache|cache_locks|jobs|failed_jobs)\b/i',
                $sql,
            );
        }
        Http::assertSentCount(6);
        Http::assertSent(fn ($request) => $request['source'] === 'pl' && $request['target'] === 'fr');
    }

    public function test_historical_and_provider_errors_expose_only_controlled_messages(): void
    {
        $user = $this->user();
        $historical = $this->part();
        $this->translation($historical, 'failed', ['error_code' => 'Bearer historical-secret',
            'error_message' => '<script>alert("historical-secret")</script> api_key=historical-secret']);
        $part = $this->part();
        Http::fake(['translation.googleapis.com/*' => Http::response([
            'error' => ['message' => 'Bearer provider-secret api_key=provider-secret <script>unsafe</script>'],
        ], 403)]);
        $this->actingAs($user)->get(self::URL)->assertOk()->assertDontSee('historical-secret');
        $report = $this->preview($user);
        $this->assertStringNotContainsString('historical-secret', json_encode($report));
        $run = $this->postJson(self::URL.'/start', [
            'dry_run_id' => $report['dry_run_id'], 'confirm' => self::CONFIRM,
        ])->assertOk()->json();
        $this->runner()->runBatch($run['run_id']);
        $response = $this->getJson(self::URL.'/status')->assertOk()->assertDontSee('provider-secret')
            ->assertDontSee('historical-secret')->assertDontSee('admin-test-key-never-exposed');
        $this->assertStringContainsString('google_http_403', $response->getContent());
        $this->assertDatabaseHas('part_translations', ['part_id' => $part->id, 'status' => 'failed',
            'error_code' => 'google_http_403', 'error_message' => 'Google Translate returned HTTP 403.']);
        $this->get(self::URL)->assertOk()->assertDontSee('provider-secret')->assertDontSee('historical-secret');
        Http::assertSentCount(1);
    }

    public function test_transient_errors_retry_with_delayed_jobs_and_bounded_attempts_without_sleep(): void
    {
        $user = $this->user();
        $part = $this->part();
        $next = $this->part(['name' => 'Drzwi']);
        Http::fake(['translation.googleapis.com/*' => fn ($request) => $request['q'] === 'Silnik'
            ? Http::response([], 429)
            : Http::response(['data' => ['translations' => [['translatedText' => 'Porte']]]])]);
        $run = $this->start($user);
        $this->runner()->runBatch($run['run_id']);
        $this->assertSame(1, $part->translations()->first()->attempts);
        $this->assertSame(0, $this->runner()->status()['processed']);
        $this->assertNotNull($this->runner()->status()['next_batch']);
        Queue::assertPushed(RunFrenchCatalogTranslationBatch::class, fn ($job) => $job->delay !== null);
        $this->runner()->runBatch($run['run_id']);
        Http::assertSentCount(1);
        foreach ([31, 121, 301] as $delay) {
            $this->travel($delay)->seconds();
            $this->runner()->runBatch($run['run_id']);
        }
        $this->assertSame(4, $part->translations()->first()->attempts);
        $this->assertSame('failed', $part->translations()->first()->status);
        $this->assertSame('stopped_on_error', $this->runner()->status()['status']);
        $this->assertSame(1, $this->runner()->status()['processed']);
        Http::assertSentCount(4);
        $this->postJson(self::URL.'/resume', ['run_id' => $run['run_id']])->assertOk();
        $this->runner()->runBatch($run['run_id']);
        $this->assertDatabaseHas('part_translations', ['part_id' => $next->id, 'status' => 'translated']);
        $this->assertSame(2, $this->runner()->status()['processed']);
        $this->assertSame(1, $this->runner()->status()['failed']);
        Http::assertSentCount(5);
        Sleep::assertNeverSlept();
        $this->runner()->runBatch($run['run_id']);
        Http::assertSentCount(5);
    }

    public function test_flag_disabled_between_batches_prevents_more_provider_calls(): void
    {
        $this->fakeSuccess();
        $user = $this->user();
        foreach (range(1, 101) as $i) {
            $this->part();
        }
        $run = $this->start($user);
        $this->runner()->runBatch($run['run_id']);
        config(['storefront-translations.fr_enabled' => false]);
        $this->runner()->runBatch($run['run_id']);
        Http::assertSentCount(100);
        $this->assertDatabaseCount('part_translations', 100);
        $this->assertSame(100, $this->runner()->status()['processed']);
        $this->assertSame('stopped_on_error', $this->runner()->status()['status']);
    }

    public function test_old_queued_job_cannot_process_new_run_or_restart_stopped_run(): void
    {
        $this->fakeSuccess();
        $user = $this->user();
        $this->part();
        $old = $this->start($user);
        $this->postJson(self::URL.'/stop', ['run_id' => $old['run_id']])->assertOk();
        $new = $this->start($user);
        $this->assertNotSame($old['run_id'], $new['run_id']);
        $this->runner()->runBatch($old['run_id']);
        $this->assertSame($new['run_id'], $this->runner()->status()['run_id']);
        $this->assertSame(0, $this->runner()->status()['processed']);
        Http::assertNothingSent();
        $this->runner()->runBatch($new['run_id']);
        Http::assertSentCount(1);
    }

    public function test_worker_failure_sets_safe_resumable_checkpoint_without_exception_details(): void
    {
        $user = $this->user();
        $this->part();
        $run = $this->start($user);
        $this->runner()->failed($run['run_id']);
        $this->getJson(self::URL.'/status')->assertOk()->assertJsonPath('status', 'failed')
            ->assertJsonPath('processed', 0)->assertDontSee('admin-test-key-never-exposed');
        $this->fakeSuccess();
        $this->postJson(self::URL.'/resume', ['run_id' => $run['run_id']])->assertOk();
        $this->runner()->runBatch($run['run_id']);
        $this->assertSame('completed', $this->runner()->status()['status']);
        Http::assertSentCount(1);
    }
}
