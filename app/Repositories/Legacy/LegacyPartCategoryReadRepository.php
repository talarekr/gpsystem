<?php

namespace App\Repositories\Legacy;

use App\Models\PartCategory;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LegacyPartCategoryReadRepository
{
    /** @return EloquentCollection<int, PartCategory> */
    public function publicCategories(): EloquentCollection
    {
        $connection = (string) config('storefront.legacy_connection');
        if (! Schema::connection($connection)->hasTable('part_categories')) {
            return new EloquentCollection();
        }

        $columns = ['id', 'parent_id', 'source_system', 'name', 'slug', 'sort_order', 'category_path', 'full_slug_path', 'woo_product_count', 'is_visible'];
        $available = array_values(array_filter($columns, fn (string $column): bool => Schema::connection($connection)->hasColumn('part_categories', $column)));

        $rows = DB::connection($connection)->table('part_categories')
            ->select($available)
            ->where(fn ($query) => $query->where('source_system', 'woo')->orWhereNull('source_system'))
            ->when(in_array('is_visible', $available, true), fn ($query) => $query->where('is_visible', true))
            ->where(fn ($query) => $query->whereNull('name')->orWhereRaw('LOWER(TRIM(name)) <> ?', ['bez kategorii']))
            ->orderByRaw("case when source_system = 'woo' then 0 else 1 end")
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return new EloquentCollection($rows->map(function (object $row): PartCategory {
            $category = new PartCategory((array) $row);
            $category->exists = true;
            return $category;
        })->all());
    }
}
