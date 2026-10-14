<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Services\Storefront\CartService;
use App\Services\Storefront\FrenchCheckoutService;
use App\Services\Storefront\FrenchProductPresentation;
use App\Support\StorefrontContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class CartController extends Controller
{
    public function __construct(private readonly CartService $cart) {}

    public function index(Request $request, FrenchCheckoutService $french): View|Response
    {
        if (StorefrontContext::isFrench($request)) {
            return response()->view('storefront.fr.cart', [
                'draft' => $french->draft($request),
                'metaTitle' => 'Panier — GPSwiss', 'metaDescription' => 'Votre panier GPSwiss France.',
            ])->header('Cache-Control', 'private, no-store');
        }

        return view('storefront.cart.index', [
            'items' => $this->cart->items(),
            'subtotal' => $this->cart->subtotal(),
            'isEmpty' => $this->cart->isEmpty(),
            'breadcrumbs' => [
                ['label' => __('storefront.home'), 'url' => route('storefront.home')],
                ['label' => __('storefront.cart')],
            ],
            'metaTitle' => 'Koszyk - GPSwiss',
            'metaDescription' => 'Koszyk produktów GPSwiss.',
        ]);
    }

    public function add(Part $part): RedirectResponse
    {
        if (StorefrontContext::isFrench(request())
            && ! app(FrenchProductPresentation::class)->forPart($part)['can_buy']) {
            return back()->with('warning', __('storefront.fr_purchase_unavailable'));
        }

        $result = $this->cart->add($part, 1);

        return back()->with($result['status'], $result['message']);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'quantities' => StorefrontContext::isFrench($request) ? ['required', 'array', 'max:1'] : ['required', 'array'],
            'quantities.*' => StorefrontContext::isFrench($request) ? ['required', 'integer', 'min:1', 'max:1'] : ['required', 'integer', 'min:1'],
        ]);

        $lastResult = ['status' => 'success', 'message' => StorefrontContext::isFrench($request) ? 'Panier mis à jour.' : 'Zaktualizowano koszyk.'];

        foreach ($validated['quantities'] as $partId => $quantity) {
            $lastResult = StorefrontContext::isFrench($request) && str_starts_with((string) $partId, 'jarek:')
                ? $this->cart->updateJarek((int) substr($partId, 6), (int) $quantity)
                : $this->cart->update((int) $partId, (int) $quantity);
        }

        return redirect()->route(StorefrontContext::routeName(request(), 'cart.index'))->with($lastResult['status'], $lastResult['message']);
    }

    public function remove(Part $part): RedirectResponse
    {
        $this->cart->remove((int) $part->id);

        return redirect()->route(StorefrontContext::routeName(request(), 'cart.index'))->with('success', __('storefront.cart_removed'));
    }

    public function clear(): RedirectResponse
    {
        $this->cart->clear();

        return redirect()->route(StorefrontContext::routeName(request(), 'cart.index'))->with('success', __('storefront.cart_cleared'));
    }
}
