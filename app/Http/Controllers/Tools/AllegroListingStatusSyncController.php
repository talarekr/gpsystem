<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\AllegroListingStatusSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AllegroListingStatusSyncController extends Controller
{
    public function __invoke(Request $request, AllegroListingStatusSyncService $service): JsonResponse
    {
        $data = $request->validate([
            'part_id' => ['nullable', 'integer', 'min:1', 'required_without:listing_id'],
            'listing_id' => ['nullable', 'integer', 'min:1', 'required_without:part_id'],
            'offer_id' => ['nullable', 'string', 'max:64'],
            'mode' => ['nullable', 'string', Rule::in([AllegroListingStatusSyncService::MODE_DRY_RUN, AllegroListingStatusSyncService::MODE_LIVE])],
            'confirm' => ['nullable', 'string'],
        ]);

        $mode = $data['mode'] ?? AllegroListingStatusSyncService::MODE_DRY_RUN;
        if ($mode === AllegroListingStatusSyncService::MODE_LIVE && ($data['confirm'] ?? null) !== AllegroListingStatusSyncService::CONFIRM) {
            return response()->json(['message' => 'confirm must be SYNC_LOCAL_STATUS for live mode.', 'writes' => ['database' => false, 'allegro' => false]], 422);
        }

        $result = $mode === AllegroListingStatusSyncService::MODE_LIVE ? $service->sync($data) : $service->dryRun($data);

        return response()->json($result, $result['blockers'] === [] ? 200 : 409);
    }
}
