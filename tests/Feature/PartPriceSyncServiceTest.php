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


    public function test_ovoko_real_nested_list_payload_confirms_original_pln_price(): void
    {
        config(['marketplace.price_sync.on_part_save_enabled'=>true,'marketplace.external_api_writes_enabled'=>true,'marketplace.ovoko_publishing_enabled'=>true]);
        Http::fake(['*/crm/updatePart'=>Http::response(['status_code'=>'R200','msg'=>'OK'],200),'*/get/part/11825'=>Http::response(['list'=>[[['id'=>'11825','price'=>'32.94','currency'=>'EUR','original_price'=>'140','original_currency'=>'PLN']]],'msg'=>'OK','status_code'=>'R200'],200)]);
        $part=$this->part(['id'=>8212]); $listing=$this->listing($part,'ovoko',['id'=>23107,'external_offer_id'=>'11825','price'=>'136.00']);
        $res=app(PartPriceSyncService::class)->sync($part->fresh(['marketplaceListings.account']), ['ovoko'=>['marketplace_price'=>'136.00']], ['ovoko'=>['marketplace_price'=>'140.00','marketplace_currency'=>'PLN','source_currency'=>'PLN','source_field'=>'parts.ovoko_price']]);
        $this->assertSame('success',$res['channels']['ovoko']['status']);
        $this->assertTrue($res['channels']['ovoko']['final_success']);
        $this->assertSame('140.00',$res['channels']['ovoko']['remote_confirmed_price']);
        $this->assertSame('140.00',$res['channels']['ovoko']['confirmed_remote_price']);
        $this->assertSame('140.00',(string)$listing->fresh()->price);
    }

    public function test_allegro_406_keeps_full_sanitized_error_and_uses_public_media_type_headers(): void
    {
        config(['marketplace.price_sync.on_part_save_enabled'=>true,'marketplace.external_api_writes_enabled'=>true,'marketplace.allegro_publishing_enabled'=>true]);
        Http::fake(['*/sale/product-offers/18778703976'=>Http::response(['errors'=>[['code'=>'NotAcceptableException','message'=>'Not acceptable','details'=>'media type','path'=>'sellingMode.price','userMessage'=>'Niepoprawne nagłówki']]],406,['trace-id'=>'trace-406'])]);
        $part=$this->part(); $listing=$this->listing($part,'allegro',['external_offer_id'=>'18778703976']);
        $res=app(PartPriceSyncService::class)->sync($part->fresh(['marketplaceListings.account']), ['allegro'=>['marketplace_price'=>'120.00']], ['allegro'=>['marketplace_price'=>'125.00','marketplace_currency'=>'PLN']]);
        $this->assertSame('error',$res['channels']['allegro']['status']);
        $this->assertSame(406,$res['channels']['allegro']['http_status']);
        $this->assertSame('trace-406',data_get($res,'channels.allegro.response_summary.request_id'));
        $this->assertSame('NotAcceptableException',data_get($res,'channels.allegro.response_summary.errors.0.code'));
        Http::assertSent(fn($r)=>$r->method()==='PATCH' && $r->url()==='https://example.test/sale/product-offers/18778703976' && $r->hasHeader('Accept','application/vnd.allegro.public.v1+json') && $r->hasHeader('Content-Type','application/vnd.allegro.public.v1+json') && $r->hasHeader('Authorization','Bearer at') && $r->data()===['sellingMode'=>['price'=>['amount'=>'125.00','currency'=>'PLN']]]);
    }

    public function test_ebay_read_retry_confirms_after_delayed_propagation_without_second_write(): void
    {
        config(['marketplace.price_sync.on_part_save_enabled'=>true,'marketplace.external_api_writes_enabled'=>true,'marketplace.ebay_publishing_enabled'=>true,'marketplace.price_sync.ebay_read_retry_attempts'=>3,'marketplace.price_sync.ebay_read_retry_delay_ms'=>0]);
        Http::fake(['*/sell/inventory/v1/offer/OFFERDE'=>Http::sequence()->push(['offerId'=>'OFFERDE','sku'=>'SKUDE','status'=>'PUBLISHED','availableQuantity'=>1,'pricingSummary'=>['price'=>['value'=>'34.55','currency'=>'EUR']]],200)->push(['offerId'=>'OFFERDE','sku'=>'SKUDE','status'=>'PUBLISHED','availableQuantity'=>1,'pricingSummary'=>['price'=>['value'=>'34.55','currency'=>'EUR']]],200)->push(['offerId'=>'OFFERDE','sku'=>'SKUDE','status'=>'PUBLISHED','availableQuantity'=>1,'pricingSummary'=>['price'=>['value'=>'36.09','currency'=>'EUR']]],200),'*/sell/inventory/v1/bulk_update_price_quantity'=>Http::response(['responses'=>[['sku'=>'SKUDE','inputRefId'=>'SKUDE','statusCode'=>200,'warnings'=>[],'errors'=>[]]]],200,['x-ebay-c-request-id'=>'rid-ebay'])]);
        $part=$this->part(); $listing=$this->listing($part,'ebay_de',['external_offer_id'=>'OFFERDE','external_inventory_id'=>'INV','sku'=>'SKUDE','currency'=>'EUR','price'=>34.55]);
        $res=app(PartPriceSyncService::class)->sync($part->fresh(['marketplaceListings.account']), ['ebay_de'=>['marketplace_price'=>'34.55','marketplace_currency'=>'EUR']], ['ebay_de'=>['marketplace_price'=>'36.09','marketplace_currency'=>'EUR']]);
        $this->assertSame('success',$res['channels']['ebay_de']['status']);
        $this->assertCount(2,data_get($res,'channels.ebay_de.read_after_write.attempts'));
        $this->assertSame(1, count(Http::recorded(fn($r)=>$r->url()==='https://example.test/sell/inventory/v1/bulk_update_price_quantity')));
        $this->assertSame('36.09',(string)$listing->fresh()->price);
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
