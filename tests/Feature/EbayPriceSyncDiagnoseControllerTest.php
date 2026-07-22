<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EbayPriceSyncDiagnoseControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_only_diagnose_single_ebay_de_offer_and_payload_shape(): void
    {
        $user = User::factory()->create(); $user->assignRole(UserRole::OwnerAdmin->value);
        $part = Part::query()->create(['id'=>8212,'name'=>'P','sku'=>'GPS-8212-566971121EH','quantity'=>1,'status'=>'ready']);
        $account = MarketplaceAccount::query()->create(['id'=>3,'marketplace'=>'ebay_de','name'=>'eBay DE','status'=>'active','api_enabled'=>true,'api_base_url'=>'https://api.ebay.test','api_credentials'=>['access_token'=>'at']]);
        MarketplaceListing::query()->create(['id'=>23108,'part_id'=>$part->id,'marketplace'=>'ebay_de','marketplace_account_id'=>$account->id,'external_offer_id'=>'211951318011','external_inventory_id'=>'GPS-8212-566971121EH','sku'=>'GPS-8212-566971121EH','price'=>'36.09','currency'=>'EUR','status'=>'active']);
        Http::fake([
            '*/sell/inventory/v1/offer/211951318011'=>Http::response(['offerId'=>'211951318011','sku'=>'GPS-8212-566971121EH','marketplaceId'=>'EBAY_DE','status'=>'PUBLISHED','availableQuantity'=>1,'pricingSummary'=>['price'=>['value'=>'34.55','currency'=>'EUR']]],200),
            '*/sell/inventory/v1/offer?sku=GPS-8212-566971121EH&marketplace_id=EBAY_DE'=>Http::response(['offers'=>[['offerId'=>'211951318011','sku'=>'GPS-8212-566971121EH','marketplaceId'=>'EBAY_DE','status'=>'PUBLISHED','availableQuantity'=>1,'pricingSummary'=>['price'=>['value'=>'34.55','currency'=>'EUR']]]]],200),
            '*/sell/inventory/v1/inventory_item/GPS-8212-566971121EH'=>Http::response(['sku'=>'GPS-8212-566971121EH','shipToLocationAvailability'=>['quantity'=>1]],200),
        ]);
        $res = $this->actingAs($user)->getJson('/admin/tools/marketplace/parts/8212/ebay-price-sync-diagnose')->assertOk()->json();
        $this->assertTrue($res['read_only']); $this->assertFalse($res['marketplace_write']); $this->assertSame(['GET'],$res['external_methods_used']);
        $this->assertSame(['211951318011'],$res['found_offer_ids']);
        $this->assertTrue($res['mapping']['unique_sku_offer_mapping']);
        $this->assertSame('35.80', data_get($res,'planned_bulk_payload.requests.0.offers.0.price.value'));
        $this->assertSame('211951318011', data_get($res,'planned_bulk_payload.requests.0.offers.0.offerId'));
        $this->assertTrue($res['current_payload_differs_from_required']);
        Http::assertSentCount(3);
        $this->assertSame(0, count(Http::recorded(fn($r)=>$r->method() !== 'GET')));
    }

    public function test_read_only_diagnose_reports_multiple_sku_offers(): void
    {
        $user = User::factory()->create(); $user->assignRole(UserRole::OwnerAdmin->value);
        $part = Part::query()->create(['id'=>8212,'name'=>'P','sku'=>'SKUDE','quantity'=>1,'status'=>'ready']);
        $account = MarketplaceAccount::query()->create(['marketplace'=>'ebay_de','name'=>'eBay DE','status'=>'active','api_enabled'=>true,'api_base_url'=>'https://api.ebay.test','api_credentials'=>['access_token'=>'at']]);
        MarketplaceListing::query()->create(['part_id'=>$part->id,'marketplace'=>'ebay_de','marketplace_account_id'=>$account->id,'external_offer_id'=>'OFFER1','external_inventory_id'=>'SKUDE','sku'=>'SKUDE','price'=>'36.09','currency'=>'EUR','status'=>'active']);
        Http::fake([
            '*/sell/inventory/v1/offer/OFFER1'=>Http::response(['offerId'=>'OFFER1','sku'=>'SKUDE','marketplaceId'=>'EBAY_DE','status'=>'PUBLISHED','availableQuantity'=>1,'pricingSummary'=>['price'=>['value'=>'34.55','currency'=>'EUR']]],200),
            '*/sell/inventory/v1/offer?sku=SKUDE&marketplace_id=EBAY_DE'=>Http::response(['offers'=>[['offerId'=>'OFFER1'],['offerId'=>'OFFER2']]],200),
            '*/sell/inventory/v1/inventory_item/SKUDE'=>Http::response(['sku'=>'SKUDE'],200),
        ]);
        $res = $this->actingAs($user)->getJson('/admin/tools/marketplace/parts/8212/ebay-price-sync-diagnose')->assertOk()->json();
        $this->assertSame(['OFFER1','OFFER2'],$res['found_offer_ids']);
        $this->assertFalse($res['mapping']['unique_sku_offer_mapping']);
        $this->assertContains('multiple_offers_for_sku_require_offerId_targeting', $res['blockers']);
    }
}
