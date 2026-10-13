<?php

namespace App\Services\Storefront;

use App\Support\StorefrontContext;
use Illuminate\Http\Request;

/** Read-only draft builder. Never persists orders, reserves stock or invokes payments. */
class FrenchCheckoutService
{
    public function __construct(private CartService $cart, private FrenchDeliveryQuoteService $deliveryQuotes) {}

    public function draft(Request $request): array
    {
        abort_unless(StorefrontContext::isFrench($request), 404);
        $address = array_replace($this->defaults(), $request->session()->get('storefront.fr.address', []));
        $items = $this->cart->items()->map(function (array $item): array {
            $part = $item['current_part'] ?? null;
            $price = $part && config('storefront-fr-checkout.pricing_policy') === 'catalog_eur'
                && strtoupper((string) $part->currency) === 'EUR' && is_numeric($part->price) && (float) $part->price > 0
                ? (int) round((float) $part->price * 100) : null;
            $quantity = max(1, (int) $item['quantity']);

            return [
                'part_id' => $item['part_id'], 'sku' => $part?->sku ?? $item['sku'] ?? null,
                'part_number' => $part?->part_number,
                'product_name' => $part?->storefrontNameForLocale('fr') ?? $item['name'],
                'quantity' => $quantity, 'unit_price_cents' => $price,
                'line_total_cents' => $price === null ? null : $price * $quantity,
                'currency' => 'EUR', 'image_url' => $item['image_url'] ?? null,
                'product_url' => route('storefront.product', $part?->slug ?: ($item['slug'] ?? $item['part_id'])),
                'delivery_quote' => $this->deliveryQuotes->forPart($part),
                'is_available' => (bool) ($item['is_available'] ?? false),
                'meta' => ['storefront_code' => 'gpswiss_fr', 'locale' => 'fr', 'price_source' => $price === null ? null : 'parts.price(EUR)', 'slug' => $part?->slug ?? $item['slug'] ?? null],
            ];
        })->all();
        $pricesReady = $items !== [] && collect($items)->every(fn ($item) => $item['unit_price_cents'] !== null && $item['is_available']);
        $subtotal = $pricesReady ? array_sum(array_column($items, 'line_total_cents')) : null;
        // No invented multi-item/additional-unit rate: eBay mappings describe item policies.
        $shipping = count($items) === 1 && $items[0]['quantity'] === 1 && $items[0]['delivery_quote']['available']
            ? $items[0]['delivery_quote']['amount_cents'] : null;
        $billing = $this->addressSnapshot($address, 'billing');
        $delivery = (bool) $address['shipping_same_as_billing'] ? $billing : $this->addressSnapshot($address, 'shipping');

        return [
            'storefront_code' => 'gpswiss_fr', 'locale' => 'fr', 'currency' => 'EUR',
            'payment_provider' => 'stripe', 'can_place_order' => false,
            'prices_ready' => $pricesReady, 'shipping_ready' => $shipping !== null,
            'subtotal_cents' => $subtotal, 'shipping_cents' => $shipping,
            'total_cents' => $subtotal !== null && $shipping !== null ? $subtotal + $shipping : null,
            'address' => $address, 'billing' => $billing, 'shipping' => $delivery, 'items' => $items,
            'order_items' => array_map(fn ($item) => [
                'part_id' => $item['part_id'], 'product_name' => $item['product_name'],
                'part_number' => $item['part_number'], 'sku' => $item['sku'],
                'quantity' => $item['quantity'], 'currency' => 'EUR',
                'unit_price' => $item['unit_price_cents'] === null ? null : number_format($item['unit_price_cents'] / 100, 2, '.', ''),
                'line_total' => $item['line_total_cents'] === null ? null : number_format($item['line_total_cents'] / 100, 2, '.', ''),
                'meta' => $item['meta'] + ['product_url' => $item['product_url'], 'delivery_quote' => $item['delivery_quote']],
            ], $items),
            'order_attributes' => [
                'currency' => 'EUR', 'status' => 'new', 'payment_status' => 'pending',
                'customer_name' => trim($address['billing_first_name'].' '.$address['billing_last_name']),
                'email' => $address['billing_email'], 'phone' => $address['billing_phone'],
                'company_name' => $address['customer_type'] === 'company' ? $address['billing_company_name'] : null,
                'nip' => null, 'country' => 'FR', 'address_line1' => $billing['address_line1'],
                'postal_code' => $billing['postal_code'], 'city' => $billing['city'],
                'subtotal' => $subtotal === null ? null : number_format($subtotal / 100, 2, '.', ''),
                'shipping_total' => $shipping === null ? null : number_format($shipping / 100, 2, '.', ''),
                'total' => $subtotal !== null && $shipping !== null ? number_format(($subtotal + $shipping) / 100, 2, '.', '') : null,
                'delivery_method' => 'courier', 'invoice_data' => $billing + [
                    'company_name' => $address['billing_company_name'], 'customer_type' => $address['customer_type'],
                    'email' => $address['billing_email'], 'phone' => $address['billing_phone'],
                    'siret' => $address['billing_siret'], 'vat_number' => $address['billing_vat_number'],
                ],
                'meta' => StorefrontContext::orderMetadata($request) + [
                    'source' => 'storefront', 'shipping' => $delivery, 'shipping_same_as_billing' => (bool) $address['shipping_same_as_billing'],
                ],
            ],
        ];
    }

    public function validateAddress(Request $request): array
    {
        abort_unless(StorefrontContext::isFrench($request), 404);

        return $request->validate([
            'customer_type' => ['required', 'in:private,company'],
            'billing_first_name' => ['required', 'string', 'max:120'],
            'billing_last_name' => ['required', 'string', 'max:120'],
            'billing_company_name' => ['nullable', 'required_if:customer_type,company', 'string', 'max:255'],
            'billing_siret' => ['nullable', 'regex:/^[0-9]{14}$/'],
            'billing_vat_number' => ['nullable', 'string', 'max:32'],
            'billing_address_line1' => ['required', 'string', 'max:255'],
            'billing_address_line2' => ['nullable', 'string', 'max:255'],
            'billing_postal_code' => ['required', 'regex:/^[0-9]{5}$/'],
            'billing_city' => ['required', 'string', 'max:255'],
            'billing_country' => ['required', 'in:FR'],
            'billing_email' => ['required', 'email', 'max:255'],
            'billing_phone' => ['required', 'string', 'max:50'],
            'shipping_same_as_billing' => ['required', 'boolean'],
            'shipping_first_name' => ['nullable', 'required_if:shipping_same_as_billing,0', 'string', 'max:120'],
            'shipping_last_name' => ['nullable', 'required_if:shipping_same_as_billing,0', 'string', 'max:120'],
            'shipping_address_line1' => ['nullable', 'required_if:shipping_same_as_billing,0', 'string', 'max:255'],
            'shipping_address_line2' => ['nullable', 'string', 'max:255'],
            'shipping_postal_code' => ['nullable', 'required_if:shipping_same_as_billing,0', 'regex:/^[0-9]{5}$/'],
            'shipping_city' => ['nullable', 'required_if:shipping_same_as_billing,0', 'string', 'max:255'],
            'shipping_country' => ['required', 'in:FR'],
        ], [
            'required' => 'Le champ :attribute est obligatoire.',
            'required_if' => 'Le champ :attribute est obligatoire pour cette sélection.',
            'in' => 'La valeur du champ :attribute n’est pas autorisée.',
            'regex' => 'Le format du champ :attribute est incorrect.',
        ], [
            'billing_country' => 'pays de facturation', 'shipping_country' => 'pays de livraison',
            'billing_postal_code' => 'code postal de facturation', 'shipping_postal_code' => 'code postal de livraison',
            'billing_company_name' => 'raison sociale', 'billing_siret' => 'SIRET',
        ]);
    }

    private function defaults(): array
    {
        return array_fill_keys([
            'billing_first_name', 'billing_last_name', 'billing_company_name', 'billing_siret', 'billing_vat_number',
            'billing_address_line1', 'billing_address_line2', 'billing_postal_code', 'billing_city', 'billing_email', 'billing_phone',
            'shipping_first_name', 'shipping_last_name', 'shipping_address_line1', 'shipping_address_line2', 'shipping_postal_code', 'shipping_city',
        ], '') + ['customer_type' => 'private', 'shipping_same_as_billing' => true, 'billing_country' => 'FR', 'shipping_country' => 'FR'];
    }

    private function addressSnapshot(array $address, string $prefix): array
    {
        $result = [];
        foreach (['first_name', 'last_name', 'address_line1', 'address_line2', 'postal_code', 'city', 'country'] as $field) {
            $result[$field] = $address[$prefix.'_'.$field];
        }

        return $result;
    }
}
