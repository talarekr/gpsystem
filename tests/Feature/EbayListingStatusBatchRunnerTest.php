<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Models\User;
use App\Services\Marketplace\EbayListingStatusBatchRunnerService;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EbayListingStatusBatchRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_creates_consistent_running_state_with_payload_values(): void
    {
        $this->actingAsAdminUser();
        $listings = [$this->listing('101010101010'), $this->listing('202020202020'), $this->listing('303030303030')];

        $this->postJson('/admin/tools/ebay/listing-status-sync/start', [
            'batch_size' => 5, 'delay_seconds' => 10, 'scope' => 'products_with_ebay_item_id', 'dry_run' => true, 'confirm' => 'start-ebay-listing-status-sync',
        ])->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'running')
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('batch_size', 5)
            ->assertJsonPath('delay_seconds', 10)
            ->assertJsonPath('total', 3)
            ->assertJsonPath('processed', 0)
            ->assertJsonPath('remaining', 3)
            ->assertJsonPath('completed', false)
            ->assertJsonPath('state_initialization_fix_marker', 'ebay_listing_status_batch_runner_state_initialization_fix_v3')
            ->assertJsonPath('delay_countdown_fix_marker', 'ebay_listing_status_batch_runner_delay_countdown_fix_v4')
            ->assertJsonStructure(['started_at']);

        $state = Cache::get(EbayListingStatusBatchRunnerService::CACHE_KEY);
        $this->assertSame(3, $state['total']);
        $this->assertSame(3, count($state['remaining_ids']));
        $this->assertSame($listings[0]->id, $state['remaining_ids'][0]);
        $this->assertSame([], $state['processed_ids']);
        $this->assertNotNull($state['started_at']);
        $this->assertNull($state['finished_at']);
        $this->assertNull($state['last_batch_at']);
    }

    public function test_status_base_state_does_not_overwrite_saved_state(): void
    {
        $this->actingAsAdminUser();
        Cache::put(EbayListingStatusBatchRunnerService::CACHE_KEY, [
            'status' => 'running', 'batch_size' => 5, 'delay_seconds' => 10, 'total' => 3, 'processed' => 0, 'remaining' => 3,
            'remaining_ids' => [11, 22, 33], 'processed_ids' => [], 'started_at' => '2026-07-10T16:00:00Z',
        ], now()->addHour());

        $this->getJson('/admin/tools/ebay/listing-status-sync/status')
            ->assertOk()
            ->assertJsonPath('status', 'running')
            ->assertJsonPath('batch_size', 5)
            ->assertJsonPath('delay_seconds', 10)
            ->assertJsonPath('total', 3)
            ->assertJsonPath('remaining', 3)
            ->assertJsonPath('started_at', '2026-07-10T16:00:00Z');
    }

    public function test_completed_state_with_remaining_items_is_reported_inconsistent_without_mutating_cache(): void
    {
        $this->actingAsAdminUser();
        $broken = [
            'status' => 'completed', 'batch_size' => 5, 'delay_seconds' => 10, 'total' => 3, 'processed' => 0, 'remaining' => 3,
            'remaining_ids' => [11, 22, 33], 'processed_ids' => [], 'started_at' => null, 'finished_at' => '2026-07-10T16:32:43Z',
        ];
        Cache::put(EbayListingStatusBatchRunnerService::CACHE_KEY, $broken, now()->addHour());

        $this->getJson('/admin/tools/ebay/listing-status-sync/status')
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('remaining', 3)
            ->assertJsonPath('state_inconsistent', true)
            ->assertJsonPath('reason', 'completed_with_remaining_items');

        $this->assertSame($broken, Cache::get(EbayListingStatusBatchRunnerService::CACHE_KEY));
    }

    public function test_new_start_overwrites_broken_completed_state_and_returns_fresh_running_state(): void
    {
        $this->actingAsAdminUser();
        $this->listing('404040404040');
        $this->listing('505050505050');
        Cache::put(EbayListingStatusBatchRunnerService::CACHE_KEY, [
            'status' => 'completed', 'total' => 0, 'processed' => 0, 'remaining' => 2, 'remaining_ids' => [1, 2], 'processed_ids' => [],
        ], now()->addHour());

        $this->postJson('/admin/tools/ebay/listing-status-sync/start', [
            'batch_size' => 5, 'delay_seconds' => 10, 'scope' => 'products_with_ebay_item_id', 'dry_run' => true, 'confirm' => 'start-ebay-listing-status-sync',
        ])->assertOk()
            ->assertJsonPath('status', 'running')
            ->assertJsonPath('batch_size', 5)
            ->assertJsonPath('delay_seconds', 10)
            ->assertJsonPath('total', 2)
            ->assertJsonPath('remaining', 2)
            ->assertJsonPath('completed', false)
            ->assertJsonMissing(['state_inconsistent' => true]);

        $state = Cache::get(EbayListingStatusBatchRunnerService::CACHE_KEY);
        $this->assertSame('running', $state['status']);
        $this->assertSame(2, $state['total']);
        $this->assertSame($state['total'], count($state['remaining_ids']));
    }

    public function test_controller_diagnose_reads_same_cache_key_as_runner_endpoints(): void
    {
        $this->actingAsAdminUser();
        Cache::put(EbayListingStatusBatchRunnerService::CACHE_KEY, ['status' => 'running'], now()->addHour());

        $this->getJson('/admin/tools/ebay/listing-status-sync/diagnose?json=1')
            ->assertOk()
            ->assertJsonPath('current_runner_state_readable', true);
    }

    public function test_dry_run_start_clamps_batch_size_and_delay_and_stop(): void
    {
        $this->actingAsAdminUser();
        $this->listing('111111111111');

        $this->postJson('/admin/tools/ebay/listing-status-sync/start', [
            'batch_size' => 99, 'delay_seconds' => 1, 'scope' => 'products_with_ebay_item_id', 'dry_run' => true, 'confirm' => 'start-ebay-listing-status-sync',
        ])->assertOk()->assertJsonPath('status', 'running')->assertJsonPath('dry_run', true)->assertJsonPath('batch_size', 20)->assertJsonPath('delay_seconds', 5)->assertJsonPath('total', 1);

        $this->postJson('/admin/tools/ebay/listing-status-sync/stop', ['confirm' => 'stop-ebay-listing-status-sync'])
            ->assertOk()->assertJsonPath('status', 'stopped');
    }

    public function test_run_next_batch_counts_statuses_continues_after_one_error_and_does_not_mutate_listings(): void
    {
        $this->actingAsAdminUser();
        $this->account();
        $active = $this->listing('111111111111', ['status' => 'published']);
        $ended = $this->listing('222222222222', ['status' => 'published']);
        $missing = $this->listing('333333333333', ['status' => 'published']);
        $unknown = $this->listing('444444444444', ['status' => 'published']);

        Http::fake(function ($request) {
            $url = (string) $request->url();
            return match (true) {
                str_contains($url, '111111111111') => Http::response(['estimatedAvailabilities' => [['estimatedAvailabilityStatus' => 'IN_STOCK']]], 200),
                str_contains($url, '222222222222') => Http::response(['itemEndDate' => '2026-05-31T10:00:00.000Z'], 200),
                str_contains($url, '333333333333') => Http::response(['errors' => []], 404),
                default => Http::response(['errors' => []], 500),
            };
        });

        $this->postJson('/admin/tools/ebay/listing-status-sync/start', [
            'batch_size' => 4, 'delay_seconds' => 5, 'scope' => 'products_with_ebay_item_id', 'dry_run' => true, 'confirm' => 'start-ebay-listing-status-sync',
        ])->assertOk();

        $this->postJson('/admin/tools/ebay/listing-status-sync/run-next-batch', ['confirm' => 'run-next-ebay-listing-status-sync-batch'])
            ->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('processed', 4)->assertJsonPath('active', 1)->assertJsonPath('ended', 1)->assertJsonPath('not_found', 1)->assertJsonPath('unknown', 1)->assertJsonPath('failed', 1);

        foreach ([$active, $ended, $missing, $unknown] as $listing) {
            $fresh = $listing->fresh();
            $this->assertSame('published', $fresh->status);
            $this->assertNull($fresh->last_api_status);
            $this->assertNull($fresh->last_synced_at);
            $this->assertNull($fresh->not_seen_in_active_api_at);
        }
    }

    public function test_run_next_batch_skips_already_processed_records_and_does_not_run_after_completed(): void
    {
        $this->actingAsAdminUser();
        $this->account();
        $this->listing('555555555555');
        $this->listing('666666666666');
        $calls = 0;
        Http::fake(function () use (&$calls) { $calls++; return Http::response(['estimatedAvailabilities' => [['estimatedAvailabilityStatus' => 'IN_STOCK']]], 200); });

        $this->postJson('/admin/tools/ebay/listing-status-sync/start', ['batch_size' => 1, 'delay_seconds' => 5, 'scope' => 'products_with_ebay_item_id', 'dry_run' => true, 'confirm' => 'start-ebay-listing-status-sync'])->assertOk();
        $this->postJson('/admin/tools/ebay/listing-status-sync/run-next-batch', ['confirm' => 'run-next-ebay-listing-status-sync-batch'])->assertOk()->assertJsonPath('processed', 1)->assertJsonPath('remaining', 1);
        $this->travel(5)->seconds();
        $this->postJson('/admin/tools/ebay/listing-status-sync/run-next-batch', ['confirm' => 'run-next-ebay-listing-status-sync-batch'])->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('processed', 2);
        $this->postJson('/admin/tools/ebay/listing-status-sync/run-next-batch', ['confirm' => 'run-next-ebay-listing-status-sync-batch'])->assertStatus(422)->assertJsonPath('reason', 'not_running');
        $this->assertSame(2, $calls);
    }


    public function test_run_next_batch_respects_delay_and_returns_retry_after_seconds(): void
    {
        $this->actingAsAdminUser();
        $this->account();
        $this->listing('777777777777');
        $this->listing('888888888888');
        $calls = 0;
        Http::fake(function () use (&$calls) { $calls++; return Http::response(['estimatedAvailabilities' => [['estimatedAvailabilityStatus' => 'IN_STOCK']]], 200); });

        $this->postJson('/admin/tools/ebay/listing-status-sync/start', ['batch_size' => 1, 'delay_seconds' => 10, 'scope' => 'products_with_ebay_item_id', 'dry_run' => true, 'confirm' => 'start-ebay-listing-status-sync'])->assertOk()->assertJsonPath('status', 'running');
        $this->postJson('/admin/tools/ebay/listing-status-sync/run-next-batch', ['confirm' => 'run-next-ebay-listing-status-sync-batch'])->assertOk()->assertJsonPath('batch_executed', true)->assertJsonPath('remaining', 1);
        $afterBatch = $this->postJson('/admin/tools/ebay/listing-status-sync/run-next-batch', ['confirm' => 'run-next-ebay-listing-status-sync-batch'])
            ->assertOk()
            ->assertJsonPath('reason', 'delay_not_elapsed')
            ->assertJsonPath('batch_executed', false)
            ->assertJsonPath('should_wait', true)
            ->assertJsonPath('completed', false)
            ->assertJsonStructure(['server_now', 'last_batch_at', 'delay_seconds', 'next_batch_allowed_at', 'retry_after_seconds', 'clock_skew_detected']);
        $this->assertGreaterThanOrEqual(9, $afterBatch->json('retry_after_seconds'));
        $this->assertLessThanOrEqual(10, $afterBatch->json('retry_after_seconds'));

        $this->travel(5)->seconds();
        $afterFiveSeconds = $this->postJson('/admin/tools/ebay/listing-status-sync/run-next-batch', ['confirm' => 'run-next-ebay-listing-status-sync-batch'])
            ->assertOk()
            ->assertJsonPath('reason', 'delay_not_elapsed')
            ->assertJsonPath('batch_executed', false);
        $this->assertGreaterThanOrEqual(4, $afterFiveSeconds->json('retry_after_seconds'));
        $this->assertLessThanOrEqual(5, $afterFiveSeconds->json('retry_after_seconds'));

        $this->travel(5)->seconds();
        $this->postJson('/admin/tools/ebay/listing-status-sync/run-next-batch', ['confirm' => 'run-next-ebay-listing-status-sync-batch'])
            ->assertOk()
            ->assertJsonPath('batch_executed', true)
            ->assertJsonPath('retry_after_seconds', 0)
            ->assertJsonPath('completed', true);
        $this->assertSame(2, $calls);
    }

    public function test_retry_after_reports_clock_skew_instead_of_using_arbitrary_waits(): void
    {
        $this->actingAsAdminUser();
        Cache::put(EbayListingStatusBatchRunnerService::CACHE_KEY, [
            'status' => 'running', 'batch_size' => 5, 'delay_seconds' => 10, 'total' => 1, 'processed' => 0, 'remaining' => 1,
            'remaining_ids' => [123], 'processed_ids' => [], 'last_batch_at' => now()->addSeconds(72)->toISOString(),
        ], now()->addHour());

        $response = $this->postJson('/admin/tools/ebay/listing-status-sync/run-next-batch', ['confirm' => 'run-next-ebay-listing-status-sync-batch'])
            ->assertOk()
            ->assertJsonPath('reason', 'delay_not_elapsed')
            ->assertJsonPath('batch_executed', false)
            ->assertJsonPath('clock_skew_detected', true);
        $this->assertGreaterThanOrEqual(81, $response->json('retry_after_seconds'));
        $this->assertLessThanOrEqual(82, $response->json('retry_after_seconds'));
    }

    public function test_stopped_runner_does_not_execute_more_batches(): void
    {
        $this->actingAsAdminUser();
        $this->account();
        $this->listing('999999999999');
        $calls = 0;
        Http::fake(function () use (&$calls) { $calls++; return Http::response(['estimatedAvailabilities' => [['estimatedAvailabilityStatus' => 'IN_STOCK']]], 200); });

        $this->postJson('/admin/tools/ebay/listing-status-sync/start', ['batch_size' => 1, 'delay_seconds' => 10, 'scope' => 'products_with_ebay_item_id', 'dry_run' => true, 'confirm' => 'start-ebay-listing-status-sync'])->assertOk();
        $this->postJson('/admin/tools/ebay/listing-status-sync/stop', ['confirm' => 'stop-ebay-listing-status-sync'])->assertOk()->assertJsonPath('status', 'stopped');
        $this->postJson('/admin/tools/ebay/listing-status-sync/run-next-batch', ['confirm' => 'run-next-ebay-listing-status-sync-batch'])->assertStatus(422)->assertJsonPath('reason', 'not_running')->assertJsonPath('batch_executed', false);
        $this->assertSame(0, $calls);
    }

    public function test_browser_autorun_static_contract_is_present(): void
    {
        $this->actingAsAdminUser();

        $this->get('/admin/tools/ebay/listing-status-sync')
            ->assertOk()
            ->assertSee('ebay_listing_status_batch_runner_browser_autorun_v2')
            ->assertSee('ebay_listing_status_batch_runner_delay_countdown_fix_v4')
            ->assertSee('requestInFlight')
            ->assertSee('terminalStatuses')
            ->assertSee("['completed', 'stopped', 'failed']", false)
            ->assertSee("initialStatus?.status === 'running'", false)
            ->assertSee('localStorage')
            ->assertSee('lockKey')
            ->assertSee('retryFromStatus')
            ->assertSee('Math.min(seconds, fallback)')
            ->assertSee('nextRunAt = Date.now() + (seconds * 1000)')
            ->assertSee('if (timerId) window.clearTimeout(timerId)')
            ->assertSee('if (countdownTimerId) window.clearInterval(countdownTimerId)')
            ->assertDontSee('lock.expiresAt - Date.now()')
            ->assertDontSee('delayFromStatus(result) +');
    }


    public function test_retry_diagnose_reports_full_transient_list_without_ebay_request_or_mutation(): void
    {
        $this->actingAsAdminUser();
        Cache::put(EbayListingStatusBatchRunnerService::CACHE_KEY, [
            'status' => 'completed', 'transient_failure_ids' => [10, 20],
            'results_by_listing_id' => [10 => ['marketplace_listing_id'=>10,'normalized_status'=>'unknown','error_type'=>'rate_limited','http_status'=>429], 20 => ['marketplace_listing_id'=>20,'normalized_status'=>'unknown','error_type'=>'remote_error','http_status'=>503]],
        ], now()->addHour());
        Http::fake(fn () => throw new \RuntimeException('eBay API must not be called'));

        $this->getJson('/admin/tools/ebay/listing-status-sync/retry-diagnose?json=1')
            ->assertOk()->assertJsonPath('no_mutation', true)->assertJsonPath('no_ebay_request', true)
            ->assertJsonPath('full_retry_id_list_available', true)->assertJsonPath('unique_transient_failure_ids', 2)
            ->assertJsonPath('can_retry_without_full_rescan', true)->assertJsonPath('marker', 'ebay_listing_status_retry_diagnose_v1');
    }

    public function test_429_stays_pending_retry_and_does_not_mark_ended_or_mutate_listing(): void
    {
        $this->actingAsAdminUser(); $this->account(); $listing = $this->listing('121212121212', ['status'=>'published']);
        Cache::put(EbayListingStatusBatchRunnerService::CACHE_KEY, ['status'=>'completed','active'=>4582,'ended'=>378,'not_found'=>34,'unknown'=>553,'failed'=>553,'transient_failure_ids'=>[$listing->id]], now()->addHour());
        Http::fake(fn () => Http::response(['errors'=>[]], 429, ['Retry-After' => '60']));

        $this->postJson('/admin/tools/ebay/listing-status-sync/retry-transient', ['batch_size'=>2,'delay_seconds'=>30,'max_attempts_per_item'=>3,'scope'=>'previous_transient_failures','dry_run'=>true,'confirm'=>'retry-ebay-listing-status-transient-failures'])->assertOk()->assertJsonPath('status','running')->assertJsonPath('retry_scope_total',1);
        $response = $this->postJson('/admin/tools/ebay/listing-status-sync/run-next-batch', ['confirm'=>'run-next-ebay-listing-status-sync-batch'])
            ->assertOk()->assertJsonPath('status','waiting_rate_limit')->assertJsonPath('pending',1)->assertJsonPath('resolved_ended',0)->assertJsonPath('unresolved_after_max_attempts',0);
        $this->assertGreaterThanOrEqual(60, $response->json('retry_after_seconds'));
        $fresh = $listing->fresh();
        $this->assertSame('published', $fresh->status); $this->assertNull($fresh->last_api_status); $this->assertNull($fresh->not_seen_in_active_api_at);
    }

    public function test_retry_after_http_date_and_no_retry_before_next_retry_at(): void
    {
        $this->actingAsAdminUser(); $this->account(); $listing = $this->listing('131313131313');
        Cache::put(EbayListingStatusBatchRunnerService::CACHE_KEY, ['status'=>'completed','transient_failure_ids'=>[$listing->id]], now()->addHour());
        $date = now()->addSeconds(90)->toRfc7231String(); $calls = 0;
        Http::fake(function () use (&$calls, $date) { $calls++; return Http::response([], 429, ['Retry-After'=>$date]); });
        $this->postJson('/admin/tools/ebay/listing-status-sync/retry-transient', ['scope'=>'previous_transient_failures','dry_run'=>true,'confirm'=>'retry-ebay-listing-status-transient-failures'])->assertOk();
        $first = $this->postJson('/admin/tools/ebay/listing-status-sync/run-next-batch', ['confirm'=>'run-next-ebay-listing-status-sync-batch'])->assertOk()->assertJsonPath('status','waiting_rate_limit');
        $this->assertGreaterThanOrEqual(90, $first->json('retry_after_seconds'));
        $this->postJson('/admin/tools/ebay/listing-status-sync/run-next-batch', ['confirm'=>'run-next-ebay-listing-status-sync-batch'])->assertOk()->assertJsonPath('reason','rate_limit_wait')->assertJsonPath('batch_executed', false);
        $this->assertSame(1, $calls);
    }

    public function test_missing_retry_after_uses_exponential_backoff_and_max_attempts_completes_unresolved(): void
    {
        $this->actingAsAdminUser(); $this->account(); $listing = $this->listing('141414141414');
        Cache::put(EbayListingStatusBatchRunnerService::CACHE_KEY, ['status'=>'completed','transient_failure_ids'=>[$listing->id]], now()->addHour());
        Http::fake(fn () => Http::response([], 429));
        $this->postJson('/admin/tools/ebay/listing-status-sync/retry-transient', ['max_attempts_per_item'=>1,'scope'=>'previous_transient_failures','dry_run'=>true,'confirm'=>'retry-ebay-listing-status-transient-failures'])->assertOk();
        $response = $this->postJson('/admin/tools/ebay/listing-status-sync/run-next-batch', ['confirm'=>'run-next-ebay-listing-status-sync-batch'])
            ->assertOk()->assertJsonPath('status','completed')->assertJsonPath('pending',0)->assertJsonPath('unresolved_after_max_attempts',1);
        $this->assertGreaterThanOrEqual(60, $response->json('retry_after_seconds'));
    }

    public function test_retry_scope_only_transient_and_first_session_counters_are_not_overwritten(): void
    {
        $this->actingAsAdminUser(); $this->account(); $transient=$this->listing('151515151515'); $ended=$this->listing('161616161616');
        Cache::put(EbayListingStatusBatchRunnerService::CACHE_KEY, ['status'=>'completed','active'=>4582,'ended'=>378,'not_found'=>34,'unknown'=>553,'failed'=>553,'unknown_items'=>553,'failed_requests'=>553,'unique_unresolved_items'=>553,'results_by_listing_id'=>[
            $transient->id=>['marketplace_listing_id'=>$transient->id,'normalized_status'=>'unknown','error_type'=>'rate_limited','http_status'=>429],
            $ended->id=>['marketplace_listing_id'=>$ended->id,'normalized_status'=>'ended','error_type'=>null,'http_status'=>200],
        ]], now()->addHour());
        Http::fake(fn () => Http::response(['estimatedAvailabilities'=>[['estimatedAvailabilityStatus'=>'IN_STOCK']]], 200));
        $this->postJson('/admin/tools/ebay/listing-status-sync/retry-transient', ['scope'=>'previous_transient_failures','dry_run'=>true,'confirm'=>'retry-ebay-listing-status-transient-failures'])->assertOk()->assertJsonPath('retry_scope_total',1);
        $this->postJson('/admin/tools/ebay/listing-status-sync/run-next-batch', ['confirm'=>'run-next-ebay-listing-status-sync-batch'])->assertOk()->assertJsonPath('status','completed')->assertJsonPath('resolved_active',1)->assertJsonPath('consolidated_report.active',4583);
        $first = Cache::get(EbayListingStatusBatchRunnerService::CACHE_KEY);
        $this->assertSame(4582, $first['active']); $this->assertSame(553, $first['unknown_items']); $this->assertSame(553, $first['failed_requests']); $this->assertSame(553, $first['unique_unresolved_items']);
        Http::assertSentCount(1);
    }


    public function test_confirmed_ended_diagnose_and_preview_require_full_ended_http_200_list(): void
    {
        $this->actingAsAdminUser();
        $ended = $this->listing('171717171717');
        $unknown = $this->listing('181818181818');
        $notFound = $this->listing('191919191919');
        $active = $this->listing('202020202021');
        Cache::put(EbayListingStatusBatchRunnerService::CACHE_KEY, ['status'=>'completed','ended'=>1,'results_by_listing_id'=>[
            $ended->id=>['part_id'=>$ended->part_id,'marketplace_listing_id'=>$ended->id,'ebay_item_id'=>'171717171717','local_status'=>'published','normalized_status'=>'ended','http_status'=>200,'error_type'=>null],
            $unknown->id=>['marketplace_listing_id'=>$unknown->id,'normalized_status'=>'unknown','http_status'=>429,'error_type'=>'rate_limited'],
            $notFound->id=>['marketplace_listing_id'=>$notFound->id,'normalized_status'=>'not_found','http_status'=>404,'error_type'=>null],
            $active->id=>['marketplace_listing_id'=>$active->id,'normalized_status'=>'active','http_status'=>200,'error_type'=>null],
        ]], now()->addHour());
        Http::fake(fn () => throw new \RuntimeException('eBay API must not be called'));

        $this->getJson('/admin/tools/ebay/listing-status-sync/ended-results-diagnose?json=1')->assertOk()
            ->assertJsonPath('marker','ebay_confirmed_ended_results_diagnose_v1')->assertJsonPath('no_mutation', true)->assertJsonPath('no_ebay_request', true)
            ->assertJsonPath('expected_ended_count',378)->assertJsonPath('available_ended_id_count',1)->assertJsonPath('full_ended_id_list_available',false)->assertJsonPath('can_apply_without_rescan',false);
        $this->postJson('/admin/tools/ebay/listing-status-sync/apply-confirmed-ended', ['source'=>'completed_dry_run','expected_count'=>378,'dry_run'=>false,'confirm'=>'apply-confirmed-ebay-ended-listings'])->assertStatus(422)->assertJsonPath('reason','full_confirmed_ended_id_list_unavailable');
        $this->assertSame('published', $ended->fresh()->status);
        Http::assertSentCount(0);
    }

    public function test_confirmed_ended_preview_and_apply_mutate_only_confirmed_batch_preserving_history_and_not_calling_ebay(): void
    {
        $this->actingAsAdminUser();
        $rows = [];
        $first = null; $unknown = $this->listing('292929292929', ['raw_payload'=>['keep'=>'unknown']]);
        for ($i = 0; $i < 378; $i++) {
            $item = (string) (300000000000 + $i);
            $listing = $this->listing($item, ['url'=>'https://www.ebay.de/itm/'.$item, 'raw_payload'=>['keep'=>'yes']]);
            $first ??= $listing;
            $rows[$listing->id] = ['part_id'=>$listing->part_id,'marketplace_listing_id'=>$listing->id,'ebay_item_id'=>$item,'local_status'=>'published','normalized_status'=>'ended','http_status'=>200,'error_type'=>null,'itemEndDate'=>'2026-05-31T10:00:00.000Z'];
        }
        $otherActive = $this->listing('399999999999', ['part_id'=>$first->part_id, 'status'=>'published']);
        Cache::put(EbayListingStatusBatchRunnerService::CACHE_KEY, ['status'=>'completed','ended'=>378,'results_by_listing_id'=>$rows + [$unknown->id=>['marketplace_listing_id'=>$unknown->id,'normalized_status'=>'unknown','http_status'=>429,'error_type'=>'rate_limited']]], now()->addHour());
        Http::fake(fn () => throw new \RuntimeException('eBay API must not be called'));
        $before = MarketplaceListing::query()->orderBy('id')->get()->keyBy('id')->map(fn ($l) => $l->getAttributes())->all();

        $this->getJson('/admin/tools/ebay/listing-status-sync/apply-confirmed-ended-preview?json=1')->assertOk()
            ->assertJsonPath('marker','ebay_confirmed_ended_apply_preview_v1')->assertJsonPath('no_mutation', true)->assertJsonPath('no_ebay_request', true)
            ->assertJsonPath('candidate_count',378)->assertJsonPath('currently_blocks_relisting',378)->assertJsonPath('sample.0.planned_local_status','ended')
            ->assertJsonPath('sample.0.has_another_active_ebay_listing', true)->assertJsonPath('sample.0.will_unblock_relisting', false);
        $this->assertSame($before, MarketplaceListing::query()->orderBy('id')->get()->keyBy('id')->map(fn ($l) => $l->getAttributes())->all());

        $this->postJson('/admin/tools/ebay/listing-status-sync/apply-confirmed-ended', ['source'=>'completed_dry_run','expected_count'=>377,'dry_run'=>false,'confirm'=>'apply-confirmed-ebay-ended-listings'])->assertStatus(422)->assertJsonPath('reason','expected_count_mismatch');
        $this->postJson('/admin/tools/ebay/listing-status-sync/apply-confirmed-ended', ['source'=>'completed_dry_run','expected_count'=>378,'dry_run'=>false,'confirm'=>'apply-confirmed-ebay-ended-listings'])->assertOk()->assertJsonPath('updated_count',20)->assertJsonPath('no_ebay_request', true);

        $fresh = $first->fresh();
        $this->assertSame('ended', $fresh->status);
        $this->assertSame('ended', $fresh->last_api_status);
        $this->assertSame((string)(300000000000), $fresh->external_listing_id);
        $this->assertSame('https://www.ebay.de/itm/300000000000', $fresh->url);
        $this->assertSame('yes', $fresh->raw_payload['keep']);
        $this->assertSame('2026-05-31T10:00:00.000Z', $fresh->raw_payload['itemEndDate']);
        $this->assertSame('published', $unknown->fresh()->status);
        $this->assertSame('published', $otherActive->fresh()->status);
        $changed = MarketplaceListing::query()->where('status','ended')->pluck('id')->all();
        $this->assertCount(20, $changed);
        Http::assertSentCount(0);
    }

    private function listing(string $itemId, array $overrides = []): MarketplaceListing
    {
        $part = Part::query()->create(['name' => 'Part '.$itemId, 'sku' => 'SKU-'.$itemId, 'quantity' => 1, 'status' => 'ready']);
        return MarketplaceListing::query()->create(array_merge(['part_id' => $part->id, 'marketplace' => 'ebay_de', 'external_listing_id' => $itemId, 'status' => 'published'], $overrides));
    }

    private function account(): void
    {
        MarketplaceAccount::query()->create(['code' => 'ebay_de', 'marketplace' => 'ebay_de', 'name' => 'eBay DE', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://api.ebay.test', 'api_credentials' => ['access_token' => 'token'], 'api_settings' => ['marketplace_id' => 'EBAY_DE']]);
    }

    private function actingAsAdminUser(): User
    {
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::query()->create(['name' => 'Owner Admin', 'email' => uniqid('admin').'@example.test', 'password' => 'password']);
        $user->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        return $user;
    }
}
