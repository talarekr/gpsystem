<?php

namespace App\Filament\Resources\PartResource\Pages;

use App\Filament\Resources\PartResource;
use App\Models\LocalSale;
use App\Models\OrderItem;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Pagination\LengthAwarePaginator as LaravelLengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\WithPagination;

class SoldParts extends Page
{
    use WithPagination;

    protected static string $resource = PartResource::class;

    protected static ?string $title = 'Sprzedane części';

    protected static string $view = 'filament.resources.parts.pages.sold-parts';

    public int $perPage = 20;

    public function getTitle(): string|Htmlable
    {
        return '';
    }

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    public function getMaxContentWidth(): MaxWidth|string|null
    {
        return MaxWidth::Full;
    }

    public function getSoldPartsProperty(): LengthAwarePaginator
    {
        $rows = $this->orderItemRows()
            ->merge($this->localSaleRows())
            ->sortByDesc(fn (array $row): int => $row['sold_at_sort'])
            ->values();

        $page = $this->getPage();
        $items = $rows->forPage($page, $this->perPage)->values();

        return new LaravelLengthAwarePaginator(
            $items,
            $rows->count(),
            $this->perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    private function orderItemRows(): Collection
    {
        return OrderItem::query()
            ->with(['order', 'part.images', 'part.storageLocation'])
            ->whereHas('order', fn ($query) => $query->where('status', '!=', 'cancelled'))
            ->latest('id')
            ->limit(500)
            ->get()
            ->map(function (OrderItem $item): array {
                $order = $item->order;
                $soldAt = $order?->ordered_at ?: $item->created_at;

                return [
                    'type' => 'order_item',
                    'part' => $item->part,
                    'name' => $item->part?->name ?: $item->product_name,
                    'sku' => $item->part?->sku ?: $item->sku,
                    'oem' => $item->part?->part_number ?: $item->part_number,
                    'source' => $item->marketplace ?: $order?->marketplace ?: 'sklep',
                    'reference' => $order ? \App\Filament\Resources\OrderResource::displayOrderNumber($order) : ($item->marketplace_order_id ?: '—'),
                    'sold_at' => $soldAt,
                    'sold_at_sort' => $soldAt?->getTimestamp() ?? 0,
                    'price' => $item->line_total ?? $item->unit_price,
                    'currency' => $item->currency ?: $order?->currency ?: 'PLN',
                    'quantity' => $item->quantity,
                    'status' => $order?->status ?: '—',
                    'part_url' => $item->part ? PartResource::getUrl('view', ['record' => $item->part]) : null,
                    'order_url' => $order ? \App\Filament\Resources\OrderResource::getUrl('view', ['record' => $order]) : null,
                ];
            });
    }

    private function localSaleRows(): Collection
    {
        return LocalSale::query()
            ->with(['part.images', 'part.storageLocation'])
            ->latest('sold_at')
            ->latest('id')
            ->limit(500)
            ->get()
            ->map(function (LocalSale $sale): array {
                $snapshot = $sale->part_snapshot ?: [];
                $soldAt = $sale->sold_at ?: $sale->created_at;

                return [
                    'type' => 'local_sale',
                    'part' => $sale->part,
                    'name' => $sale->part?->name ?: ($snapshot['name'] ?? '—'),
                    'sku' => $sale->part?->sku ?: ($snapshot['sku'] ?? null),
                    'oem' => $sale->part?->part_number ?: ($snapshot['part_number'] ?? null),
                    'source' => 'sprzedaż lokalna',
                    'reference' => 'Lokalna #'.$sale->id,
                    'sold_at' => $soldAt,
                    'sold_at_sort' => $soldAt?->getTimestamp() ?? 0,
                    'price' => $sale->amount,
                    'currency' => $sale->currency ?: 'PLN',
                    'quantity' => $sale->quantity,
                    'status' => 'sprzedaż lokalna',
                    'part_url' => $sale->part ? PartResource::getUrl('view', ['record' => $sale->part]) : null,
                    'order_url' => null,
                ];
            });
    }
}
