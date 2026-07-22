<?php

namespace App\Http\Controllers\Tools;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Services\Marketplace\AllegroCompatibilityApplyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AllegroCompatibilityApplyController extends Controller
{
    public function dryRun(Request $request, Part $part, AllegroCompatibilityApplyService $service): JsonResponse
    {
        $this->authorizeOwnerAdmin($request);
        abort_unless($request->boolean('dry_run'), 422, 'dry_run=1 is required for GET.');
        return response()->json($service->dryRun($part));
    }

    public function apply(Request $request, Part $part, AllegroCompatibilityApplyService $service): JsonResponse
    {
        $this->authorizeOwnerAdmin($request);
        return response()->json($service->apply($part, (string) $request->input('confirm', ''), (string) $request->input('canonical_hash', '')));
    }

    private function authorizeOwnerAdmin(Request $request): void
    {
        abort_unless($request->user()?->hasAnyRole([UserRole::OwnerAdmin->value]), 403);
    }
}
