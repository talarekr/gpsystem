<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\BuildsStorefrontQueries;
use App\Services\Storefront\CategoryTreeService;
use App\Services\Storefront\FrenchMixedCatalog;
use App\Support\StorefrontContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Throwable;

class CatalogController extends Controller
{
    use BuildsStorefrontQueries;

    public function index(Request $request): View
    {
        return view('storefront.catalog.index', $this->viewData($request));
    }

    /**
     * @return array<string, mixed>
     */
    public function viewData(Request $request, ?CategoryTreeService $categoryTree = null): array
    {
        $query = $this->storefrontQuery($request);
        $parts = StorefrontContext::isFrench($request)
            ? app(FrenchMixedCatalog::class)->paginate($query, $request)
            : $query->paginate(60)->withQueryString();
        $filterOptions = [
            'producers' => collect(),
            'models' => collect(),
        ];
        $categoryRoots = collect();
        $safeCategoryTree = null;

        try {
            $categoryTree ??= app(CategoryTreeService::class);
            $categoryRoots = $categoryTree->roots();
            $safeCategoryTree = $categoryTree;
        } catch (Throwable) {
            $categoryRoots = collect();
            $safeCategoryTree = null;
        }

        return [
            'parts' => $parts,
            'categoryRoots' => $categoryRoots,
            'categoryTreeService' => $safeCategoryTree,
            'producers' => $filterOptions['producers'],
            'models' => $filterOptions['models'],
            'metaTitle' => StorefrontContext::isFrench($request) ? 'Catalogue de pièces automobiles d’occasion — GPSwiss.fr' : 'Katalog części GPSwiss - używane części samochodowe',
            'metaDescription' => StorefrontContext::isFrench($request) ? 'Découvrez les pièces automobiles d’occasion GPSwiss.fr : moteurs, boîtes de vitesses et équipements automobiles.' : 'Katalog oryginalnych używanych części samochodowych GPSwiss.',
            'breadcrumbs' => [['label' => __('storefront.home'), 'url' => route('storefront.home')], ['label' => __('storefront.catalog')]],
        ];
    }
}
