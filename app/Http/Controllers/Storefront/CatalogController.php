<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\BuildsStorefrontQueries;
use App\Models\Car;
use App\Services\Storefront\CategoryTreeService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    use BuildsStorefrontQueries;

    public function index(Request $request, CategoryTreeService $categoryTree): View
    {
        return view('storefront.catalog.index', [
            'parts' => $this->storefrontQuery($request)->paginate(60)->withQueryString(),
            'categoryRoots' => $categoryTree->roots(),
            'categoryTreeService' => $categoryTree,
            'cars' => Car::query()->select('make', 'model')->whereNotNull('make')->distinct()->orderBy('make')->limit(80)->get(),
            'metaTitle' => 'Sklep GPSwiss - używane części samochodowe',
            'metaDescription' => 'Katalog oryginalnych używanych części samochodowych GPSwiss.',
            'breadcrumbs' => [['label' => 'Strona główna', 'url' => route('storefront.home')], ['label' => 'Sklep']],
        ]);
    }
}
