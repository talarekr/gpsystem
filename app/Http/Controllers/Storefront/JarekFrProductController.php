<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\JarekFrProduct;
use App\Services\Storefront\CartService;
use App\Services\Storefront\JarekFrPresentation;
use App\Support\StorefrontContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class JarekFrProductController extends Controller
{
    private function french(Request $request): void
    {
        abort_unless(StorefrontContext::isFrench($request) && Schema::hasTable('jarek_fr_products'), 404);
    }

    public function index(Request $request, CatalogController $catalog)
    {
        $this->french($request);
        $data = $catalog->viewData($request);
        $data['parts'] = collect();
        $data['jarekOnly'] = true;

        return view('storefront.catalog.index', $data);
    }

    public function show(Request $request, int $product, JarekFrPresentation $presentation)
    {
        $this->french($request);
        $source = JarekFrProduct::query()->active()->with('gearbox.translations')->findOrFail($product);
        $p = $presentation->forProduct($source);

        return view('storefront.fr.jarek-product', ['product' => $p, 'metaTitle' => $p['title'].' — GPSwiss.fr',
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
