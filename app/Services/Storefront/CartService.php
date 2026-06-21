<?php

namespace App\Services\Storefront;

use App\Models\Part;
use Illuminate\Support\Collection;

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

        return $items->map(function (array $item) use ($parts): array {
            $part = $parts->get((int) $item['part_id']);
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $maxQuantity = $part ? max(0, (int) $part->quantity) : 0;

            if ($maxQuantity > 0) {
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
        })->filter(fn (array $item): bool => (int) ($item['part_id'] ?? 0) > 0)->values();
    }

    public function count(): int
    {
        return collect(session(self::SESSION_KEY, []))->sum(fn (array $item): int => max(0, (int) ($item['quantity'] ?? 0)));
    }

    public function subtotal(): float
    {
        return round($this->items()->sum('line_total'), 2);
    }

    public function add(Part $part, int $quantity = 1): array
    {
        $freshPart = $this->visiblePart((int) $part->id);

        if (! $freshPart) {
            return ['status' => 'error', 'message' => 'Nie można dodać produktu do koszyka.'];
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
            return ['status' => 'warning', 'message' => 'Produkt jest już w koszyku w maksymalnej dostępnej ilości.'];
        }

        return ['status' => 'success', 'message' => 'Dodano produkt do koszyka.'];
    }

    public function update(int $partId, int $quantity): array
    {
        $items = session(self::SESSION_KEY, []);
        $key = (string) $partId;

        if (! isset($items[$key])) {
            return ['status' => 'warning', 'message' => 'Produkt nie znajduje się w koszyku.'];
        }

        $freshPart = $this->visiblePart($partId);

        if (! $freshPart) {
            unset($items[$key]);
            session([self::SESSION_KEY => $items]);

            return ['status' => 'warning', 'message' => 'Produkt nie jest już dostępny i został usunięty z koszyka.'];
        }

        $quantity = min(max(1, $quantity), max(1, (int) $freshPart->quantity));
        $items[$key] = $this->snapshot($freshPart, $quantity, $items[$key]['added_at'] ?? now()->toIso8601String());
        session([self::SESSION_KEY => $items]);

        return ['status' => 'success', 'message' => 'Zaktualizowano koszyk.'];
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

    private function isAvailable(Part $part): bool
    {
        return ! in_array($part->status, ['sold', 'archived'], true) && (int) $part->quantity > 0;
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
