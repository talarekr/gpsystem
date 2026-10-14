<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Storefront\FrenchCatalogTranslationAdminRunner;
use App\Services\Storefront\FrenchCatalogTranslationPreviewService;
use App\Services\Storefront\FrenchNewProductsTranslationRunner;
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
            'newProductsOverview' => app(FrenchNewProductsTranslationRunner::class)->overview(),
            'jarekOverview' => $preview->forScope('jarek_gearboxes')->overview((int) $request->user()->getAuthIdentifier()),
            'jarekStatus' => $runner->forScope('jarek_gearboxes')->status(),
        ]);
    }

    public function status(Request $request, FrenchCatalogTranslationAdminRunner $runner): JsonResponse
    {
        return response()->json($runner->forScope($this->scope($request))->status());
    }

    public function dryRun(Request $request, FrenchCatalogTranslationPreviewService $preview): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'dry_run' => $preview->forScope($this->scope($request))->dryRun((int) $request->user()->getAuthIdentifier()),
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

        return $this->respond($runner->forScope($this->scope($request))->start(
            (int) $request->user()->getAuthIdentifier(),
            $input['dry_run_id'],
            $input['confirm'],
        ));
    }

    public function tick(Request $request, FrenchCatalogTranslationAdminRunner $runner): JsonResponse
    {
        $input = $request->validate(['run_id' => ['required', 'string', 'max:128']]);

        return $this->respond($runner->forScope($this->scope($request))->tick($input['run_id']));
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

        return $this->respond($runner->forScope($this->scope($request))->control($input['run_id'], $action));
    }

    /** Scope is fixed by the route, never selected from user-supplied options. */
    private function scope(Request $request): string
    {
        return $request->route('translation_scope') === 'jarek_gearboxes' ? 'jarek_gearboxes' : 'catalog';
    }

    private function respond(array $result): JsonResponse
    {
        $reason = $result['reason'] ?? $result['error_code'] ?? $result['error'] ?? '';
        $conflict = in_array($reason, ['busy', 'active_run', 'run_active', 'already_running', 'control_conflict', 'run_mismatch'], true);

        return response()->json($result, ($result['ok'] ?? false) ? 200 : ($conflict ? 409 : 422));
    }
}
