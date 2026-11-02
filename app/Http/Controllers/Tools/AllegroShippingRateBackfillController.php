<?php

namespace App\Http\Controllers\Tools;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\Marketplace\ShippingRateAudit\ShippingRateAuditService;
use Illuminate\Http\Request;

final class AllegroShippingRateBackfillController extends Controller
{
    public function __invoke(Request $request, ShippingRateAuditService $audit)
    {
        abort_unless($request->user()?->hasRole(UserRole::OwnerAdmin->value), 403);
        $input = $request->validate([
            'mode' => ['sometimes', 'in:catalog,batch,diagnose'],
            'after' => ['sometimes', 'integer', 'min:0'],
            'through' => ['sometimes', 'integer', 'min:0'],
        ]);
        $response = isset($input['mode'])
            ? response()->json(($input['mode'] === 'diagnose') ? $audit->diagnoseMainAccount() : $audit->run((int) ($input['after'] ?? 0), isset($input['through']) ? (int) $input['through'] : null, $input['mode'] === 'catalog'))
            : response()->view('admin.tools.allegro.shipping-rate-backfill');

        return $response->header('Cache-Control', 'private, no-store')
            ->header('X-Robots-Tag', 'noindex, noarchive')->header('Referrer-Policy', 'no-referrer');
    }
}
