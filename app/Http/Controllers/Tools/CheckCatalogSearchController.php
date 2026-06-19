<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\BuildsStorefrontQueries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CheckCatalogSearchController extends Controller
{
    use BuildsStorefrontQueries;

    public function __invoke(Request $request): JsonResponse
    {
        if (! hash_equals('gps_images_import_2026', (string) $request->query('token', ''))) {
            abort(403);
        }

        try {
            $query = $this->storefrontQuery($request);
            $count = (clone $query)->count();
            $columns = array_values(array_filter(['id', 'name', 'part_number', 'sku'], fn (string $column): bool => Schema::hasColumn('parts', $column)));
            $rows = (clone $query)
                ->select($columns)
                ->limit(5)
                ->get()
                ->map(fn ($part): array => collect(['id', 'name', 'part_number', 'sku'])
                    ->filter(fn (string $column): bool => in_array($column, $columns, true))
                    ->mapWithKeys(fn (string $column): array => [$column => $part->{$column}])
                    ->all())
                ->all();

            return response()->json([
                'ok' => true,
                'count' => $count,
                'first' => $rows,
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'ok' => false,
                'count' => 0,
                'first' => [],
                'error' => [
                    'class' => $exception::class,
                    'message' => $exception->getMessage(),
                ],
            ], 500);
        }
    }
}
