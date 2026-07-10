<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\EbayListingStatusScanResult;
use App\Models\EbayListingStatusScanRun;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EbayListingStatusPersistentScanTest extends TestCase
{
    use RefreshDatabase;

    public function test_persistent_scan_full_flow_persists_results_retries_rate_limits_and_ended_report(): void
    {
        $this->actingAsAdminUser(); $this->account();
        $active=$this->listing('111111111111'); $ended=$this->listing('222222222222'); $rate=$this->listing('333333333333'); $notFound=$this->listing('444444444444');
        $before=MarketplaceListing::query()->orderBy('id')->get()->keyBy('id')->map(fn($l)=>$l->getAttributes())->all();
        $calls=[];
        Http::fake(function($request) use (&$calls) { $url=(string)$request->url(); $calls[]=$url; return match(true){
            str_contains($url,'111111111111')=>Http::response(['estimatedAvailabilities'=>[['estimatedAvailabilityStatus'=>'IN_STOCK']]],200),
            str_contains($url,'222222222222')=>Http::response(['itemEndDate'=>'2026-01-01T00:00:00.000Z'],200),
            str_contains($url,'333333333333')=>Http::response([],429,['Retry-After'=>'60']),
            default=>Http::response([],404),
        };});

        $start=$this->postJson('/admin/tools/ebay/listing-status-sync/start-persistent-scan',['batch_size'=>3,'delay_seconds'=>15,'scope'=>'products_with_ebay_item_id','dry_run'=>true,'stop_on_rate_limit'=>true,'max_attempts_per_item'=>3,'persist_full_report'=>true,'confirm'=>'start-persistent-ebay-listing-status-scan'])->assertOk()->assertJsonPath('status','running')->assertJsonPath('total',4)->json();
        $runId=$start['scan_run_id'];
        $this->assertDatabaseHas('ebay_listing_status_scan_runs',['id'=>$runId,'dry_run'=>true,'total'=>4]);
        $this->assertSame(4, EbayListingStatusScanResult::where('scan_run_id',$runId)->count());
        $this->assertDatabaseHas('ebay_listing_status_scan_results',['scan_run_id'=>$runId,'marketplace_listing_id'=>$active->id,'attempts'=>0]);

        $first=$this->postJson('/admin/tools/ebay/listing-status-sync/persistent-scan/run-next-batch',['confirm'=>'run-next-persistent-ebay-listing-status-scan-batch'])->assertOk()->assertJsonPath('status','waiting_rate_limit')->json();
        $this->assertGreaterThanOrEqual(60,$first['retry_after_seconds']);
        $this->assertCount(3,$calls);
        $this->assertFalse(collect($calls)->contains(fn($url)=>str_contains($url,'444444444444')), 'next listing after 429 must not be requested');
        $this->assertDatabaseHas('ebay_listing_status_scan_results',['scan_run_id'=>$runId,'marketplace_listing_id'=>$ended->id,'normalized_status'=>'ended','http_status'=>200,'error_type'=>null]);
        $this->assertDatabaseHas('ebay_listing_status_scan_results',['scan_run_id'=>$runId,'marketplace_listing_id'=>$rate->id,'normalized_status'=>'unknown','http_status'=>429,'error_type'=>'rate_limited','attempts'=>1]);
        $this->postJson('/admin/tools/ebay/listing-status-sync/persistent-scan/run-next-batch',['confirm'=>'run-next-persistent-ebay-listing-status-scan-batch'])->assertOk()->assertJsonPath('reason','rate_limit_wait')->assertJsonPath('batch_executed',false);
        $this->assertCount(3,$calls);

        Http::fake(function($request) use (&$calls) { $url=(string)$request->url(); $calls[]=$url; return str_contains($url,'333333333333') ? Http::response([],500) : Http::response([],404); });
        $this->travel(65)->seconds();
        $this->postJson('/admin/tools/ebay/listing-status-sync/persistent-scan/run-next-batch',['confirm'=>'run-next-persistent-ebay-listing-status-scan-batch'])->assertOk()->assertJsonPath('remaining',2);
        $this->travel(65)->seconds();
        $this->postJson('/admin/tools/ebay/listing-status-sync/persistent-scan/run-next-batch',['confirm'=>'run-next-persistent-ebay-listing-status-scan-batch'])->assertOk()->assertJsonPath('remaining',1);
        $this->travel(65)->seconds();
        $this->postJson('/admin/tools/ebay/listing-status-sync/persistent-scan/run-next-batch',['confirm'=>'run-next-persistent-ebay-listing-status-scan-batch'])->assertOk()->assertJsonPath('status','completed')->assertJsonPath('remaining',0);
        $this->assertSame(1, EbayListingStatusScanResult::where('scan_run_id',$runId)->where('marketplace_listing_id',$rate->id)->count());
        $this->assertGreaterThanOrEqual(3, EbayListingStatusScanResult::where('scan_run_id',$runId)->where('marketplace_listing_id',$rate->id)->value('attempts'));
        $this->assertDatabaseHas('ebay_listing_status_scan_results',['scan_run_id'=>$runId,'marketplace_listing_id'=>$notFound->id,'normalized_status'=>'not_found']);

        Http::fake(fn()=>throw new \RuntimeException('eBay API must not be called'));
        $this->getJson('/admin/tools/ebay/listing-status-sync/persistent-scan/diagnose?json=1')->assertOk()->assertJsonPath('no_ebay_request',true);
        $this->getJson('/admin/tools/ebay/listing-status-sync/persistent-scan/ended-results?scan_run_id='.$runId.'&json=1')->assertOk()->assertJsonPath('full_ended_id_list_available',true)->assertJsonPath('ended_count',1)->assertJsonPath('unique_marketplace_listing_ids',1)->assertJsonPath('can_apply_confirmed_ended',true)->assertJsonPath('sample.0.marketplace_listing_id',$ended->id)->assertJsonPath('no_mutation',true)->assertJsonPath('no_ebay_request',true);
        $this->assertSame($before, MarketplaceListing::query()->orderBy('id')->get()->keyBy('id')->map(fn($l)=>$l->getAttributes())->all());
        Http::assertSentCount(0);
    }

    public function test_unique_constraint_prevents_duplicate_results_and_first_report_is_not_overwritten(): void
    {
        $this->actingAsAdminUser(); $listing=$this->listing('555555555555');
        $run1=EbayListingStatusScanRun::create(['status'=>'completed','dry_run'=>true,'total'=>1,'processed'=>1,'remaining'=>0,'ended'=>1]);
        EbayListingStatusScanResult::create(['scan_run_id'=>$run1->id,'marketplace_listing_id'=>$listing->id,'part_id'=>$listing->part_id,'ebay_item_id'=>'555555555555','normalized_status'=>'ended','http_status'=>200,'attempts'=>1,'checked_at'=>now()]);
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        EbayListingStatusScanResult::create(['scan_run_id'=>$run1->id,'marketplace_listing_id'=>$listing->id,'normalized_status'=>'active']);
    }

    public function test_session_can_resume_after_refresh_and_old_report_remains_available(): void
    {
        $this->actingAsAdminUser(); $this->account(); $old=$this->listing('666666666666'); $new=$this->listing('777777777777');
        $oldRun=EbayListingStatusScanRun::create(['status'=>'completed','dry_run'=>true,'total'=>1,'processed'=>1,'remaining'=>0,'ended'=>1]);
        EbayListingStatusScanResult::create(['scan_run_id'=>$oldRun->id,'marketplace_listing_id'=>$old->id,'part_id'=>$old->part_id,'ebay_item_id'=>'666666666666','normalized_status'=>'ended','http_status'=>200,'attempts'=>1,'checked_at'=>now()]);
        $run=EbayListingStatusScanRun::create(['status'=>'running','dry_run'=>true,'total'=>1,'processed'=>0,'remaining'=>1,'settings'=>['batch_size'=>1,'max_attempts_per_item'=>3]]);
        EbayListingStatusScanResult::create(['scan_run_id'=>$run->id,'marketplace_listing_id'=>$new->id,'part_id'=>$new->part_id,'ebay_item_id'=>'777777777777','normalized_status'=>'unknown']);
        $this->getJson('/admin/tools/ebay/listing-status-sync/persistent-scan/status?json=1')->assertOk()->assertJsonPath('scan_run_id',$run->id)->assertJsonPath('status','running');
        $this->getJson('/admin/tools/ebay/listing-status-sync/persistent-scan/ended-results?scan_run_id='.$oldRun->id.'&json=1')->assertOk()->assertJsonPath('ended_count',1)->assertJsonPath('sample.0.marketplace_listing_id',$old->id);
    }

    private function listing(string $itemId, array $overrides=[]): MarketplaceListing { $part=Part::create(['name'=>'Part '.$itemId,'sku'=>'SKU-'.$itemId,'quantity'=>1,'status'=>'ready']); return MarketplaceListing::create(array_merge(['part_id'=>$part->id,'marketplace'=>'ebay_de','external_listing_id'=>$itemId,'status'=>'published'],$overrides)); }
    private function account(): void { MarketplaceAccount::create(['code'=>'ebay_de','marketplace'=>'ebay_de','name'=>'eBay DE','status'=>'active','api_enabled'=>true,'api_base_url'=>'https://api.ebay.test','api_credentials'=>['access_token'=>'token'],'api_settings'=>['marketplace_id'=>'EBAY_DE']]); }
    private function actingAsAdminUser(): User { $this->seed(RoleSeeder::class); app(PermissionRegistrar::class)->forgetCachedPermissions(); $user=User::create(['name'=>'Owner Admin','email'=>uniqid('admin').'@example.test','password'=>'password']); $user->assignRole(UserRole::OwnerAdmin->value); $this->actingAs($user); Filament::setCurrentPanel(Filament::getPanel('admin')); return $user; }
}
