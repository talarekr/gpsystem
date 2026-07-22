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

    public function test_ovoko_and_ebay_safety_blockers_and_marketplace_selection(): void
    {
        config(['marketplace.price_sync.on_part_save_enabled'=>true,'marketplace.external_api_writes_enabled'=>true,'marketplace.ovoko_publishing_enabled'=>true,'marketplace.ebay_publishing_enabled'=>true]);
        $part = $this->part(); $this->listing($part, 'ebay_fr'); $this->listing($part, 'ebay'); $this->listing($part, 'ovoko', ['external_offer_id'=>'OV1']);
        $res = app(PartPriceSyncService::class)->sync($part->fresh(['marketplaceListings.account']), ['ovoko'=>['marketplace_price'=>'1.00'],'ebay_de'=>['marketplace_price'=>'1.00','marketplace_currency'=>'EUR']], ['ovoko'=>['marketplace_price'=>'2.00','marketplace_currency'=>'PLN'],'ebay_de'=>['marketplace_price'=>'2.00','marketplace_currency'=>'EUR']]);
        $this->assertSame('blocked', $res['channels']['ovoko']['status']); $this->assertContains('missing_active_listing', $res['channels']['ebay_de']['blockers']);
    }

    private function part(array $attrs = []): Part { return Part::query()->create($attrs + ['name'=>'P','sku'=>'SKU1','price'=>100,'allegro_price'=>100,'ovoko_price'=>100,'ebay_price'=>125,'quantity'=>1,'status'=>'ready']); }
    private function listing(Part $part, string $marketplace, array $attrs = []): MarketplaceListing { $account=MarketplaceAccount::query()->create(['marketplace'=>$marketplace,'name'=>'A','status'=>'active','api_enabled'=>true,'api_credentials'=>['token'=>'secret']]); return MarketplaceListing::query()->create($attrs + ['marketplace'=>$marketplace,'marketplace_account_id'=>$account->id,'part_id'=>$part->id,'external_offer_id'=>'EXT','sku'=>'SKU1','price'=>100,'currency'=>'PLN','status'=>'active']); }
}
