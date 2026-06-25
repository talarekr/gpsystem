<?php

namespace App\Http\Controllers\Storefront\Concerns;

use App\Models\Part;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

trait BuildsStorefrontQueries
{
    protected function storefrontQuery(Request $request): Builder
    {
        $query = Part::query()
            ->with(['images', 'category', 'car'])
            ->storefrontVisible()
            ->searchStorefront($request->string('q')->toString())
            ->partNumberSearch($request->string('part_number')->toString())
            ->priceBetween(
                $request->input('price_from', $request->input('price_min')),
                $request->input('price_to', $request->input('price_max')),
            );

        $producer = trim($request->string('producer')->toString());
        if ($producer !== '') {
            $query->whereStorefrontDetail('make', $producer);
        }

        $model = trim($request->string('model')->toString());
        if ($model !== '') {
            $query->whereStorefrontDetail('model', $model);
        }

        $vehicleModel = trim($request->string('vehicle_model')->toString());
        if ($vehicleModel !== '') {
            foreach (preg_split('/\s+/', $vehicleModel) ?: [] as $token) {
                $query->where(function (Builder $inner) use ($token): void {
                    $like = '%'.$token.'%';
                    $inner->where('name', 'like', $like)->orWhereHas('car', function (Builder $carQuery) use ($like): void {
                        $carQuery->where('make', 'like', $like)
                            ->orWhere('model', 'like', $like)
                            ->orWhere('model_variant', 'like', $like)
                            ->orWhere('engine_code', 'like', $like);
                    });
                });
            }
        }

        $category = trim($request->string('category')->toString());
        if ($category !== '') {
            $query->whereHas('category', function (Builder $categoryQuery) use ($category): void {
                if (Schema::hasColumn('part_categories', 'is_visible')) $categoryQuery->where('is_visible', true);
                $categoryQuery->where(fn (Builder $inner) => $inner->where('slug', $category)->orWhere('name', 'like', '%'.$category.'%'));
            });
        }

        match ($request->string('sort')->toString()) {
            'price_asc' => $query->orderByRaw('price is null')->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'name' => $query->orderBy('name'),
            default => $query->latest('updated_at'),
        };

        return $query;
    }

    protected function storefrontFilterOptions(Builder $baseQuery): array
    {
        $parts = (clone $baseQuery)->with('car')->get();

        return [
            'producers' => $this->uniqueStorefrontDetailOptions($parts, 'make'),
            'models' => $this->uniqueStorefrontDetailOptions($parts, 'model'),
        ];
    }

    private function uniqueStorefrontDetailOptions($parts, string $key): array
    {
        return $parts
            ->map(fn (Part $part) => $part->storefrontDetailValue($key))
            ->filter()
            ->unique(fn (string $value) => mb_strtolower($value))
            ->sort(fn (string $a, string $b) => strnatcasecmp($a, $b))
            ->values()
            ->all();
    }
}
