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
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class SoldParts extends Page
{
    use WithPagination;

    protected static string $resource = PartResource::class;

    protected static ?string $title = 'Sprzedane części';

    protected static string $view = 'filament.resources.parts.pages.sold-parts';

    #[Url(as: 'search')]
    public string $search = '';

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

    public function updating(string $property): void
    {
        if ($property !== 'page') {
            $this->resetPage();
        }
    }

    public function getSoldPartsProperty(): LengthAwarePaginator
    {
        $rows = $this->orderItemRows()
            ->merge($this->localSaleRows())
            ->when(filled($this->search), fn (Collection $rows): Collection => $this->filterRows($rows, trim($this->search)))
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
                    'search_values' => $this->partSearchValues($part, [
                        $item->product_name,
                        $item->sku,
                        $item->part_number,
                        $item->marketplace,
                        $item->marketplace_order_id,
                        $order?->marketplace,
                        $order ? \App\Filament\Resources\OrderResource::displayOrderNumber($order) : null,
                    ]),
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
                    'search_values' => $this->partSearchValues($sale->part, [
                        $snapshot['id'] ?? null,
                        $snapshot['name'] ?? null,
                        $snapshot['sku'] ?? null,
                        $snapshot['part_number'] ?? null,
                        $snapshot['oem_number'] ?? null,
                        $snapshot['manufacturer_code'] ?? null,
                        'sprzedaż lokalna',
                        'local sale',
                        'lokalna',
                        'Lokalna #'.$sale->id,
                    ]),
                ];
            });
    }

    private function filterRows(Collection $rows, string $search): Collection
    {
        $tokens = collect(preg_split('/\s+/', mb_strtolower($search)) ?: [])
            ->filter()
            ->values();

        if ($tokens->isEmpty()) {
            return $rows;
        }

        return $rows->filter(function (array $row) use ($tokens): bool {
            $haystack = mb_strtolower(implode(' ', array_filter($row['search_values'] ?? [])));

            return $tokens->every(fn (string $token): bool => str_contains($haystack, $token));
        });
    }

    private function partSearchValues($part, array $fallbackValues = []): array
    {
        return array_values(array_filter(array_merge([
            $part?->id,
            $part?->name,
            $part?->sku,
            $part?->part_number,
            $part?->oem_number,
            $part?->manufacturer_code,
        ], $fallbackValues), fn ($value): bool => filled($value)));
    }
}
