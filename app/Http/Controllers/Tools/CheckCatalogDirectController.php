<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\CatalogController;
use App\Services\Storefront\CategoryTreeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Throwable;

class CheckCatalogDirectController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! hash_equals('gps_images_import_2026', (string) $request->query('token', ''))) {
            return response()->json([
                'ok' => false,
                'failed_stage' => 'token',
                'error_class' => 'AuthorizationException',
                'error_message' => 'Invalid diagnostics token.',
                'file' => __FILE__,
                'line' => __LINE__,
                'trace' => [],
            ], 403);
        }

        try {
            $routeExists = collect(Route::getRoutes())->contains(
                fn ($route): bool => in_array('GET', $route->methods(), true) && $route->uri() === 'czesci'
            );

            if (! $routeExists) {
                return response()->json([
                    'ok' => false,
                    'failed_stage' => 'route',
                    'error_class' => 'RuntimeException',
                    'error_message' => 'GET /czesci route is not registered.',
                    'file' => null,
                    'line' => null,
                    'trace' => [],
                ], 200);
            }
        } catch (Throwable $exception) {
            return response()->json($this->exceptionPayload($exception, 'route'), 200);
        }

        try {
            $catalogRequest = Request::create('/czesci', 'GET', $request->query());
            $catalogRequest->setLaravelSession($request->session());
            app()->instance('request', $catalogRequest);

            $view = app(CatalogController::class)->index($catalogRequest, app(CategoryTreeService::class));
            $html = $view->render();

            return response()->json([
                'ok' => true,
                'failed_stage' => null,
                'route_exists' => true,
                'rendered_bytes' => strlen($html),
            ]);
        } catch (Throwable $exception) {
            return response()->json($this->exceptionPayload($exception, 'catalog_render'), 200);
        } finally {
            app()->instance('request', $request);
        }
    }

    /** @return array<string, mixed> */
    private function exceptionPayload(Throwable $exception, string $stage): array
    {
        return [
            'ok' => false,
            'failed_stage' => $stage,
            'error_class' => $exception::class,
            'error_message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => collect($exception->getTrace())->take(10)->map(fn (array $frame): array => [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'function' => $frame['function'] ?? null,
                'class' => $frame['class'] ?? null,
            ])->values()->all(),
        ];
    }
}
