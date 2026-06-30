<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\MarketplaceOrdersTimezoneFixService;
use Illuminate\Http\Request;

class MarketplaceOrdersTimezoneFixController extends Controller
{
    public function __invoke(Request $request, MarketplaceOrdersTimezoneFixService $service)
    {
        abort_unless($request->user()?->canAccessPanel(filament()->getPanel('admin')), 403);

        return response()->json($service->run([
            'channels' => (string) $request->query('channels', 'allegro,ebay'),
            'since' => (string) $request->query('since', '2026-06-29 00:00:00'),
            'apply' => $request->boolean('apply', false),
            'confirm' => (string) $request->query('confirm', ''),
        ]));
    }
}
