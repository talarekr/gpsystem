<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Marketplace\PartAvailabilityEventService;
use App\Services\Payments\PayuService;
use App\Services\Storefront\CartService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    public function __construct(private readonly CartService $cart, private readonly PartAvailabilityEventService $availabilityEvents, private readonly PayuService $payu)
    {
    }

    public function show(): View|RedirectResponse
    {
        if ($this->cart->isEmpty()) {
            return redirect()->route('storefront.cart.index')->with('warning', 'Koszyk jest pusty. Dodaj produkt przed złożeniem zamówienia.');
        }

        return view('storefront.checkout.show', $this->viewData());
    }

    public function store(Request $request): RedirectResponse
    {
        $items = $this->cart->items();

        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['cart' => 'Koszyk nie może być pusty.']);
        }

        if ($items->contains(fn (array $item): bool => ! (bool) ($item['is_available'] ?? false))) {
            throw ValidationException::withMessages(['cart' => 'Koszyk zawiera produkt niedostępny. Usuń go przed złożeniem zamówienia.']);
        }

        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'address_line1' => ['required', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:20'],
            'city' => ['required', 'string', 'max:255'],
            'country' => ['required', 'string', 'max:2'],
            'nip' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'payment_method' => ['required', 'in:payu,blik'],
            'terms' => ['accepted'],
        ]);

        $subtotal = round((float) $items->sum('line_total'), 2);

        $order = DB::transaction(function () use ($validated, $items, $subtotal, $request): Order {
            $order = Order::query()->create([
                ...collect($validated)->except('terms', 'payment_method')->all(),
                'order_number' => $this->nextOrderNumber(),
                'customer_id' => $request->user()?->id,
                'status' => 'new',
                'currency' => $items->first()['currency'] ?? 'PLN',
                'subtotal' => $subtotal,
                'shipping_total' => 0,
                'total' => $subtotal,
                'payment_status' => 'pending',
                'meta' => ['source' => 'storefront', 'payment_method' => $validated['payment_method']],
            ]);

            foreach ($items as $item) {
                $part = $item['current_part'] ?? null;
                $order->items()->create([
                    'part_id' => $item['part_id'] ?? null,
                    'product_name' => $item['name'],
                    'part_number' => $part?->part_number,
                    'sku' => $part?->sku ?: ($item['sku'] ?? null),
                    'unit_price' => $item['unit_price'],
                    'quantity' => $item['quantity'],
                    'line_total' => $item['line_total'],
                    'meta' => ['slug' => $item['slug'] ?? null],
                ]);
            }

            return $order;
        });

        $order->load('items');
        foreach ($order->items as $item) {
            if ($item->part_id) {
                $this->availabilityEvents->sold([
                    'source_channel' => 'storefront',
                    'part_id' => $item->part_id,
                    'source_order_id' => (string) $order->id,
                    'source_order_item_id' => (string) $item->id,
                ]);
            }
        }

        if (in_array($validated['payment_method'], ['payu', 'blik'], true)) {
            $payuOrder = $this->payu->createOrder($order, $request->ip() ?: '127.0.0.1');
            $response = $payuOrder['response'];
            $meta = $order->meta ?? [];
            data_set($meta, 'payu', [
                'provider' => 'payu',
                'order_id' => $response['orderId'] ?? null,
                'ext_order_id' => $payuOrder['payload']['extOrderId'] ?? null,
                'redirect_uri' => $payuOrder['redirectUri'],
                'status_desc' => data_get($response, 'status.statusDesc'),
                'correlation_id' => data_get($response, 'status.correlationId'),
                'request' => $payuOrder['payload'],
                'response' => $response,
                'created_at' => now()->toIso8601String(),
            ]);
            $order->update([
                'marketplace' => 'payu',
                'marketplace_order_id' => $response['orderId'] ?? null,
                'marketplace_status' => data_get($response, 'status.statusCode'),
                'payment_status' => 'pending',
                'meta' => $meta,
            ]);
            $this->cart->clear();

            return redirect()->away($payuOrder['redirectUri']);
        }

        $this->cart->clear();

        return redirect()->route('storefront.checkout.thank-you', $order)->with('success', 'Dziękujemy. Zamówienie zostało przyjęte.');
    }

    public function thankYou(Order $order): View
    {
        return view('storefront.checkout.thank-you', [
            'order' => $order->load('items'),
            'breadcrumbs' => [['label' => 'Strona główna', 'url' => route('storefront.home')], ['label' => 'Dziękujemy']],
            'metaTitle' => 'Dziękujemy za zamówienie - GPSwiss',
            'metaDescription' => 'Potwierdzenie zamówienia GPSwiss.',
        ]);
    }

    private function viewData(): array
    {
        return [
            'items' => $this->cart->items(),
            'subtotal' => $this->cart->subtotal(),
            'breadcrumbs' => [['label' => 'Strona główna', 'url' => route('storefront.home')], ['label' => 'Koszyk', 'url' => route('storefront.cart.index')], ['label' => 'Zamówienie']],
            'metaTitle' => 'Zamówienie - GPSwiss',
            'metaDescription' => 'Checkout sklepu GPSwiss.',
        ];
    }

    private function nextOrderNumber(): string
    {
        $prefix = 'GPS-'.now()->format('Y').'-';
        $last = Order::query()->where('order_number', 'like', $prefix.'%')->lockForUpdate()->orderByDesc('id')->value('order_number');
        $next = $last ? ((int) substr($last, -6)) + 1 : 1;

        return $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
