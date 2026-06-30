<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\EbayDescriptionAuditService;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EbayDescriptionAuditController extends Controller
{
    public function __invoke(Request $request, EbayDescriptionAuditService $audit): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $user->canAccessPanel(Filament::getPanel('admin')), 403);
        $result = $audit->run(
            channel: (string) $request->query('channel', 'ebay_de'),
            limit: (int) $request->query('limit', 20),
            offset: (int) $request->query('offset', 0),
            partId: $request->filled('part_id') ? (int) $request->query('part_id') : null,
            apply: $request->boolean('apply'),
            confirmed: $request->query('confirm') === 'revise-ebay-description',
            checkApi: $request->boolean('check_api'),
            patchAssetsOnly: $request->boolean('patch_assets_only'),
        );
        return response()->json(['ok' => true] + $result);
    }
}
