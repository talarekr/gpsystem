<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use Throwable;

class CheckCatalogViewController extends Controller
{
    public function __invoke()
    {
        try {
            $request = request();

            if (! hash_equals('gps_images_import_2026', (string) $request->query('token', ''))) {
                return response()->json([
                    'ok' => false,
                    'error_class' => 'AuthorizationException',
                    'error_message' => 'Invalid diagnostics token.',
                    'file' => __FILE__,
                    'line' => __LINE__,
                    'trace' => [],
                ], 403);
            }

            $catalog = app(\App\Http\Controllers\Storefront\CatalogController::class);
            $categoryTree = app(\App\Services\Storefront\CategoryTreeService::class);
            $html = view('storefront.catalog.index', $catalog->viewData($request, $categoryTree))->render();

            return response()->json([
                'ok' => true,
                'rendered_length' => strlen($html),
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'ok' => false,
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
            ], 500);
        }
    }
}
