<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\MarketplaceLinkRepairService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MarketplaceLinkRepairController extends Controller
{
    public function __invoke(Request $request, MarketplaceLinkRepairService $service): JsonResponse|View
    {
        $filters = $this->filters($request);

        if ($request->isMethod('post')) {
            abort_unless($request->input('confirm') === 'apply-marketplace-link-repair', 422, 'Missing confirmation.');
            $payload = $service->apply($filters);
        } else {
            $payload = $service->preview($filters);
        }

        if ($request->expectsJson() || $request->query('format') === 'json') {
            return response()->json($payload);
        }

        return view('tools.marketplace-link-repair', $payload);
    }

    private function filters(Request $request): array
    {
        return [
            'channel' => in_array($request->input('channel', 'both'), ['ovoko', 'allegro', 'both'], true) ? $request->input('channel', 'both') : 'both',
            'part_id' => $request->filled('part_id') ? (int) $request->input('part_id') : null,
            'ready_only' => $request->boolean('ready_only', false),
            'only_resolver_broken' => $request->boolean('only_resolver_broken', false),
            'limit' => max(1, min(100, (int) $request->input('limit', 50))),
        ];
    }
}
