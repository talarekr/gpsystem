<?php

namespace App\Http\Controllers\Tools;

use App\Services\Tools\PhotoStorageReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;

class PreDomainSwitchCheckController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    public function __invoke(Request $request, PhotoStorageReportService $photoStorageReport): JsonResponse
    {
        if (! hash_equals(self::TOKEN, (string) $request->query('token', ''))) {
            return response()->json([
                'ok' => false,
                'error_message' => 'Invalid diagnostics token.',
            ], 403);
        }

        $routeDiagnostics = $this->routeDiagnostics();

        return response()->json([
            'ok' => true,
            'check' => 'pre-domain-switch',
            'checks' => [
                'route_registered' => $routeDiagnostics['registered'],
            ],
            'warnings' => [],
            'blockers' => [],
            'recommended_next_steps' => [
                'Deploy this branch to gpsystem.thecamels.pl before switching gpswiss.pl.',
                'Confirm this endpoint returns HTTP 200 JSON after deployment.',
            ],
            'generated_at' => now()->toISOString(),
            'route_registered' => $routeDiagnostics['registered'],
            'route' => $routeDiagnostics,
            'cache' => [
                'routes_cached' => app()->routesAreCached(),
                'config_cached' => app()->configurationIsCached(),
                'events_cached' => method_exists(app(), 'eventsAreCached') ? app()->eventsAreCached() : null,
                'views_cache_path' => realpath(storage_path('framework/views')) ?: storage_path('framework/views'),
            ],
            'app' => [
                'environment' => app()->environment(),
                'debug' => (bool) config('app.debug'),
                'url' => config('app.url'),
                'base_path' => base_path(),
                'public_path' => public_path(),
                'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? null,
            ],
            'storage' => $photoStorageReport->report(),
        ], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    private function routeDiagnostics(): array
    {
        $route = collect(Route::getRoutes())->first(fn ($route): bool => in_array('GET', $route->methods(), true)
            && $route->uri() === 'tools/pre-domain-switch-check');

        return [
            'expected_uri' => 'tools/pre-domain-switch-check',
            'registered' => $route !== null,
            'name' => $route?->getName(),
            'action' => $route?->getActionName(),
            'methods' => $route?->methods(),
            'middleware' => $route?->gatherMiddleware(),
        ];
    }
}
