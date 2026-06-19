<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\BuildsStorefrontQueries;
use App\Services\Storefront\CategoryTreeService;
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
        $parts = $this->storefrontQuery($request)->paginate(60)->withQueryString();
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
            'metaTitle' => 'Katalog części GPSwiss - używane części samochodowe',
            'metaDescription' => 'Katalog oryginalnych używanych części samochodowych GPSwiss.',
            'breadcrumbs' => [['label' => 'Strona główna', 'url' => route('storefront.home')], ['label' => 'Katalog części']],
        ];
    }
}
