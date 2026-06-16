<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\BuildsStorefrontQueries;
use App\Models\Car;
use App\Models\PartCategory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    use BuildsStorefrontQueries;

    public function show(Request $request, string $path): View
    {
        $path = trim($path, '/');
        $lastSegment = collect(explode('/', $path))->filter()->last();

        $category = PartCategory::query()
            ->where('full_slug_path', $path)
            ->orWhere('category_path', $path)
            ->orWhere('slug', $path)
            ->orWhere('slug', $lastSegment)
            ->firstOrFail();

        $categoryIds = $this->categoryAndDescendantIds($category);

        return view('storefront.categories.show', [
            'category' => $category,
            'parts' => $this->storefrontQuery($request)->whereIn('category_id', $categoryIds)->paginate(60)->withQueryString(),
            'categories' => PartCategory::query()->ordered()->limit(80)->get(),
            'cars' => Car::query()->select('make', 'model')->whereNotNull('make')->distinct()->orderBy('make')->limit(80)->get(),
            'metaTitle' => $category->name.' - GPSwiss',
            'metaDescription' => 'Szeroki wybór oryginalnych, używanych części samochodowych w kategorii '.$category->name.'.',
            'breadcrumbs' => [
                ['label' => 'Strona główna', 'url' => route('storefront.home')],
                ['label' => 'Sklep', 'url' => route('storefront.catalog')],
                ['label' => $category->name],
            ],
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function categoryAndDescendantIds(PartCategory $category): array
    {
        $ids = [$category->id];
        $frontier = [$category->id];

        while ($frontier !== []) {
            $children = PartCategory::query()
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $children = array_values(array_diff($children, $ids));
            $ids = array_merge($ids, $children);
            $frontier = $children;
        }

        return $ids;
    }
}
