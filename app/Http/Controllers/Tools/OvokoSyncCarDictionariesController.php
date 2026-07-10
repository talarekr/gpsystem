<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\Ovoko\OvokoCarDictionaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OvokoSyncCarDictionariesController extends Controller
{
    public function __invoke(Request $request, OvokoCarDictionaryService $service): JsonResponse|RedirectResponse
    {
        if ($request->input('confirm') !== OvokoCarDictionaryService::CONFIRM) {
            $result = ['ok' => false, 'blocked' => true, 'reason' => 'missing_confirm_token', 'expected_confirm' => OvokoCarDictionaryService::CONFIRM];

            return $this->syncResponse($request, $result, 422);
        }

        $scope = (string) $request->input('scope', 'all');
        if ($scope === 'models' && ! $request->filled('brand_id')) {
            $result = [
                'ok' => false,
                'blocked' => true,
                'reason' => 'models_full_sync_requires_runner',
                'message' => 'Pełny sync modeli Ovoko uruchom przez /admin/tools/ovoko/car-models-sync-runner/start.',
                'runner_endpoint' => '/admin/tools/ovoko/car-models-sync-runner/start',
            ];

            return $this->syncResponse($request, $result, 422);
        }

        $result = ['ok' => true] + $service->sync($scope, $request->filled('brand_id') ? (string) $request->input('brand_id') : null);

        return $this->syncResponse($request, $result);
    }

    private function syncResponse(Request $request, array $result, int $status = 200): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json($result, ($result['ok'] ?? false) ? 200 : $status);
        }

        return redirect()
            ->route('admin.tools.ovoko.car-models-sync-runner.index')
            ->with(($result['ok'] ?? false) ? 'runner_message' : 'runner_error', ($result['ok'] ?? false)
                ? 'Synchronizacja marek i słowników bazowych Ovoko zakończona. Aktualny status/counts są widoczne poniżej.'
                : ('Synchronizacja marek i słowników bazowych zablokowana: '.($result['reason'] ?? 'unknown')));
    }
}
