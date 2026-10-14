<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\BuildsStorefrontQueries;
use App\Models\Part;
use App\Services\Storefront\CategoryTreeService;
use App\Services\Storefront\FrenchMixedCatalog;
use App\Support\StorefrontContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    use BuildsStorefrontQueries;

    public function show(Request $request, CategoryTreeService $categoryTree, string $path): View|RedirectResponse
    {
        $locale = $request->attributes->get('storefront_locale', 'pl');
        $category = $categoryTree->findByPublicPath($path) ?? abort(404);
        if (StorefrontContext::isFrench($request) && trim($path, '/') !== $categoryTree->publicPath($category, 'fr')) {
            $query = $request->getQueryString();

            return redirect($categoryTree->localizedUrl($category, 'fr').($query ? '?'.$query : ''), 301);
        }
        $categoryIds = $categoryTree->categoryAndDescendantIds($category);
        $ancestors = $categoryTree->ancestors($category);
        $categoryPartsQuery = Part::query()->storefrontVisible()->whereIn('category_id', $categoryIds);
        $filterOptions = $this->storefrontFilterOptions($categoryPartsQuery);

        $query = $this->storefrontQuery($request)->whereIn('category_id', $categoryIds);
        $parts = StorefrontContext::isFrench($request)
            ? app(FrenchMixedCatalog::class)->paginate($query, $request, $categoryIds)
            : $query->paginate(60)->withQueryString();

        return view('storefront.categories.show', [
            'category' => $category,
            'categoryAncestors' => $ancestors,
            'categoryRoots' => $categoryTree->roots(),
            'categoryTreeService' => $categoryTree,
            'parts' => $parts,
            'producers' => $filterOptions['producers'],
            'models' => $filterOptions['models'],
            'metaTitle' => $category->storefrontNameForLocale($locale).' - GPSwiss',
            'metaDescription' => $locale === 'fr'
                ? str($category->storefrontDescriptionForLocale($locale) ?: $category->storefrontNameForLocale($locale))->stripTags()->limit(155)->toString()
                : 'Szeroki wybór oryginalnych, używanych części samochodowych w kategorii '.$category->name.'.',
            'breadcrumbs' => [],
        ]);
    }
}
