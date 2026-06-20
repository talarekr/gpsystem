<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\BuildsStorefrontQueries;
use App\Services\Storefront\CategoryTreeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class CheckPartNumberPerformanceController extends Controller
{
    use BuildsStorefrontQueries;

    public function __invoke(Request $request): JsonResponse
    {
        if (! hash_equals('gps_images_import_2026', (string) $request->query('token', ''))) {
            return response()->json([
                'ok' => false,
                'error_message' => 'Invalid diagnostics token.',
            ], 403);
        }

        $partNumber = trim($request->string('part_number')->toString());
        $perPage = 60;
        $queries = [];
        $startedAt = microtime(true);

        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = [
                'sql' => $query->sql,
                'bindings' => $query->bindings,
                'time_ms' => $query->time,
            ];
        });

        try {
            $buildStartedAt = microtime(true);
            $query = $this->storefrontQuery($request);
            $sql = $query->toSql();
            $bindings = $query->getBindings();
            $buildTimeMs = $this->elapsedMs($buildStartedAt);

            $explain = $this->explain($sql, $bindings);
            $indexes = $this->indexes('parts');

            $queryStartedAt = microtime(true);
            $parts = $query->paginate($perPage)->withQueryString();
            $queryTimeMs = $this->elapsedMs($queryStartedAt);

            $renderStartedAt = microtime(true);
            $html = view('storefront.catalog.index', [
                'parts' => $parts,
                'categoryRoots' => $this->categoryRoots(),
                'categoryTreeService' => $this->categoryTreeService(),
                'producers' => collect(),
                'models' => collect(),
                'metaTitle' => 'Katalog części GPSwiss - używane części samochodowe',
                'metaDescription' => 'Katalog oryginalnych używanych części samochodowych GPSwiss.',
                'breadcrumbs' => [['label' => 'Strona główna', 'url' => route('storefront.home')], ['label' => 'Katalog części']],
            ])->render();
            $renderTimeMs = $this->elapsedMs($renderStartedAt);

            return response()->json([
                'ok' => true,
                'part_number' => $partNumber,
                'total_time_ms' => $this->elapsedMs($startedAt),
                'build_time_ms' => $buildTimeMs,
                'query_time_ms' => $queryTimeMs,
                'render_time_ms' => $renderTimeMs,
                'count' => $parts->count(),
                'total' => $parts->total(),
                'per_page' => $parts->perPage(),
                'sql' => $sql,
                'bindings' => $bindings,
                'explain' => $explain,
                'indexes' => $indexes,
                'matched_ids' => $parts->getCollection()->pluck('id')->values(),
                'queries' => $queries,
                'rendered_length' => strlen($html),
                'diagnosis_flags' => $this->diagnosisFlags($sql),
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'ok' => false,
                'part_number' => $partNumber,
                'total_time_ms' => $this->elapsedMs($startedAt),
                'error_class' => $exception::class,
                'error_message' => $exception->getMessage(),
                'queries' => $queries,
            ], 500);
        }
    }

    private function elapsedMs(float $startedAt): float
    {
        return round((microtime(true) - $startedAt) * 1000, 2);
    }

    /**
     * @param array<int, mixed> $bindings
     * @return array<int, array<string, mixed>>
     */
    private function explain(string $sql, array $bindings): array
    {
        try {
            return array_map(static fn ($row): array => (array) $row, DB::select('EXPLAIN '.$sql, $bindings));
        } catch (Throwable $exception) {
            return [[
                'error_class' => $exception::class,
                'error_message' => $exception->getMessage(),
            ]];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function indexes(string $table): array
    {
        try {
            return array_map(static fn ($row): array => (array) $row, DB::select('SHOW INDEX FROM '.$table));
        } catch (Throwable) {
            try {
                return array_map(static fn ($row): array => (array) $row, DB::select('PRAGMA index_list('.$table.')'));
            } catch (Throwable $exception) {
                return [[
                    'error_class' => $exception::class,
                    'error_message' => $exception->getMessage(),
                ]];
            }
        }
    }

    /**
     * @return array<string, bool>
     */
    private function diagnosisFlags(string $sql): array
    {
        $lowerSql = strtolower($sql);

        return [
            'uses_like_contains' => str_contains($lowerSql, 'like ?'),
            'uses_many_or_like' => substr_count($lowerSql, ' or ') >= 3 && str_contains($lowerSql, 'like ?'),
            'uses_lower' => str_contains($lowerSql, 'lower('),
            'uses_where_has_or_relation_exists' => str_contains($lowerSql, 'exists (') || str_contains($lowerSql, ' cars '),
            'uses_full_search_scope_when_q_empty' => str_contains($lowerSql, 'short_description') || str_contains($lowerSql, 'legacy_payload'),
        ];
    }

    private function categoryRoots()
    {
        try {
            return app(CategoryTreeService::class)->roots();
        } catch (Throwable) {
            return collect();
        }
    }

    private function categoryTreeService(): ?CategoryTreeService
    {
        try {
            return app(CategoryTreeService::class);
        } catch (Throwable) {
            return null;
        }
    }
}
