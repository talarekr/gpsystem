<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Services\Marketplace\AllegroCompatibilitySuggestionsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AllegroCompatibilitySuggestionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('allegro_compatibility_supported_categories');
    }

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
        $this->account(); Http::fake(['*/supported-categories*'=>Http::response(['categories'=>[['id'=>'620','validationRules'=>['maxRows'=>10]]]],200),'*/compatibility-list-suggestions*'=>Http::response(['items'=>[]],404)]); $svc->preview($part, $this->payload()); Http::assertSent(fn($r)=>$r->method()==='GET'); $this->assertSame($before, $part->fresh()->review_metadata); $this->assertSame(0, MarketplaceSyncLog::query()->count());
    }


    public function test_category_max_rows_2000_truncates_2001_results_and_records_warning(): void
    {
        $this->account();
        $items = array_map(fn ($i) => ['type' => 'ID', 'id' => 'car-'.$i], range(1, 2001));
        Http::fake([
            'https://api.allegro.test/sale/compatibility-list/supported-categories*' => Http::response(['categories'=>[['id'=>'620','inputType'=>'ID','itemsType'=>'CAR','validationRules'=>['maxRows'=>2000]]]], 200),
            'https://api.allegro.test/sale/compatibility-list-suggestions*' => Http::response(['compatibilityList'=>['items'=>$items]], 200),
        ]);
        $part=Part::query()->create(['name'=>'Part','price'=>100,'quantity'=>1]);

        app(AllegroCompatibilitySuggestionsService::class)->fetchAndStoreForPreparedPayload($part, $this->payload());
        $meta=data_get($part->fresh()->review_metadata, AllegroCompatibilitySuggestionsService::META_PATH);

        $this->assertSame(2001, $meta['returned_count']);
        $this->assertSame(2000, $meta['stored_count']);
        $this->assertSame(2000, $meta['max_rows']);
        $this->assertSame('ID', $meta['input_type']);
        $this->assertSame('CAR', $meta['items_type']);
        $this->assertTrue($meta['truncated']);
        $this->assertSame('allegro_compatibility_items_truncated_to_category_max_rows', $meta['warning']);
        $this->assertSame('car-1', $meta['compatibilityList']['items'][0]['id']);
        $this->assertSame('car-2000', $meta['compatibilityList']['items'][1999]['id']);
    }

    public function test_each_category_uses_its_own_max_rows_and_parent_supported_category_config(): void
    {
        $this->account();
        MarketplaceCategory::query()->create(['channel'=>'allegro_main','external_category_id'=>'620','name'=>'Parent']);
        MarketplaceCategory::query()->create(['channel'=>'allegro_main','external_category_id'=>'312565','parent_external_category_id'=>'620','name'=>'Child']);
        $items = array_map(fn ($i) => ['type' => 'ID', 'id' => 'truck-'.$i], range(1, 4));
        Http::fake([
            'https://api.allegro.test/sale/compatibility-list/supported-categories*' => Http::response(['categories'=>[
                ['id'=>'620','inputType'=>'ID','itemsType'=>'CAR','validationRules'=>['maxRows'=>3]],
                ['id'=>'999','inputType'=>'ID','itemsType'=>'MOTORCYCLE','validationRules'=>['maxRows'=>1]],
            ]], 200),
            'https://api.allegro.test/sale/compatibility-list-suggestions*' => Http::response(['items'=>$items], 200),
        ]);
        $part=Part::query()->create(['name'=>'Part','price'=>100,'quantity'=>1]);

        app(AllegroCompatibilitySuggestionsService::class)->fetchAndStoreForPreparedPayload($part, ['category_id'=>'312565','productSet'=>[['product'=>['id'=>'prod-1']]]]);
        $meta=data_get($part->fresh()->review_metadata, AllegroCompatibilitySuggestionsService::META_PATH);

        $this->assertSame(3, $meta['max_rows']);
        $this->assertSame(3, $meta['stored_count']);
        $this->assertSame('CAR', $meta['items_type']);
        $this->assertSame(['truck-1','truck-2','truck-3'], array_column($meta['compatibilityList']['items'], 'id'));
    }

    public function test_text_items_respect_max_characters_per_line(): void
    {
        $list = app(AllegroCompatibilitySuggestionsService::class)->normalizeList([
            ['type'=>'TEXT','text'=>'ABCDEFGHIJ'],
        ], ['max_rows'=>5, 'max_characters_per_line'=>4]);

        $this->assertSame('ABCD', $list['items'][0]['text']);
    }

    public function test_prepare_request_semantics_and_no_write_methods(): void
    {
        $this->account();
        Http::fake([
            'https://api.allegro.test/sale/compatibility-list/supported-categories*' => Http::response(['categories'=>[['id'=>'620','inputType'=>'ID','itemsType'=>'CAR','validationRules'=>['maxRows'=>2]]]], 200),
            'https://api.allegro.test/sale/compatibility-list-suggestions*' => Http::response(['items'=>[['type'=>'ID','id'=>'car-1']]], 200),
        ]);
        $part=Part::query()->create(['name'=>'Part','price'=>100,'quantity'=>1]);
        $preview=app(AllegroCompatibilitySuggestionsService::class)->preview($part, $this->payload());

        $this->assertTrue($preview['external_requests']);
        $this->assertSame(['GET'], $preview['external_request_methods']);
        $this->assertFalse($preview['marketplace_write']);
        $this->assertFalse($preview['publish']);
        $this->assertTrue($preview['no_marketplace_mutation']);
        Http::assertNotSent(fn($r)=>in_array($r->method(), ['POST','PATCH','PUT','DELETE'], true));
    }

    public function test_no_product_id_preview_has_no_external_requests(): void
    {
        $part=Part::query()->create(['name'=>'Part','price'=>100,'quantity'=>1]);
        Http::fake(fn()=> $this->fail('missing product id must not call HTTP'));

        $preview=app(AllegroCompatibilitySuggestionsService::class)->preview($part, ['category_id'=>'620']);

        $this->assertFalse($preview['external_requests']);
        $this->assertSame([], $preview['external_request_methods']);
    }

    public function test_unsupported_category_skips_suggestions_get(): void
    {
        $this->account();
        Http::fake([
            'https://api.allegro.test/sale/compatibility-list/supported-categories*' => Http::response(['categories'=>[['id'=>'999','validationRules'=>['maxRows'=>1]]]], 200),
            'https://api.allegro.test/sale/compatibility-list-suggestions*' => Http::response(['items'=>[['type'=>'ID','id'=>'bad']]], 200),
        ]);
        $part=Part::query()->create(['name'=>'Part','price'=>100,'quantity'=>1]);

        app(AllegroCompatibilitySuggestionsService::class)->fetchAndStoreForPreparedPayload($part, $this->payload());

        Http::assertNotSent(fn($r)=>str_contains($r->url(), '/sale/compatibility-list-suggestions'));
        Http::assertNotSent(fn($r)=>in_array($r->method(), ['POST','PATCH','PUT','DELETE'], true));
    }

    private function payload(): array { return ['category_id'=>'620','productSet'=>[['product'=>['id'=>'prod-1','parameters'=>[['id'=>'p','values'=>['v']]]]]],'price_pln'=>'100','quantity'=>1,'description'=>['sections'=>[]],'images'=>['https://img.test/1.jpg'],'publication'=>['status'=>'ACTIVE']]; }
    private function account(): void { MarketplaceAccount::query()->updateOrCreate(['code'=>'allegro_main'], ['marketplace'=>'allegro','name'=>'Allegro','status'=>'active','api_enabled'=>true,'api_base_url'=>'https://api.allegro.test','api_credentials'=>['access_token'=>'token']]); }
}
