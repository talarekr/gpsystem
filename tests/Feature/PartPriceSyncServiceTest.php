<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Services\Marketplace\PriceSync\AllegroPriceSyncAdapter;
use App\Services\Marketplace\PriceSync\EbayDePriceSyncAdapter;
use App\Services\Marketplace\PriceSync\PartPriceResolver;
use App\Services\Marketplace\PriceSync\PartPriceSyncService;
use App\Services\Marketplace\PriceSync\PriceNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PartPriceSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_env_false_makes_no_requests_logs_or_listing_updates(): void
    {
        config(['marketplace.price_sync.on_part_save_enabled'=>false]); Http::fake();
        $part = $this->part(); $listing = $this->listing($part, 'allegro');
        $old = ['allegro'=>['marketplace_price'=>'100.00','marketplace_currency'=>'PLN']]; $new = ['allegro'=>['marketplace_price'=>'120.00','marketplace_currency'=>'PLN']];
        $result = app(PartPriceSyncService::class)->sync($part->fresh(['marketplaceListings.account']), $old, $new);
        $this->assertSame('disabled', $result['channels']['allegro']['status']); Http::assertNothingSent();
        $this->assertSame(0, MarketplaceSyncLog::query()->count()); $this->assertSame('100.00', (string) $listing->fresh()->price);
    }

    public function test_decimal_normalization_and_channel_change_detection(): void
    {
        $n = app(PriceNormalizer::class); $this->assertSame('279.00', $n->normalize('279,00')); $this->assertNull($n->normalize('abc'));
        $part = $this->part(['price'=>100,'allegro_price'=>100,'ovoko_price'=>150,'ebay_price'=>125]); Cache::put('nbp_table_a_eur_rate', ['rate'=>5,'source'=>'NBP_TABLE_A'], 60);
        $old = app(PartPriceResolver::class)->snapshot($part); $part->forceFill(['price'=>120,'allegro_price'=>120,'ebay_price'=>150])->saveQuietly(); $new = app(PartPriceResolver::class)->snapshot($part->fresh());
        $svc = app(PartPriceSyncService::class); $ctxA = $svc->context($part->fresh('marketplaceListings.account'), 'allegro', $old['allegro'], $new['allegro']); $ctxO = $svc->context($part->fresh('marketplaceListings.account'), 'ovoko', $old['ovoko'], $new['ovoko']); $ctxE = $svc->context($part->fresh('marketplaceListings.account'), 'ebay_de', $old['ebay_de'], $new['ebay_de']);
        $this->assertTrue($ctxA['changed']); $this->assertTrue($ctxE['changed']); $this->assertFalse($ctxO['changed']);
    }

    public function test_allegro_payload_is_price_only_and_updates_listing_only_after_confirmed_success(): void
    {
        config(['marketplace.price_sync.on_part_save_enabled'=>true,'marketplace.external_api_writes_enabled'=>true,'marketplace.allegro_publishing_enabled'=>true]);
        Http::fake(['*/sale/product-offers/OFFER1' => Http::sequence()->push([], 200)->push(['sellingMode'=>['price'=>['amount'=>'279.00','currency'=>'PLN']]], 200)]);
        $part = $this->part(); $listing = $this->listing($part, 'allegro', ['external_offer_id'=>'OFFER1']);
        $new = ['allegro'=>['marketplace_price'=>'279.00','marketplace_currency'=>'PLN','source_currency'=>'PLN','source_field'=>'parts.allegro_price']];
        app(PartPriceSyncService::class)->sync($part->fresh(['marketplaceListings.account']), ['allegro'=>['marketplace_price'=>'100.00']], $new);
        Http::assertSent(fn ($r) => $r->method()==='PATCH' && $r->data() === ['sellingMode'=>['price'=>['amount'=>'279.00','currency'=>'PLN']]]);
        $this->assertSame('279.00', (string) $listing->fresh()->price);
    }

    public function test_ovoko_minimal_payload_confirmed_and_listing_updates_only_on_success(): void
    {
        config(['marketplace.price_sync.on_part_save_enabled'=>true,'marketplace.external_api_writes_enabled'=>true,'marketplace.ovoko_publishing_enabled'=>true]);
        Http::fake(['*/crm/updatePart'=>Http::response(['status_code'=>'R200'],200),'*/get/part/OV1'=>Http::response(['data'=>['original_price'=>'135.00','original_currency'=>'PLN']],200)]);
        $part=$this->part(); $listing=$this->listing($part,'ovoko',['external_offer_id'=>'OV1','price'=>100]);
        $res=app(PartPriceSyncService::class)->sync($part->fresh(['marketplaceListings.account']), ['ovoko'=>['marketplace_price'=>'100.00']], ['ovoko'=>['marketplace_price'=>'135.00','marketplace_currency'=>'PLN','source_currency'=>'PLN','source_field'=>'parts.ovoko_price']]);
        $this->assertSame('success',$res['channels']['ovoko']['status']);
        Http::assertSent(fn($r)=>$r->url()==='https://example.test/crm/updatePart' && $r['part_id']==='OV1' && $r['price']==='135.00' && $r['original_currency']==='PLN' && !isset($r['status'],$r['quantity'],$r['category_id'],$r['description'],$r['photos']));
        $this->assertSame('135.00',(string)$listing->fresh()->price);
    }

    public function test_ovoko_unverified_or_mismatch_do_not_update_listing(): void
    {
        config(['marketplace.price_sync.on_part_save_enabled'=>true,'marketplace.external_api_writes_enabled'=>true,'marketplace.ovoko_publishing_enabled'=>true]);
        Http::fake(['*/crm/updatePart'=>Http::response(['status_code'=>'R200'],200),'*/get/part/OV1'=>Http::response(['data'=>['price'=>'9.00','currency'=>'EUR']],200)]);
        $part=$this->part(); $listing=$this->listing($part,'ovoko',['external_offer_id'=>'OV1','price'=>100]);
        $res=app(PartPriceSyncService::class)->sync($part->fresh(['marketplaceListings.account']), ['ovoko'=>['marketplace_price'=>'100.00']], ['ovoko'=>['marketplace_price'=>'135.00','marketplace_currency'=>'PLN']]);
        $this->assertSame('write_accepted_unverified',$res['channels']['ovoko']['status']); $this->assertSame('100.00',(string)$listing->fresh()->price);
    }

    public function test_ebay_de_inventory_flow_uses_remote_quantity_and_guards_publication(): void
    {
        config(['marketplace.price_sync.on_part_save_enabled'=>true,'marketplace.external_api_writes_enabled'=>true,'marketplace.ebay_publishing_enabled'=>true]);
        Http::fake(['*/sell/inventory/v1/offer/OFFERDE'=>Http::sequence()->push(['offerId'=>'OFFERDE','sku'=>'SKUDE','status'=>'PUBLISHED','availableQuantity'=>4,'pricingSummary'=>['price'=>['value'=>'30.00','currency'=>'EUR']]],200)->push(['offerId'=>'OFFERDE','sku'=>'SKUDE','status'=>'PUBLISHED','availableQuantity'=>4,'pricingSummary'=>['price'=>['value'=>'34.65','currency'=>'EUR']]],200),'*/sell/inventory/v1/bulk_update_price_quantity'=>Http::response(['responses'=>[['sku'=>'SKUDE']]],200)]);
        $part=$this->part(); $listing=$this->listing($part,'ebay_de',['external_offer_id'=>'OFFERDE','external_inventory_id'=>'INV','sku'=>'SKUDE','currency'=>'EUR','price'=>30]);
        $res=app(PartPriceSyncService::class)->sync($part->fresh(['marketplaceListings.account']), ['ebay_de'=>['marketplace_price'=>'30.00','marketplace_currency'=>'EUR']], ['ebay_de'=>['marketplace_price'=>'34.65','marketplace_currency'=>'EUR','source_currency'=>'PLN','source_field'=>'parts.ebay_price']]);
        $this->assertSame('success',$res['channels']['ebay_de']['status']); $this->assertTrue($res['channels']['ebay_de']['quantity_unchanged']);
        Http::assertSent(fn($r)=>$r->url()==='https://example.test/sell/inventory/v1/bulk_update_price_quantity' && data_get($r->data(),'requests.0.sku')==='SKUDE' && data_get($r->data(),'requests.0.shipToLocationAvailability.quantity')===4 && data_get($r->data(),'requests.0.pricingSummary.price.value')==='34.65' && !array_key_exists('status',$r->data()));
        $this->assertSame('34.65',(string)$listing->fresh()->price);
    }

    public function test_ebay_ignores_non_de_and_legacy_and_sanitizes_errors(): void
    {
        config(['marketplace.price_sync.on_part_save_enabled'=>true,'marketplace.external_api_writes_enabled'=>true,'marketplace.ebay_publishing_enabled'=>true]);
        $part=$this->part(); $this->listing($part,'ebay_fr'); $this->listing($part,'ebay');
        $res=app(PartPriceSyncService::class)->sync($part->fresh(['marketplaceListings.account']), ['ebay_de'=>['marketplace_price'=>'1.00','marketplace_currency'=>'EUR']], ['ebay_de'=>['marketplace_price'=>'2.00','marketplace_currency'=>'EUR']]);
        $this->assertContains('missing_active_listing',$res['channels']['ebay_de']['blockers']);
        $legacy=$this->listing($part,'ebay_de',['external_offer_id'=>'OFFER','external_inventory_id'=>null]);
        $ctx=app(EbayDePriceSyncAdapter::class)->sync($legacy, ['marketplace_price'=>'2.00','marketplace_currency'=>'EUR']);
        $this->assertSame('skipped',$ctx['status']); $this->assertSame('ebay_legacy_price_sync_not_supported',$ctx['blocker']);
    }

    private function part(array $attrs = []): Part { return Part::query()->create($attrs + ['name'=>'P','sku'=>'SKU1','price'=>100,'allegro_price'=>100,'ovoko_price'=>100,'ebay_price'=>125,'quantity'=>1,'status'=>'ready']); }
    private function listing(Part $part, string $marketplace, array $attrs = []): MarketplaceListing { $account=MarketplaceAccount::query()->create(['marketplace'=>$marketplace,'name'=>'A','status'=>'active','api_enabled'=>true,'api_base_url'=>'https://example.test','api_credentials'=>['token'=>'secret','username'=>'u','password'=>'p','user_token'=>'ut','access_token'=>'at']]); return MarketplaceListing::query()->create($attrs + ['marketplace'=>$marketplace,'marketplace_account_id'=>$account->id,'part_id'=>$part->id,'external_offer_id'=>'EXT','sku'=>'SKU1','price'=>100,'currency'=>'PLN','status'=>'active']); }
}
