<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Services\Marketplace\AllegroCompatibilitySuggestionsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AllegroCompatibilitySuggestionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_prepare_compatibility_fetch_stores_deduplicates_and_preserves_additional_info(): void
    {
        $this->account(); Http::fake([
            'https://api.allegro.test/sale/compatibility-list/supported-categories*' => Http::response(['categories'=>[['id'=>'620']]], 200),
            'https://api.allegro.test/sale/compatibility-list-suggestions*' => Http::response(['compatibilityList'=>['items'=>[
                ['type'=>'ID','id'=>'car-1','additionalInfo'=>[['name'=>'engine','value'=>'2.0']]],
                ['type'=>'ID','id'=>'car-1','additionalInfo'=>[['name'=>'engine','value'=>'2.0']]],
                ['type'=>'TEXT','text'=>'BMW 3 E90 2005-2011','additionalInfo'=>['body'=>'sedan']],
            ]]], 200, ['trace-id'=>'req-1']),
        ]);
        $part=Part::query()->create(['name'=>'Part','part_number'=>'P1','price'=>100,'quantity'=>1]);
        $r=app(AllegroCompatibilitySuggestionsService::class)->fetchAndStoreForPreparedPayload($part, $this->payload());
        $meta=data_get($part->fresh()->review_metadata, AllegroCompatibilitySuggestionsService::META_PATH);
        $this->assertSame('suggestions_found',$r['compatibility']['status']);
        $this->assertCount(2, $meta['compatibilityList']['items']);
        $this->assertSame([['name'=>'engine','value'=>'2.0']], $meta['compatibilityList']['items'][0]['additionalInfo']);
        Http::assertSent(fn($req)=>str_contains($req->url(), '/sale/compatibility-list-suggestions') && $req['product.id']==='prod-1');
        Http::assertNotSent(fn($req)=>$req->method()==='POST' || $req->method()==='PATCH');
    }

    public function test_missing_product_empty_error_and_unsupported_category_do_not_block(): void
    {
        $part=Part::query()->create(['name'=>'Part','price'=>100,'quantity'=>1]);
        $r=app(AllegroCompatibilitySuggestionsService::class)->fetchAndStoreForPreparedPayload($part, ['category_id'=>'620']);
        $this->assertTrue($r['ok']); $this->assertSame('no_product_id',$r['compatibility']['status']);
        $this->account(); Http::fake(['*/supported-categories*'=>Http::response(['categories'=>[['id'=>'999']]],200)]);
        $r=app(AllegroCompatibilitySuggestionsService::class)->fetchAndStoreForPreparedPayload($part->fresh(), $this->payload());
        $this->assertSame('category_unsupported',$r['compatibility']['status']);
    }

    public function test_publishable_list_requires_current_product_and_category(): void
    {
        $part=Part::query()->create(['name'=>'Part','price'=>100,'quantity'=>1,'review_metadata'=>['marketplace_prepare_results'=>['allegro'=>['compatibility'=>['status'=>'suggestions_found','source'=>'product_suggestions','product_id'=>'prod-1','category_id'=>'620','compatibilityList'=>['type'=>'MANUAL','items'=>[['type'=>'ID','id'=>'car-1','additionalInfo'=>[]]]]]]]]]);
        $svc=app(AllegroCompatibilitySuggestionsService::class);
        $this->assertNotNull($svc->publishableCompatibilityList($part, $this->payload()));
        $this->assertNull($svc->publishableCompatibilityList($part, ['category_id'=>'620','productSet'=>[['product'=>['id'=>'other']]]]));
    }

    public function test_audit_has_no_http_or_mutation_and_preview_only_gets(): void
    {
        $part=Part::query()->create(['name'=>'Part','price'=>100,'quantity'=>1]); $svc=app(AllegroCompatibilitySuggestionsService::class);
        Http::fake(fn()=> $this->fail('audit must not call HTTP')); $before=$part->review_metadata; $svc->audit($part, $this->payload()); $this->assertSame($before, $part->fresh()->review_metadata);
        $this->account(); Http::fake(['*/compatibility-list-suggestions*'=>Http::response(['items'=>[]],404)]); $svc->preview($part, $this->payload()); Http::assertSent(fn($r)=>$r->method()==='GET'); $this->assertSame($before, $part->fresh()->review_metadata); $this->assertSame(0, MarketplaceSyncLog::query()->count());
    }

    private function payload(): array { return ['category_id'=>'620','productSet'=>[['product'=>['id'=>'prod-1','parameters'=>[['id'=>'p','values'=>['v']]]]]],'price_pln'=>'100','quantity'=>1,'description'=>['sections'=>[]],'images'=>['https://img.test/1.jpg'],'publication'=>['status'=>'ACTIVE']]; }
    private function account(): void { MarketplaceAccount::query()->updateOrCreate(['code'=>'allegro_main'], ['marketplace'=>'allegro','name'=>'Allegro','status'=>'active','api_enabled'=>true,'api_base_url'=>'https://api.allegro.test','api_credentials'=>['access_token'=>'token']]); }
}
