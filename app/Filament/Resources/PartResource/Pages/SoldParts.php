<?php

namespace App\Filament\Resources\PartResource\Pages;

use App\Filament\Resources\PartResource;
use App\Models\LocalSale;
use App\Models\OrderItem;
use App\Support\OrderItemThumbnailDiagnostics;
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
            ->with(['order', 'part.images', 'part.storageLocation', 'marketplaceListing.part.images', 'marketplaceListing.part.storageLocation'])
            ->whereHas('order', fn ($query) => $query->where('status', '!=', 'cancelled'))
            ->latest('id')
            ->limit(500)
            ->get()
            ->map(function (OrderItem $item): array {
                $order = $item->order;
                $soldAt = $order?->ordered_at ?: $item->created_at;

                $thumbnail = OrderItemThumbnailDiagnostics::resolve($order, $item);
                $part = $thumbnail['part'] ?? null;

                return [
                    'type' => 'order_item',
                    'part' => $part,
                    'name' => $part?->name ?: $item->product_name,
                    'thumbnail_url' => $thumbnail['thumbnail_url'] ?? null,
                    'thumbnail_source' => $thumbnail['thumbnail_source'] ?? 'placeholder',
                    'storage_location' => $thumbnail['storage_location'] ?? 'Brak lokalizacji',
                    'source' => $item->marketplace ?: $order?->marketplace ?: 'sklep',
                    'reference' => $order ? \App\Filament\Resources\OrderResource::displayOrderNumber($order) : ($item->marketplace_order_id ?: '—'),
                    'sold_at' => $soldAt,
                    'sold_at_sort' => $soldAt?->getTimestamp() ?? 0,
                    'price' => $item->line_total ?? $item->unit_price,
                    'currency' => $item->currency ?: $order?->currency ?: 'PLN',
                    'part_id' => $part?->id,
                    'part_url' => $part ? PartResource::getUrl('view', ['record' => $part]) : null,
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
                    'source' => 'sprzedaż lokalna',
                    'reference' => 'Lokalna #'.$sale->id,
                    'sold_at' => $soldAt,
                    'sold_at_sort' => $soldAt?->getTimestamp() ?? 0,
                    'price' => $sale->amount,
                    'currency' => $sale->currency ?: 'PLN',
                    'part_id' => $sale->part?->id ?: $sale->part_id,
                    'thumbnail_url' => $sale->part?->adminTableImageUrl(),
                    'thumbnail_source' => $sale->part?->adminTableImageUrl() ? 'admin_parts_thumbnail' : 'placeholder',
                    'storage_location' => $sale->part?->storageLocation?->name ?: 'Brak lokalizacji',
                    'part_url' => $sale->part ? PartResource::getUrl('view', ['record' => $sale->part]) : null,
                    'order_url' => null,
                ];
            });
    }
}
