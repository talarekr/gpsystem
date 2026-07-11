<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Models\ShopEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketplaceSupportReadOnlyDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_diagnose_endpoint_is_read_only_and_does_not_write_shop_events(): void
    {
        Http::preventStrayRequests();

        MarketplaceAccount::query()->create([
            'marketplace' => 'allegro',
            'name' => 'Allegro',
            'code' => 'allegro',
            'status' => 'active',
            'api_enabled' => true,
            'api_base_url' => 'https://api.allegro.pl',
            'api_credentials' => ['access_token' => 'secret', 'scope' => 'allegro:api:orders:read'],
        ]);

        $before = ShopEvent::query()->count();

        $this->getJson('/admin/tools/support-sync/allegro/diagnose?json=1')
            ->assertOk()
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('marketplace', 'allegro')
            ->assertJsonPath('no_mutation', true)
            ->assertJsonPath('authentication_ready', true)
            ->assertJsonPath('sample_count', 0)
            ->assertJsonPath('probe_executed', false);

        $this->assertSame($before, ShopEvent::query()->count());
    }

    public function test_preview_maps_local_order_without_marketplace_mutation(): void
    {
        Http::preventStrayRequests();

        Order::query()->create([
            'order_number' => 'ALLEGRO-123',
            'marketplace_order_id' => 'EXT-123',
            'marketplace' => 'allegro',
            'status' => 'new',
            'currency' => 'PLN',
        ]);

        $this->getJson('/admin/tools/support-sync/preview?marketplace=allegro&json=1')
            ->assertOk()
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('marketplace', 'allegro')
            ->assertJsonPath('sample_count', 0)
            ->assertJsonPath('sample', []);
    }

    public function test_ovoko_diagnose_reports_undocumented_support_capabilities(): void
    {
        Http::preventStrayRequests();

        $this->getJson('/admin/tools/support-sync/ovoko/diagnose?json=1')
            ->assertOk()
            ->assertJsonPath('marketplace', 'ovoko')
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('messages_supported', false)
            ->assertJsonPath('returns_supported', false)
            ->assertJsonPath('complaints_supported', false)
            ->assertJsonPath('no_mutation', true);
    }


    public function test_allegro_probe_uses_get_limits_to_five_and_redacts_sensitive_data(): void
    {
        Http::preventStrayRequests();
        MarketplaceAccount::query()->create(['marketplace'=>'allegro','name'=>'Allegro','code'=>'allegro','status'=>'active','api_enabled'=>true,'api_base_url'=>'https://api.allegro.pl','api_credentials'=>['access_token'=>'secret','scope'=>'allegro:api:orders:read allegro:api:sale:offers:read']]);
        Order::query()->create(['order_number'=>'A1','marketplace_order_id'=>'ORDER-1','marketplace'=>'allegro','status'=>'new','currency'=>'PLN']);
        Http::fake([
            'api.allegro.pl/order/customer-returns?limit=5' => Http::response(['customerReturns'=>array_map(fn($i)=>['id'=>'R'.$i,'status'=>'CREATED','order'=>['id'=>'ORDER-'.$i],'buyerEmail'=>'x@example.com'], range(1, 7))], 200),
            'api.allegro.pl/sale/issues?limit=5' => Http::response(['issues'=>[['id'=>'D1','status'=>'OPEN','order'=>['id'=>'ORDER-1']]]], 200),
        ]);

        $this->getJson('/admin/tools/support-sync/allegro/diagnose?json=1&probe=1')->assertOk()
            ->assertJsonPath('probe_executed', true)
            ->assertJsonPath('sample_count', 5)
            ->assertJsonPath('sample.0.local_order_id', 1);
        Http::assertSentCount(2);
        Http::assertSent(fn($request) => $request->method() === 'GET' && str_contains($request->url(), 'limit=5'));
        $this->assertSame(0, ShopEvent::query()->count());
    }

    public function test_ebay_fulfillment_is_not_message_probe_and_capability_distinguishes_scope_from_access(): void
    {
        Http::preventStrayRequests();
        MarketplaceAccount::query()->create(['marketplace'=>'ebay','name'=>'eBay','code'=>'ebay_de','status'=>'active','api_enabled'=>true,'api_base_url'=>'https://api.ebay.com','api_credentials'=>['access_token'=>'secret','scope'=>'https://api.ebay.com/oauth/api_scope/sell.fulfillment.readonly']]);
        Http::fake([
            'api.ebay.com/post-order/v2/return/search?limit=5' => Http::response([], 403),
            'api.ebay.com/post-order/v2/inquiry/search?limit=5' => Http::response([], 429),
            'api.ebay.com/post-order/v2/cancellation/search?limit=5' => Http::response(['cancellations'=>[]], 200),
            'api.ebay.com/post-order/v2/casemanagement/search?limit=5' => Http::response([], 401),
        ]);
        $json = $this->getJson('/admin/tools/support-sync/ebay/diagnose?json=1&probe=1')->assertOk()->json();
        $messages = collect($json['capability_checks'])->firstWhere('feature', 'messages');
        $returns = collect($json['capability_checks'])->firstWhere('feature', 'returns');
        $inquiries = collect($json['capability_checks'])->firstWhere('feature', 'inquiries');
        $cancellations = collect($json['capability_checks'])->firstWhere('feature', 'cancellations');
        $disputes = collect($json['capability_checks'])->firstWhere('feature', 'disputes');
        $this->assertStringNotContainsString('/sell/fulfillment/v1/order', $messages['endpoint']);
        $this->assertFalse($messages['probe_executed']);
        $this->assertTrue($messages['api_exists']);
        $this->assertFalse($messages['app_has_scope']);
        $this->assertSame('auth_or_scope_error', $returns['error_type']);
        $this->assertSame('rate_limited', $inquiries['error_type']);
        $this->assertTrue($cancellations['app_access_confirmed']);
        $this->assertSame('auth_or_scope_error', $disputes['error_type']);
    }

    public function test_missing_scope_does_not_execute_ebay_request(): void
    {
        Http::preventStrayRequests();
        MarketplaceAccount::query()->create(['marketplace'=>'ebay','name'=>'eBay','code'=>'ebay_de','status'=>'active','api_enabled'=>true,'api_base_url'=>'https://api.ebay.com','api_credentials'=>['access_token'=>'secret','scope'=>'']]);
        $this->getJson('/admin/tools/support-sync/ebay/diagnose?json=1&probe=1')->assertOk();
        Http::assertNothingSent();
    }

    public function test_ovoko_config_diagnose_does_not_probe_undocumented_support_api(): void
    {
        Http::preventStrayRequests();
        MarketplaceAccount::query()->create(['marketplace'=>'ovoko','name'=>'Ovoko','code'=>'ovoko','status'=>'active','api_enabled'=>true,'api_base_url'=>'https://api.rrr.lt','api_credentials'=>['username'=>'u','password'=>'p','user_token'=>'t']]);
        $this->getJson('/admin/tools/support-sync/ovoko/diagnose?json=1&probe=1')->assertOk()
            ->assertJsonPath('ovoko_config.order_sync_credentials_detected', true)
            ->assertJsonPath('ovoko_config.support_api_credentials_detected', false)
            ->assertJsonPath('ovoko_config.can_probe_support_api', false);
        Http::assertNothingSent();
    }


    public function test_allegro_probe_uses_separate_accept_headers_and_maps_406(): void
    {
        Http::preventStrayRequests();
        MarketplaceAccount::query()->create(['marketplace'=>'allegro','name'=>'Allegro','code'=>'allegro','status'=>'active','api_enabled'=>true,'api_base_url'=>'https://api.allegro.pl','api_credentials'=>['access_token'=>'secret','scope'=>'allegro:api:orders:read allegro:api:sale:offers:read']]);
        Http::fake([
            'api.allegro.pl/order/customer-returns?limit=5' => Http::response(['customerReturns'=>[['id'=>'R1','status'=>'CREATED']]], 200, ['Content-Type' => 'application/vnd.allegro.public.v1+json']),
            'api.allegro.pl/sale/issues?limit=5' => Http::response(['errors'=>[['code'=>'NotAcceptableException','message'=>'Not acceptable representation requested']]], 406, ['Content-Type' => 'application/json', 'trace-id' => 'trace-redacted']),
        ]);

        $this->getJson('/admin/tools/support-sync/allegro/diagnose?json=1&probe=1')->assertOk()
            ->assertJsonPath('probe_results.returns.accept_header', 'application/vnd.allegro.public.v1+json')
            ->assertJsonPath('probe_results.issues.accept_header', 'application/vnd.allegro.beta.v1+json')
            ->assertJsonPath('probe_results.issues.error_type', 'not_acceptable_media_type')
            ->assertJsonPath('probe_results.returns.sample_count', 1)
            ->assertJsonPath('probe_results.issues.sample_count', 0);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/order/customer-returns') && $request->header('Accept')[0] === 'application/vnd.allegro.public.v1+json' && $request->header('Accept-Language')[0] === 'pl-PL');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sale/issues') && $request->header('Accept')[0] === 'application/vnd.allegro.beta.v1+json' && $request->method() === 'GET');
    }

    public function test_ebay_auth_diagnose_redacts_tokens_and_reports_missing_message_scope(): void
    {
        Http::preventStrayRequests();
        MarketplaceAccount::query()->create(['marketplace'=>'ebay','name'=>'eBay','code'=>'ebay_de','status'=>'active','api_enabled'=>true,'api_base_url'=>'https://api.ebay.com','api_credentials'=>['access_token'=>'secret-access','refresh_token'=>'secret-refresh','scope'=>'https://api.ebay.com/oauth/api_scope/sell.fulfillment.readonly']]);
        $json = $this->getJson('/admin/tools/support-sync/ebay/auth-diagnose?json=1')->assertOk()
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('no_mutation', true)
            ->assertJsonPath('requires_reauthorization', true)
            ->json();
        $encoded = json_encode($json);
        $this->assertStringNotContainsString('secret-access', $encoded);
        $this->assertStringNotContainsString('secret-refresh', $encoded);
        $this->assertContains('https://api.ebay.com/oauth/api_scope/commerce.message.readonly', $json['missing_scopes_by_feature']['messages']);
    }

    public function test_ebay_post_order_401_keeps_safe_error_details_and_not_confirmed_working(): void
    {
        Http::preventStrayRequests();
        MarketplaceAccount::query()->create(['marketplace'=>'ebay','name'=>'eBay','code'=>'ebay_de','status'=>'active','api_enabled'=>true,'api_base_url'=>'https://api.ebay.com','api_credentials'=>['access_token'=>'secret','scope'=>'https://api.ebay.com/oauth/api_scope/sell.fulfillment.readonly']]);
        Http::fake(['api.ebay.com/post-order/v2/*' => Http::response(['errors'=>[['errorId'=>1100,'domain'=>'ACCESS','category'=>'REQUEST','message'=>'Access denied']]], 401)]);
        $json = $this->getJson('/admin/tools/support-sync/ebay/diagnose?json=1&probe=1')->assertOk()->json();
        $returns = collect($json['capability_checks'])->firstWhere('feature', 'returns');
        $this->assertSame(401, $returns['http_status']);
        $this->assertSame(1100, $returns['ebay_error_id']);
        $this->assertSame('ACCESS', $returns['ebay_error_domain']);
        $this->assertFalse($returns['app_access_confirmed']);
        $this->assertNotSame('confirmed working', $json['decision_table']['returns']);
    }

    public function test_no_marketplace_mutating_methods_are_used_by_probes(): void
    {
        Http::preventStrayRequests();
        MarketplaceAccount::query()->create(['marketplace'=>'allegro','name'=>'Allegro','code'=>'allegro','status'=>'active','api_enabled'=>true,'api_base_url'=>'https://api.allegro.pl','api_credentials'=>['access_token'=>'secret','scope'=>'allegro:api:orders:read allegro:api:sale:offers:read']]);
        Http::fake(['api.allegro.pl/*' => Http::response([], 401)]);
        $this->getJson('/admin/tools/support-sync/allegro/diagnose?json=1&probe=1')->assertOk();
        Http::assertSent(fn ($request) => ! in_array($request->method(), ['POST','PUT','PATCH','DELETE'], true));
        $this->assertSame(0, ShopEvent::query()->count());
    }

}
