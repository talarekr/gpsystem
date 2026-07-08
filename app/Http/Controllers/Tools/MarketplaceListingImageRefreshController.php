<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Enums\UserRole;
use App\Services\Marketplace\MarketplaceListingImageRefreshService;
use Illuminate\Http\Request;

class MarketplaceListingImageRefreshController extends Controller
{
    public function __invoke(Request $request, MarketplaceListingImageRefreshService $service)
    {
        $this->authorizeOwnerAdmin($request);
        $partId = (int) $request->input('part_id', 8015);
        $channel = (string) $request->input('channel', 'allegro_main');
        $result = $request->isMethod('post') && $request->input('action') === 'repair_ebay_mapping'
            ? $service->repairEbayMapping($partId, $channel, (string) $request->input('public_url'), (string) $request->input('confirm'))
            : ($request->isMethod('post')
                ? $service->apply($partId, $channel, (string) $request->input('confirm'))
                : $service->preview($partId, $channel));

        if ($request->wantsJson() || $request->boolean('json')) return response()->json($result);

        return view('tools.marketplace-listing-image-refresh', [
            'result' => $result,
            'partId' => $partId,
            'channel' => $channel,
            'confirmText' => MarketplaceListingImageRefreshService::CONFIRM,
        ]);
    }
    private function authorizeOwnerAdmin(Request $request): void
    {
        abort_unless($request->user()?->canAccessPanel(filament()->getPanel('admin')), 403);
        abort_unless($request->user()?->hasAnyRole([UserRole::OwnerAdmin->value]), 403);
    }
}
