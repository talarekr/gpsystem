<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Storefront\FrenchCatalogTranslationAdminRunner;
use App\Services\Storefront\FrenchCatalogTranslationPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FrenchCatalogTranslationController extends Controller
{
    public function index(Request $request, FrenchCatalogTranslationPreviewService $preview, FrenchCatalogTranslationAdminRunner $runner): View
    {
        return view('admin.tools.storefront.fr-translations', [
            'overview' => $preview->overview((int) $request->user()->getAuthIdentifier()),
            'status' => $runner->status(),
        ]);
    }

    public function status(FrenchCatalogTranslationAdminRunner $runner): JsonResponse
    {
        return response()->json($runner->status());
    }

    public function dryRun(Request $request, FrenchCatalogTranslationPreviewService $preview): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'dry_run' => $preview->dryRun((int) $request->user()->getAuthIdentifier()),
        ]);
    }

    public function start(Request $request, FrenchCatalogTranslationAdminRunner $runner): JsonResponse
    {
        $input = $request->validate([
            'dry_run_id' => ['required', 'string', 'max:128'],
            'confirm' => ['required', 'string', 'max:64'],
            'type' => ['prohibited'],
            'chunk' => ['prohibited'],
            'only_missing' => ['prohibited'],
            'include_failed' => ['prohibited'],
            'include_needs_update' => ['prohibited'],
            'options' => ['prohibited'],
        ]);

        return $this->respond($runner->start(
            (int) $request->user()->getAuthIdentifier(),
            $input['dry_run_id'],
            $input['confirm'],
        ));
    }

    public function pause(Request $request, FrenchCatalogTranslationAdminRunner $runner): JsonResponse
    {
        return $this->control($request, $runner, 'pause');
    }

    public function resume(Request $request, FrenchCatalogTranslationAdminRunner $runner): JsonResponse
    {
        return $this->control($request, $runner, 'resume');
    }

    public function stop(Request $request, FrenchCatalogTranslationAdminRunner $runner): JsonResponse
    {
        return $this->control($request, $runner, 'stop');
    }

    private function control(Request $request, FrenchCatalogTranslationAdminRunner $runner, string $action): JsonResponse
    {
        $input = $request->validate(['run_id' => ['required', 'string', 'max:128']]);

        return $this->respond($runner->control($input['run_id'], $action));
    }

    private function respond(array $result): JsonResponse
    {
        $reason = $result['reason'] ?? $result['error_code'] ?? $result['error'] ?? '';
        $conflict = in_array($reason, ['busy', 'active_run', 'run_active', 'already_running', 'control_conflict', 'run_mismatch'], true);

        return response()->json($result, ($result['ok'] ?? false) ? 200 : ($conflict ? 409 : 422));
    }
}
