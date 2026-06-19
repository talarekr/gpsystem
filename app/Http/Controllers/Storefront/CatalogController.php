<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\BuildsStorefrontQueries;
use App\Models\Part;
use App\Services\Storefront\CategoryTreeService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Throwable;

class CatalogController extends Controller
{
    use BuildsStorefrontQueries;

    public function index(Request $request, CategoryTreeService $categoryTree): View
    {
        try {
            $filterOptions = $this->storefrontFilterOptions(Part::query()->storefrontVisible());
        } catch (Throwable) {
            $filterOptions = ['producers' => [], 'models' => []];
        }

        try {
            $categoryRoots = $categoryTree->roots();
        } catch (Throwable) {
            $categoryRoots = collect();
        }

        return view('storefront.catalog.index', [
            'parts' => $this->storefrontQuery($request)->paginate(60)->withQueryString(),
            'categoryRoots' => $categoryRoots,
            'categoryTreeService' => $categoryTree,
            'producers' => $filterOptions['producers'] ?? [],
            'models' => $filterOptions['models'] ?? [],
            'metaTitle' => 'Katalog części GPSwiss - używane części samochodowe',
            'metaDescription' => 'Katalog oryginalnych używanych części samochodowych GPSwiss.',
            'breadcrumbs' => [['label' => 'Strona główna', 'url' => route('storefront.home')], ['label' => 'Katalog części']],
        ]);
    }
}
