<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\BuildsStorefrontQueries;
use App\Models\Part;
use App\Services\Storefront\CategoryTreeService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    use BuildsStorefrontQueries;

    public function index(Request $request, CategoryTreeService $categoryTree): View
    {
        $filterOptions = $this->storefrontFilterOptions(Part::query()->storefrontVisible());

        return view('storefront.catalog.index', [
            'parts' => $this->storefrontQuery($request)->paginate(60)->withQueryString(),
            'categoryRoots' => $categoryTree->roots(),
            'categoryTreeService' => $categoryTree,
            'producers' => $filterOptions['producers'],
            'models' => $filterOptions['models'],
            'metaTitle' => 'Sklep GPSwiss - używane części samochodowe',
            'metaDescription' => 'Katalog oryginalnych używanych części samochodowych GPSwiss.',
            'breadcrumbs' => [['label' => 'Strona główna', 'url' => route('storefront.home')], ['label' => 'Sklep']],
        ]);
    }
}
