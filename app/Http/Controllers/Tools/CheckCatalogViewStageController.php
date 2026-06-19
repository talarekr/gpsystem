<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\CatalogController;
use App\Models\Part;
use App\Services\Storefront\CategoryTreeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class CheckCatalogViewStageController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! hash_equals('gps_images_import_2026', (string) $request->query('token', ''))) {
            return response()->json($this->errorPayload(new \RuntimeException('Invalid diagnostics token.'), 'auth'), 403);
        }

        $stage = strtoupper((string) $request->query('stage', 'A'));

        try {
            $payload = match ($stage) {
                'A' => ['route' => 'entered', 'path' => $request->path(), 'query' => $request->query()],
                'B' => $this->stageQuery($request),
                'C' => $this->stageData($request),
                'D' => $this->stageSimpleView($request),
                'E' => $this->stageProductCard($request),
                'F' => $this->stageFullView($request),
                default => throw new \InvalidArgumentException('Unknown stage. Use A, B, C, D, E or F.'),
            };

            return response()->json(['ok' => true, 'stage' => $stage] + $payload);
        } catch (Throwable $exception) {
            return response()->json($this->errorPayload($exception, $stage), 200);
        }
    }

    /** @return array<string, mixed> */
    private function stageQuery(Request $request): array
    {
        $count = Part::query()
            ->storefrontVisible()
            ->searchStorefront($request->string('q')->toString())
            ->partNumberSearch($request->string('part_number')->toString())
            ->count();

        return ['query' => 'ok', 'count' => $count];
    }

    /** @return array<string, mixed> */
    private function stageData(Request $request): array
    {
        $data = app(CatalogController::class)->viewData($request, app(CategoryTreeService::class));

        return [
            'data' => 'ok',
            'keys' => array_keys($data),
            'parts_count' => method_exists($data['parts'], 'count') ? $data['parts']->count() : null,
            'parts_total' => method_exists($data['parts'], 'total') ? $data['parts']->total() : null,
        ];
    }

    /** @return array<string, mixed> */
    private function stageSimpleView(Request $request): array
    {
        $parts = Part::query()->storefrontVisible()->searchStorefront($request->string('q')->toString())->limit(3)->get();
        $html = view('storefront.catalog._diagnostic-simple', ['parts' => $parts])->render();

        return ['rendered_length' => strlen($html)];
    }

    /** @return array<string, mixed> */
    private function stageProductCard(Request $request): array
    {
        $part = Part::query()->storefrontVisible()->searchStorefront($request->string('q')->toString())->first();
        $html = view('storefront.partials.product-card', ['part' => $part])->render();

        return ['has_part' => $part !== null, 'rendered_length' => strlen($html)];
    }

    /** @return array<string, mixed> */
    private function stageFullView(Request $request): array
    {
        $html = view('storefront.catalog.index', app(CatalogController::class)->viewData($request, app(CategoryTreeService::class)))->render();

        return ['rendered_length' => strlen($html)];
    }

    /** @return array<string, mixed> */
    private function errorPayload(Throwable $exception, string $stage): array
    {
        return [
            'ok' => false,
            'stage' => $stage,
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
