<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\JarekFrProduct;
use App\Services\Storefront\CartService;
use App\Services\Storefront\CategoryTreeService;
use App\Services\Storefront\FrenchMixedCatalog;
use App\Services\Storefront\JarekCatalogProduct;
use App\Support\StorefrontContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class JarekFrProductController extends Controller
{
    private function french(Request $request): void
    {
        abort_unless(StorefrontContext::isFrench($request) && Schema::hasTable('jarek_fr_products'), 404);
    }

    public function index(Request $request)
    {
        $this->french($request);
        $query = $request->query();
        if (isset($query['jarek_page'])) {
            $query['page'] ??= $query['jarek_page'];
            unset($query['jarek_page']);
        }

        return redirect('/kategoria-produktu/'.FrenchMixedCatalog::CATEGORY_PATH.($query ? '?'.http_build_query($query) : ''), 301);
    }

    public function show(Request $request, int $product, CategoryTreeService $categoryTree)
    {
        $this->french($request);
        $source = JarekFrProduct::query()->active()->with('gearbox.translations')->findOrFail($product);
        $category = $categoryTree->findByPublicPath(FrenchMixedCatalog::CATEGORY_PATH);
        $adapter = new JarekCatalogProduct($source, $category);
        $p = $adapter->presentation;
        $breadcrumbs = [['label' => __('storefront.home'), 'url' => route('storefront.home')]];
        if ($category) {
            foreach ($categoryTree->ancestors($category)->push($category) as $crumb) {
                $breadcrumbs[] = ['label' => $crumb->storefrontPublicNameForLocale('fr'), 'url' => $categoryTree->url($crumb)];
            }
        }
        $breadcrumbs[] = ['label' => $p['title']];

        return view('storefront.parts.show', ['part' => $adapter,
            'related' => collect(), 'breadcrumbs' => $breadcrumbs, 'metaTitle' => $p['title'].' — GPSwiss.fr',
            'metaDescription' => mb_substr($p['description'], 0, 155)]);
    }

    public function add(Request $request, int $product, CartService $cart)
    {
        $this->french($request);
        $input = $request->validate(['quantity' => ['sometimes', 'integer', 'in:1']]);
        $result = $cart->addJarek($product, (int) ($input['quantity'] ?? 1));

        return back()->with($result['status'], $result['message']);
    }

    public function remove(Request $request, int $product, CartService $cart)
    {
        $this->french($request);
        $cart->removeJarek($product);

        return redirect()->route(StorefrontContext::routeName($request, 'cart.index'))->with('success', __('storefront.cart_removed'));
    }
}
