<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\BuildsStorefrontQueries;
use App\Models\Car;
use App\Services\Storefront\CategoryTreeService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    use BuildsStorefrontQueries;

    public function show(Request $request, CategoryTreeService $categoryTree, string $path): View
    {
        $category = $categoryTree->findByPublicPath($path) ?? abort(404);
        $categoryIds = $categoryTree->categoryAndDescendantIds($category);
        $ancestors = $categoryTree->ancestors($category);

        return view('storefront.categories.show', [
            'category' => $category,
            'categoryAncestors' => $ancestors,
            'categoryRoots' => $categoryTree->roots(),
            'categoryTreeService' => $categoryTree,
            'parts' => $this->storefrontQuery($request)->whereIn('category_id', $categoryIds)->paginate(60)->withQueryString(),
            'cars' => Car::query()->select('make', 'model')->whereNotNull('make')->distinct()->orderBy('make')->limit(80)->get(),
            'metaTitle' => $category->name.' - GPSwiss',
            'metaDescription' => 'Szeroki wybór oryginalnych, używanych części samochodowych w kategorii '.$category->name.'.',
            'breadcrumbs' => [],
        ]);
    }
}
