<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Part;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CheckCatalogRenderController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        try {
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

            $q = $request->string('q')->toString();
            $columns = $this->availablePartColumns(['id', 'name', 'part_number', 'sku', 'price', 'slug', 'category_id']);
            $parts = Part::query()
                ->searchStorefront($q)
                ->select($columns)
                ->limit(5)
                ->get();

            return response()->json([
                'ok' => true,
                'q' => $q,
                'limit' => 5,
                'returned' => $parts->count(),
                'pagination' => $this->safeTry(fn (): array => [
                    'count' => (clone Part::query()->searchStorefront($q))->count(),
                    'sample_limit' => 5,
                ]),
                'first' => $parts->map(fn (Part $part): array => $this->partSnapshot($part))->values()->all(),
            ]);
        } catch (Throwable $exception) {
            return response()->json($this->exceptionPayload($exception), 500);
        }
    }

    /**
     * @param array<int, string> $columns
     * @return array<int, string>
     */
    private function availablePartColumns(array $columns): array
    {
        $available = array_values(array_filter($columns, fn (string $column): bool => Schema::hasColumn('parts', $column)));

        return $available === [] ? ['*'] : $available;
    }

    private function partSnapshot(Part $part): array
    {
        return [
            'id' => $part->id,
            'name' => $part->name ?? null,
            'part_number' => $part->part_number ?? null,
            'sku' => $part->sku ?? null,
            'price' => $part->price ?? null,
            'image' => $this->safeTry(fn (): array => [
                'has_image_record' => $part->listingImage() !== null,
                'url' => $part->listingImageUrl(),
            ]),
            'product_url' => $this->safeTry(fn (): ?string => route('storefront.product', $part->slug ?: $part->id)),
            'category' => $this->safeTry(fn (): ?array => $part->category ? [
                'id' => $part->category->id,
                'name' => $part->category->name,
                'slug' => $part->category->slug,
            ] : null),
        ];
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

    /**
     * @return array<string, mixed>
     */
    private function exceptionPayload(Throwable $exception): array
    {
        return [
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
        ];
    }
}
