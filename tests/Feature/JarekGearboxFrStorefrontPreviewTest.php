<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\JarekGearbox;
use App\Models\MarketplaceCategoryMapping;
use App\Models\PartCategory;
use App\Models\User;
use App\Services\Marketplace\NbpExchangeRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class JarekGearboxFrStorefrontPreviewTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/admin/tools/jarek-gearboxes/fr-storefront-preview';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
    }

    public function test_endpoint_requires_authenticated_owner_admin(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $user = User::query()->create(['name' => 'Viewer', 'email' => 'viewer@example.test', 'password' => 'password']);
        Role::findOrCreate(UserRole::Viewer->value, 'web');
        $user->assignRole(UserRole::Viewer->value);
        $this->actingAs($user)->getJson(self::URL)->assertForbidden();
        $user->syncRoles([]);
        $this->getJson(self::URL)->assertForbidden();
        Role::findOrCreate(UserRole::OwnerAdmin->value, 'web');
        $user->assignRole(UserRole::OwnerAdmin->value);
        $this->getJson(self::URL)->assertOk()->assertJsonPath('read_only', true);
        Http::assertNothingSent();
    }

    public function test_remember_me_alone_cannot_restore_or_write_an_admin_session(): void
    {
        Role::findOrCreate(UserRole::OwnerAdmin->value, 'web');
        $user = User::query()->create(['name' => 'Admin', 'email' => 'remember@example.test', 'password' => 'password']);
        $user->assignRole(UserRole::OwnerAdmin->value);
        $user->forceFill(['remember_token' => 'preview-remember-token'])->save();
        $cookieName = auth()->guard('web')->getRecallerName();
        $cookie = $user->id.'|'.$user->remember_token.'|'.$user->password;
        $before = $user->fresh()->getAttributes();
        app('auth')->forgetGuards();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->withCredentials()->withCookie($cookieName, $cookie)->getJson(self::URL)->assertUnauthorized();
        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/^\\s*(insert|update|delete|replace|create|alter|drop)\\b/i', $sql);
        }
        $this->assertSame($before, $user->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public function test_preview_is_read_only_calculates_markup_and_ignores_source_stock_for_fr_decision(): void
    {
        $this->admin();
        $category = $this->deliveryCategory();
        $product = $this->gearbox(['quantity' => 0, 'allegro_status' => 'ENDED', 'ebay_payload_snapshot' => [
            '_jarek_price_fetch' => ['marketplaceId' => 'EBAY_DE', 'fetched_at' => '2026-07-06T12:00:00Z',
                'pricingSummary' => ['price' => ['value' => '315.00', 'currency' => 'EUR']]],
        ]]);
        $partId = DB::table('parts')->insertGetId([
            'source_system' => 'jarek', 'external_id' => $product->allegro_offer_id, 'sku' => 'JAREK-'.$product->allegro_offer_id,
            'name' => $product->title, 'price' => 9999, 'currency' => 'PLN', 'quantity' => 7,
            'category_id' => $category->id, 'status' => 'ready', 'needs_listing' => false,
            'needs_review' => false, 'is_visible_storefront' => false,
        ]);
        $this->cacheRate();
        $beforeProduct = $product->fresh()->getAttributes();
        $beforePart = (array) DB::table('parts')->where('id', $partId)->first();
        $beforeCache = Cache::get('nbp_table_a_rate:EUR:latest');
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $response = $this->getJson(self::URL);
        $response->assertOk()
            ->assertJsonPath('summary.total_jarek_gearboxes', 1)
            ->assertJsonPath('summary.already_in_parts_count', 1)
            ->assertJsonPath('summary.needs_part_creation_count', 0)
            ->assertJsonPath('summary.data_ready_count', 1)
            ->assertJsonPath('summary.eligible_for_fr_count', 0)
            ->assertJsonPath('summary.blocked_count', 1)
            ->assertJsonPath('sample_products.0.base_price', 1000)
            ->assertJsonPath('sample_products.0.price_after_markup_pln', 1250)
            ->assertJsonPath('sample_products.0.nbp_eur_rate', 4)
            ->assertJsonPath('sample_products.0.price_eur', 312.5)
            ->assertJsonPath('sample_products.0.source_quantity', 0)
            ->assertJsonPath('sample_products.0.fr_quantity_decision', 1)
            ->assertJsonPath('sample_products.0.source_stock_decrement', false)
            ->assertJsonPath('sample_products.0.marketplace_stock_sync', false)
            ->assertJsonPath('sample_products.0.proposed_fr_delivery.amount_eur', 70)
            ->assertJsonPath('sample_products.0.historical_ebay_de_price.value', 315)
            ->assertJsonPath('sample_products.0.status', 'blocked')
            ->assertJsonPath('sample_products.0.currently_visible_via_shared_catalog.0', $partId)
            ->assertJsonPath('sample_products.0.blockers', ['pl_visibility_risk', 'independent_fr_stock_required', 'jarek_fr_markup_resolver_required'])
            ->assertJsonPath('external_api_requests', false)->assertJsonPath('database_write', false);
        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $sql);
        }
        $this->assertSame($beforeProduct, $product->fresh()->getAttributes());
        $this->assertSame($beforePart, (array) DB::table('parts')->where('id', $partId)->first());
        $this->assertSame($beforeCache, Cache::get('nbp_table_a_rate:EUR:latest'));
        $this->assertDatabaseCount('parts', 1);
        $this->assertDatabaseCount('marketplace_sync_logs', 0);
        $this->assertDatabaseCount('marketplace_listings', 0);
        Http::assertNothingSent();
    }

    public function test_missing_data_and_non_pln_currency_are_blockers_without_invented_shipping(): void
    {
        $this->admin();
        $this->gearbox(['price' => null, 'currency' => 'EUR', 'images' => [], 'description' => null, 'plain_description' => null]);
        $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('summary.missing_price_count', 1)
            ->assertJsonPath('summary.non_pln_price_count', 1)
            ->assertJsonPath('summary.missing_images_count', 1)
            ->assertJsonPath('summary.missing_description_count', 1)
            ->assertJsonPath('summary.missing_delivery_mapping_count', 1)
            ->assertJsonPath('summary.visibility_risk_count', 1)
            ->assertJsonPath('summary.stock_behavior_required_count', 1)
            ->assertJsonPath('summary.needs_part_creation_count', 1)
            ->assertJsonPath('sample_products.0.price_eur', null)
            ->assertJsonPath('sample_products.0.proposed_fr_delivery.available', false)
            ->assertJsonPath('sample_products.0.proposed_fr_delivery.amount_eur', null);
        $this->assertDatabaseCount('parts', 0);
        Http::assertNothingSent();
    }

    public function test_blocked_delivery_empty_structured_description_and_fr_history_do_not_pass(): void
    {
        $this->admin();
        $category = $this->deliveryCategory();
        MarketplaceCategoryMapping::query()->where('local_category_id', $category->id)->update(['is_blocked' => true]);
        $this->gearbox(['price' => 1.05, 'description' => '{"sections":[]}', 'plain_description' => null,
            'ebay_payload_snapshot' => ['_jarek_price_fetch' => ['marketplaceId' => 'EBAY_FR',
                'pricingSummary' => ['price' => ['value' => '50', 'currency' => 'EUR']]]]]);
        $this->cacheRate();
        $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('sample_products.0.price_after_markup_pln', 1.3125)
            ->assertJsonPath('sample_products.0.price_eur', 0.33)
            ->assertJsonPath('sample_products.0.has_description', false)
            ->assertJsonPath('sample_products.0.historical_ebay_de_price', null)
            ->assertJsonPath('sample_products.0.proposed_fr_delivery.reason', 'delivery_mapping_blocked')
            ->assertJsonPath('sample_products.0.proposed_fr_delivery.amount_eur', null)
            ->assertJsonPath('summary.missing_delivery_mapping_count', 1);
        Http::assertNothingSent();
    }

    public function test_duplicate_existing_parts_and_ambiguous_category_are_reported(): void
    {
        $this->admin();
        $product = $this->gearbox();
        foreach (['one', 'two'] as $sku) {
            DB::table('parts')->insert(['source_system' => 'jarek', 'external_id' => $product->allegro_offer_id,
                'sku' => $sku, 'name' => $sku, 'status' => 'draft', 'price' => 10, 'quantity' => 1]);
        }
        foreach (['a', 'b'] as $slug) {
            $category = PartCategory::query()->create(['name' => 'Skrzynie '.$slug, 'slug' => $slug]);
            MarketplaceCategoryMapping::query()->create(['channel' => 'allegro_main', 'local_category_id' => $category->id,
                'external_category_id' => '123', 'is_blocked' => false]);
        }
        $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('summary.duplicate_risk_count', 1)
            ->assertJsonPath('summary.already_in_parts_count', 1)
            ->assertJsonPath('sample_products.0.proposed_part_category', null)
            ->assertJsonPath('sample_products.0.data_blockers', fn ($blockers) => in_array('part_category_ambiguous', $blockers, true));
        Http::assertNothingSent();
    }

    public function test_cache_only_rate_rejects_absent_stale_future_and_wrong_currency_and_reads_legacy_cache(): void
    {
        foreach ([null, $this->rate(['effective_date' => today()->subDays(8)->toDateString()]),
            $this->rate(['effective_date' => today()->addDay()->toDateString()]),
            $this->rate(['effective_date' => '2026-02-30']), $this->rate(['currency' => 'USD']),
            $this->rate(['source' => 'manual']), $this->rate(['rate' => 0])] as $invalid) {
            Cache::forget('nbp_table_a_rate:EUR:latest');
            if ($invalid !== null) {
                Cache::put('nbp_table_a_rate:EUR:latest', $invalid);
            }
            $this->assertFalse(app(NbpExchangeRateService::class)->cachedEurPlnForPreview()['ok']);
            $this->assertSame($invalid, Cache::get('nbp_table_a_rate:EUR:latest'));
        }
        Cache::forget('nbp_table_a_rate:EUR:latest');
        Cache::put('nbp_table_a_eur_rate', $this->rate());
        $rate = app(NbpExchangeRateService::class)->cachedEurPlnForPreview();
        $this->assertTrue($rate['ok']);
        $this->assertSame('nbp_table_a_eur_rate', $rate['cache_key']);
        Http::assertNothingSent();
    }

    public function test_summary_covers_all_rows_but_sample_is_paginated_and_html_is_escaped(): void
    {
        $this->admin();
        foreach (['1', '2', '3'] as $id) {
            $this->gearbox(['allegro_offer_id' => $id, 'title' => '<script>alert(1)</script>']);
        }
        $this->getJson(self::URL.'?limit=1&offset=1')->assertOk()
            ->assertJsonPath('summary.total_jarek_gearboxes', 3)
            ->assertJsonCount(1, 'sample_products')->assertJsonPath('sample_products.0.allegro_offer_id', '2');
        $this->get(self::URL)->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->get(self::URL.'?download=1')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="jarek-fr-storefront-preview.json"');
        $this->getJson(self::URL.'?limit=201')->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_database_cache_and_database_session_are_not_written_or_garbage_collected(): void
    {
        $this->admin();
        $user = auth()->user();
        $this->gearbox();
        $loginKey = auth()->guard('web')->getName();
        $sessionId = str_repeat('a', 40);
        $payload = base64_encode(serialize([$loginKey => $user->id, '_token' => 'preview-token']));
        DB::table('sessions')->insert(['id' => $sessionId, 'user_id' => $user->id, 'payload' => $payload, 'last_activity' => now()->timestamp]);
        DB::table('sessions')->insert(['id' => str_repeat('b', 40), 'payload' => base64_encode(serialize([])), 'last_activity' => 1]);
        $prefix = config('cache.prefix');
        DB::table('cache')->insert(['key' => $prefix.'nbp_table_a_rate:EUR:latest', 'value' => serialize($this->rate()), 'expiration' => 1]);
        DB::table('cache')->insert(['key' => $prefix.'nbp_table_a_eur_rate', 'value' => serialize($this->rate()), 'expiration' => now()->addHour()->timestamp]);
        $before = DB::table('sessions')->orderBy('id')->get()->toJson();
        $beforeCache = DB::table('cache')->orderBy('key')->get()->toJson();
        config()->set('cache.default', 'database');
        config()->set('session.driver', 'database');
        config()->set('session.lottery', [100, 100]);
        app('session')->forgetDrivers();
        $this->app->forgetInstance('session.store');
        app('auth')->forgetGuards();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->withCredentials()->withCookie(config('session.cookie'), $sessionId)->getJson(self::URL)->assertOk();
        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/^\\s*(insert|update|delete|replace|create|alter|drop)\\b/i', $sql);
        }
        $this->assertSame($before, DB::table('sessions')->orderBy('id')->get()->toJson());
        $this->assertSame($beforeCache, DB::table('cache')->orderBy('key')->get()->toJson());
        $rate = app(NbpExchangeRateService::class)->cachedEurPlnForPreview();
        $this->assertTrue($rate['ok']);
        $this->assertSame('nbp_table_a_eur_rate', $rate['cache_key']);
        Http::assertNothingSent();
    }

    public function test_expired_file_rate_is_not_deleted(): void
    {
        $directory = sys_get_temp_dir().'/jarek-preview-'.bin2hex(random_bytes(8));
        config()->set('cache.stores.file.path', $directory);
        config()->set('cache.default', 'file');
        Cache::purge('file');
        $store = Cache::store('file')->getStore();
        $path = $store->path('nbp_table_a_rate:EUR:latest');
        mkdir(dirname($path), 0777, true);
        $content = '0000000001'.serialize($this->rate());
        file_put_contents($path, $content);
        try {
            $this->assertFalse(app(NbpExchangeRateService::class)->cachedEurPlnForPreview()['ok']);
            $this->assertSame($content, file_get_contents($path));
        } finally {
            app('files')->deleteDirectory($directory);
        }
        Http::assertNothingSent();
    }

    private function admin(): void
    {
        Role::findOrCreate(UserRole::OwnerAdmin->value, 'web');
        $user = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password']);
        $user->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($user);
    }

    private function gearbox(array $values = []): JarekGearbox
    {
        return JarekGearbox::query()->create(array_replace([
            'allegro_offer_id' => '123456', 'title' => 'Skrzynia Jarka', 'price' => 1000, 'currency' => 'PLN',
            'quantity' => 4, 'plain_description' => 'Opis skrzyni', 'images' => ['https://images.example.test/gearbox.jpg'],
            'category_id' => '123', 'category_name' => 'Skrzynie',
        ], $values));
    }

    private function deliveryCategory(): PartCategory
    {
        $category = PartCategory::query()->create(['name' => 'Skrzynie', 'slug' => 'skrzynie']);
        MarketplaceCategoryMapping::query()->create(['channel' => 'ebay_fr', 'local_category_id' => $category->id,
            'external_category_id' => '555', 'shipping_group' => 'fr_70_eur', 'fulfillment_policy_id' => '260547464013', 'is_blocked' => false]);

        return $category;
    }

    private function rate(array $values = []): array
    {
        return array_replace(['ok' => true, 'currency' => 'EUR', 'source' => 'NBP_TABLE_A',
            'rate' => 4, 'effective_date' => today()->toDateString(), 'table_no' => 'test-table'], $values);
    }

    private function cacheRate(): void
    {
        Cache::put('nbp_table_a_rate:EUR:latest', $this->rate());
    }
}
