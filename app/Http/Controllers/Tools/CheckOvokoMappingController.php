<?php

namespace App\Http\Controllers\Tools;

use App\Filament\Resources\MarketplaceListingResource;
use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

class CheckOvokoMappingController extends Controller
{
    public function __invoke()
    {
        if (! hash_equals('gps_images_import_2026', (string) request()->query('token', ''))) {
            return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        }
        $tables = ['marketplace_accounts'=>Schema::hasTable('marketplace_accounts'), 'marketplace_listings'=>Schema::hasTable('marketplace_listings'), 'marketplace_sync_logs'=>Schema::hasTable('marketplace_sync_logs')];
        $counts = fn ($status) => $tables['marketplace_listings'] ? MarketplaceListing::query()->where('marketplace', 'ovoko')->where('sync_status', $status)->count() : 0;
        return response()->json([
            'ok' => ! in_array(false, $tables, true),
            'tables' => $tables,
            'models' => ['MarketplaceAccount'=>class_exists(MarketplaceAccount::class), 'MarketplaceListing'=>class_exists(MarketplaceListing::class), 'MarketplaceSyncLog'=>class_exists(MarketplaceSyncLog::class)],
            'accounts_count' => $tables['marketplace_accounts'] ? MarketplaceAccount::query()->count() : 0,
            'ovoko_listings_count' => $tables['marketplace_listings'] ? MarketplaceListing::query()->where('marketplace', 'ovoko')->count() : 0,
            'mapped_count' => $counts('mapped'), 'unmatched_count' => $counts('unmatched'), 'conflict_count' => $counts('conflict'), 'ignored_count' => $counts('ignored'), 'sync_error_count' => $counts('sync_error'),
            'samples_mapped' => $this->samples('mapped'), 'samples_unmatched' => $this->samples('unmatched'), 'samples_conflict' => $this->samples('conflict'),
            'import_command_exists' => array_key_exists('marketplace:import-ovoko-mapping', Artisan::all()),
            'recent_sync_logs' => $tables['marketplace_sync_logs'] ? MarketplaceSyncLog::query()->where('marketplace', 'ovoko')->latest('created_at')->limit(10)->get(['id','marketplace_listing_id','part_id','action','status','message','created_at']) : [],
            'admin_ovoko_url' => MarketplaceListingResource::getUrl('index'),
        ]);
    }

    private function samples(string $status): array
    {
        if (! Schema::hasTable('marketplace_listings')) return [];
        return MarketplaceListing::query()->where('marketplace', 'ovoko')->where('sync_status', $status)->limit(5)->get(['id','external_offer_id','part_id','sku','title','match_status','sync_status','match_confidence','match_reason'])->all();
    }
}
