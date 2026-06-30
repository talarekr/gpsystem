<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\EbayListingAuditService;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EbayListingAuditController extends Controller
{
    public function __invoke(Request $request, EbayListingAuditService $audit): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $user->canAccessPanel(Filament::getPanel('admin')), 403);

        $result = $audit->run(
            channel: (string) $request->query('channel', 'ebay_de'),
            limit: (int) $request->query('limit', 100),
            offset: (int) $request->query('offset', 0),
            partId: $request->filled('part_id') ? (int) $request->query('part_id') : null,
            apply: $request->boolean('apply') && $request->query('confirm') === 'ebay-listing-audit-fix',
        );

        return response()->json(['ok' => true] + $result);
    }
}
