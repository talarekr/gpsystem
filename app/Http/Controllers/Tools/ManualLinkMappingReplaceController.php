<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Services\Marketplace\ManualMarketplaceLinkMappingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ManualLinkMappingReplaceController extends Controller
{
    public function dryRun(Request $request, ManualMarketplaceLinkMappingService $service): JsonResponse
    {
        return $this->handle($request, $service, false);
    }

    public function apply(Request $request, ManualMarketplaceLinkMappingService $service): JsonResponse
    {
        return $this->handle($request, $service, true);
    }

    private function handle(Request $request, ManualMarketplaceLinkMappingService $service, bool $apply): JsonResponse
    {
        $partId = (int) $request->query('part_id');
        $part = Part::query()->find($partId);
        if (! $part) {
            return response()->json(['ok' => false, 'part_id' => $partId, 'error' => 'part_not_found'], 404);
        }

        if ($apply && ! hash_equals('replace-marketplace-link-mapping', (string) $request->query('confirm', ''))) {
            return response()->json(['ok' => false, 'part_id' => $part->id, 'applied' => false, 'error' => 'missing_confirm', 'required_confirm' => 'replace-marketplace-link-mapping', 'marketplace_write' => false, 'sync_triggered' => false], 422);
        }

        try {
            $result = $apply
                ? $service->replace($part, (string) $request->query('marketplace', ''), (string) $request->query('url', ''), false)
                : $service->replaceDryRun($part, (string) $request->query('marketplace', ''), (string) $request->query('url', ''));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['ok' => false, 'part_id' => $part->id, 'error' => $exception->getMessage(), 'marketplace_write' => false, 'sync_triggered' => false], 422);
        }

        return response()->json($result + ['ok' => true, 'dry_run' => ! $apply, 'applied' => $apply]);
    }
}
