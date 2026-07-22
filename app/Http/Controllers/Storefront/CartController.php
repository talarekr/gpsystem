<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Services\Storefront\CartService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CartController extends Controller
{
    public function __construct(private readonly CartService $cart)
    {
    }

    public function index(): View
    {
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
        $result = $this->cart->add($part, 1);

        return back()->with($result['status'], $result['message']);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'quantities' => ['required', 'array'],
            'quantities.*' => ['required', 'integer', 'min:1'],
        ]);

        $lastResult = ['status' => 'success', 'message' => 'Zaktualizowano koszyk.'];

        foreach ($validated['quantities'] as $partId => $quantity) {
            $lastResult = $this->cart->update((int) $partId, (int) $quantity);
        }

        return redirect()->route('storefront.cart.index')->with($lastResult['status'], $lastResult['message']);
    }

    public function remove(Part $part): RedirectResponse
    {
        $this->cart->remove((int) $part->id);

        return redirect()->route('storefront.cart.index')->with('success', __('storefront.cart_removed'));
    }

    public function clear(): RedirectResponse
    {
        $this->cart->clear();

        return redirect()->route('storefront.cart.index')->with('success', __('storefront.cart_cleared'));
    }
}
