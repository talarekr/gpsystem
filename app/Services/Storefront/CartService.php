<?php

namespace App\Services\Storefront;

use App\Models\JarekFrProduct;
use App\Models\Part;
use App\Support\StorefrontContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class CartService
{
    private const SESSION_KEY = 'storefront.cart.items';

    public function items(): Collection
    {
        $items = collect(session(self::SESSION_KEY, []));

        if ($items->isEmpty()) {
            return $items;
        }

        $parts = Part::query()
            ->with('images')
            ->storefrontVisible()
            ->whereIn('id', $items->keys()->map(fn ($key) => (int) $key)->all())
            ->get()
            ->keyBy('id');

        $jarek = StorefrontContext::isFrench(request()) && Schema::hasTable('jarek_fr_products')
            ? JarekFrProduct::query()->active()->with('gearbox.translations')->whereIn('id', $items->where('source_type', 'jarek_gearbox')->pluck('jarek_fr_product_id'))->get()->keyBy('id') : collect();

        return $items->map(function (array $item) use ($parts, $jarek): ?array {
            if (($item['source_type'] ?? '') === 'jarek_gearbox') {
                if (! StorefrontContext::isFrench(request())) {
                    return null;
                }
                $product = $jarek->get((int) ($item['jarek_fr_product_id'] ?? 0));
                $p = $product ? app(JarekFrPresentation::class)->forProduct($product) : null;

                return array_replace($item, ['current_part' => null, 'current_jarek_product' => $product, 'current_quantity' => 1,
                    'is_available' => (bool) ($p['can_buy'] ?? false), 'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                    'line_total' => ($p['cents'] ?? 0) / 100 * max(1, (int) ($item['quantity'] ?? 1)),
                    'name' => $p['title'] ?? $item['name'], 'image_url' => $p['images'][0] ?? $item['image_url'] ?? null]);
            }
            $part = $parts->get((int) $item['part_id']);
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $maxQuantity = $part ? max(0, (int) $part->quantity) : 0;

            // Preserve invalid legacy FR quantities so checkout rejects them rather than silently accepting one unit.
            if ($maxQuantity > 0 && ! StorefrontContext::isFrench(request())) {
                $quantity = min($quantity, $maxQuantity);
            }

            $unitPrice = (float) ($item['unit_price'] ?? 0);

            return array_merge($item, [
                'quantity' => $quantity,
                'current_quantity' => $maxQuantity,
                'is_available' => $part !== null && $this->isAvailable($part),
                'current_part' => $part,
                'line_total' => round($unitPrice * $quantity, 2),
            ]);
        })->filter(fn ($item): bool => $item !== null && ((int) ($item['part_id'] ?? 0) > 0 || ($item['source_type'] ?? '') === 'jarek_gearbox'))->values();
    }

    public function count(): int
    {
        return collect(session(self::SESSION_KEY, []))->filter(fn ($item) => StorefrontContext::isFrench(request()) || ($item['source_type'] ?? '') !== 'jarek_gearbox')->sum(fn (array $item): int => max(0, (int) ($item['quantity'] ?? 0)));
    }

    public function subtotal(): float
    {
        return round($this->items()->sum('line_total'), 2);
    }

    public function add(Part $part, int $quantity = 1): array
    {
        if (StorefrontContext::isFrench(request())
            && ($quantity !== 1 || session(self::SESSION_KEY, []) !== [])) {
            return ['status' => 'warning', 'message' => __('storefront.fr_single_item_only')];
        }

        $freshPart = $this->visiblePart((int) $part->id);

        if (! $freshPart) {
            return ['status' => 'error', 'message' => StorefrontContext::isFrench(request()) ? 'Cet article ne peut pas être ajouté au panier.' : 'Nie można dodać produktu do koszyka.'];
        }

        $quantity = max(1, $quantity);
        $items = session(self::SESSION_KEY, []);
        $key = (string) $freshPart->id;
        $currentQuantity = (int) ($items[$key]['quantity'] ?? 0);
        $targetQuantity = $currentQuantity + $quantity;
        $maxQuantity = max(0, (int) $freshPart->quantity);
        $finalQuantity = min($targetQuantity, $maxQuantity);

        $items[$key] = $this->snapshot($freshPart, $finalQuantity, $items[$key]['added_at'] ?? now()->toIso8601String());
        session([self::SESSION_KEY => $items]);

        if ($finalQuantity < $targetQuantity) {
            return ['status' => 'warning', 'message' => StorefrontContext::isFrench(request()) ? 'La quantité maximale disponible de cet article est déjà dans le panier.' : 'Produkt jest już w koszyku w maksymalnej dostępnej ilości.'];
        }

        return ['status' => 'success', 'message' => StorefrontContext::isFrench(request()) ? 'Article ajouté au panier.' : 'Dodano produkt do koszyka.'];
    }

    public function addJarek(int $id, int $quantity = 1): array
    {
        abort_unless(StorefrontContext::isFrench(request()), 404);
        if ($quantity !== 1 || session(self::SESSION_KEY, []) !== []) {
            return ['status' => 'warning', 'message' => __('storefront.fr_single_item_only')];
        }
        $product = JarekFrProduct::query()->active()->with('gearbox.translations')->find($id);
        $p = $product ? app(JarekFrPresentation::class)->forProduct($product) : null;
        if (! $p || ! $p['can_buy']) {
            return ['status' => 'warning', 'message' => __('storefront.fr_purchase_unavailable')];
        }
        session([self::SESSION_KEY => ['jarek:'.$id => [
            'source_type' => 'jarek_gearbox', 'jarek_fr_product_id' => $id, 'part_id' => null, 'quantity' => 1,
            'unit_price' => number_format($p['cents'] / 100, 2, '.', ''), 'currency' => 'EUR', 'name' => $p['title'],
            'sku' => $p['sku'], 'image_url' => $p['images'][0] ?? null, 'product_url' => $p['url'], 'added_at' => now()->toIso8601String(),
        ]]]);

        return ['status' => 'success', 'message' => 'Article ajouté au panier.'];
    }

    public function updateJarek(int $id, int $quantity): array
    {
        abort_unless(StorefrontContext::isFrench(request()), 404);
        if ($quantity !== 1 || ! isset(session(self::SESSION_KEY, [])['jarek:'.$id]) || count(session(self::SESSION_KEY, [])) !== 1) {
            return ['status' => 'warning', 'message' => __('storefront.fr_single_item_only')];
        }

        return ['status' => 'success', 'message' => 'Panier mis à jour.'];
    }

    public function removeJarek(int $id): void
    {
        abort_unless(StorefrontContext::isFrench(request()), 404);
        $items = session(self::SESSION_KEY, []);
        unset($items['jarek:'.$id]);
        session([self::SESSION_KEY => $items]);
    }

    public function update(int $partId, int $quantity): array
    {
        $items = session(self::SESSION_KEY, []);
        $key = (string) $partId;

        if (StorefrontContext::isFrench(request()) && ($quantity !== 1 || count($items) > 1)) {
            return ['status' => 'warning', 'message' => __('storefront.fr_single_item_only')];
        }

        if (! isset($items[$key])) {
            return ['status' => 'warning', 'message' => StorefrontContext::isFrench(request()) ? 'Cet article ne se trouve pas dans le panier.' : 'Produkt nie znajduje się w koszyku.'];
        }

        $freshPart = $this->visiblePart($partId);

        if (! $freshPart) {
            unset($items[$key]);
            session([self::SESSION_KEY => $items]);

            return ['status' => 'warning', 'message' => StorefrontContext::isFrench(request()) ? 'Cet article n’est plus disponible et a été retiré du panier.' : 'Produkt nie jest już dostępny i został usunięty z koszyka.'];
        }

        $quantity = min(max(1, $quantity), max(1, (int) $freshPart->quantity));
        $items[$key] = $this->snapshot($freshPart, $quantity, $items[$key]['added_at'] ?? now()->toIso8601String());
        session([self::SESSION_KEY => $items]);

        return ['status' => 'success', 'message' => StorefrontContext::isFrench(request()) ? 'Panier mis à jour.' : 'Zaktualizowano koszyk.'];
    }

    public function remove(int $partId): void
    {
        $items = session(self::SESSION_KEY, []);
        unset($items[(string) $partId]);
        session([self::SESSION_KEY => $items]);
    }

    public function clear(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    private function visiblePart(int $partId): ?Part
    {
        return Part::query()->with('images')->storefrontVisible()->whereKey($partId)->first();
    }

    public function isAvailable(Part $part): bool
    {
        return $part->isAvailableForLocalSale();
    }

    private function snapshot(Part $part, int $quantity, string $addedAt): array
    {
        return [
            'part_id' => (int) $part->id,
            'quantity' => $quantity,
            'unit_price' => number_format((float) $part->price, 2, '.', ''),
            'currency' => $part->currency ?: 'PLN',
            'name' => $part->name,
            'sku' => $part->part_number ?: $part->sku,
            'slug' => $part->slug,
            'image_url' => $part->listingImageUrl(),
            'added_at' => $addedAt,
        ];
    }
}
