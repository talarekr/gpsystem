<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\CatalogController;
use App\Models\Part;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use Throwable;

class CheckCatalogErrorController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        try {
            if (! hash_equals('gps_images_import_2026', (string) $request->query('token', ''))) {
                return response()->json([
                    'ok' => false,
                    'error_class' => 'AuthorizationException',
                    'error_message' => 'Invalid diagnostics token.',
                ], 403);
            }

            $q = $request->string('q')->toString();

            return response()->json([
                'ok' => true,
                'laravel_version' => App::version(),
                'app_env' => App::environment(),
                'czesci_route_exists' => $this->safeTry(fn (): bool => collect(Route::getRoutes())->contains(
                    fn ($route): bool => in_array('GET', $route->methods(), true) && $route->uri() === 'czesci'
                )),
                'catalog_controller_exists' => class_exists(CatalogController::class),
                'count' => $this->safeTry(fn (): int => Part::query()->searchStorefront($q)->count()),
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'ok' => false,
                'error_class' => $exception::class,
                'error_message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => collect($exception->getTrace())->take(5)->map(fn (array $frame): array => [
                    'file' => $frame['file'] ?? null,
                    'line' => $frame['line'] ?? null,
                    'function' => $frame['function'] ?? null,
                    'class' => $frame['class'] ?? null,
                ])->values()->all(),
            ], 500);
        }
    }

    private function safeTry(callable $callback): array
    {
        try {
            return [
                'ok' => true,
                'value' => $callback(),
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error_class' => $exception::class,
                'error_message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ];
        }
    }
}
