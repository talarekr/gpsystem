<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\CatalogController;
use App\Models\Part;
use App\Services\Storefront\CategoryTreeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Throwable;

class CheckCatalogBladeStagesController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! hash_equals('gps_images_import_2026', (string) $request->query('token', ''))) {
            return response()->json([
                'ok' => false,
                'error_message' => 'Invalid diagnostics token.',
            ], 403);
        }

        $stages = [
            'A_inline_html' => fn (): string => '<h1>Catalog Blade diagnostic</h1><p>Inline HTML OK</p>',
            'B_simple_blade_no_layout' => fn (): string => Blade::render('<div data-stage="B">Catalog Blade diagnostic: {{ $label }}</div>', ['label' => 'simple Blade OK']),
            'C_search_bar_partial' => fn (): string => view('storefront.partials.search-bar')->render(),
            'D_product_card_partial' => fn (): string => view('storefront.partials.product-card', ['part' => Part::query()->storefrontVisible()->first()])->render(),
            'E_pagination' => fn (): string => Part::query()->storefrontVisible()->paginate(5)->withQueryString()->links()->toHtml(),
            'F_catalog_index_without_layout' => fn (): string => $this->renderCatalogIndexWithoutLayout($this->catalogData($request)),
            'G_catalog_index_full_layout' => fn (): string => view('storefront.catalog.index', $this->catalogData($request))->render(),
        ];

        $results = [];
        foreach ($stages as $name => $renderer) {
            $results[$name] = $this->runStage($renderer);
        }

        $failed = collect($results)->filter(fn (array $stage): bool => ! $stage['ok'])->keys()->values()->all();

        return response()->json([
            'ok' => $failed === [],
            'failed_stages' => $failed,
            'stages' => $results,
        ]);
    }

    /** @return array<string, mixed> */
    private function catalogData(Request $request): array
    {
        return app(CatalogController::class)->viewData($request, app(CategoryTreeService::class));
    }

    /** @param callable(): string $renderer @return array<string, mixed> */
    private function runStage(callable $renderer): array
    {
        try {
            $html = $renderer();

            return [
                'ok' => true,
                'rendered_length' => strlen($html),
            ];
        } catch (Throwable $exception) {
            return $this->exceptionPayload($exception);
        }
    }

    /** @param array<string, mixed> $data */
    private function renderCatalogIndexWithoutLayout(array $data): string
    {
        return view('storefront.catalog._content', $data)->render();
    }

    /** @return array<string, mixed> */
    private function exceptionPayload(Throwable $exception): array
    {
        return [
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
        ];
    }
}
