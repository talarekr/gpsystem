<?php

namespace App\Livewire\Admin;

use App\Filament\Resources\OrderResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ShopEvent;
use App\Support\OrderItemThumbnailDiagnostics;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;

class ShopEvents extends Component
{
    #[Url(as: 'shop_event_tab', history: true)]
    public string $tab = 'all';

    public function mount(): void
    {
        $this->tab = $this->normalizeTab($this->tab);
    }

    public function setTab(string $tab): void
    {
        $this->tab = $this->normalizeTab($tab);
    }

    /**
     * @return array<string, string>
     */
    public function tabs(): array
    {
        return [
            'all' => 'Wszystkie',
            'requires_action' => 'Wymaga reakcji',
            'messages' => 'Wiadomości',
            'returns_complaints' => 'Zwroty/Reklamacje',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function viewData(): array
    {
        $activeTab = $this->normalizeTab($this->tab);
        $counts = $this->counts();
        $events = $this->events($activeTab);

        return [
            'activeTab' => $activeTab,
            'tabs' => $this->tabs(),
            'counts' => $counts,
            'events' => $events,
            'showMore' => in_array($activeTab, ['all', 'requires_action'], true) && (($counts[$activeTab] ?? 0) > 6),
            'moreUrl' => OrderResource::getUrl('index', ['status' => 'new']),
        ];
    }

    public function render()
    {
        return view('livewire.admin.shop-events', $this->viewData());
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        try {
            return collect(array_keys($this->tabs()))
                ->mapWithKeys(fn (string $tab): array => [$tab => $this->countForTab($tab)])
                ->all();
        } catch (QueryException) {
            return array_fill_keys(array_keys($this->tabs()), 0);
        }
    }

    /**
     * @return Collection<int, array<string, string>>
     */
    private function events(string $tab): Collection
    {
        if (in_array($tab, ['all', 'requires_action'], true)) {
            return $this->orderEvents($tab);
        }

        if (! Schema::hasTable('shop_events')) {
            return collect();
        }

        return $this->shopEventQuery($tab)
            ->orderByRaw('COALESCE(occurred_at, created_at) DESC')
            ->limit(6)
            ->get()
            ->map(fn (ShopEvent $event): array => $this->shopEventToItem($event))
            ->values();
    }

    private function countForTab(string $tab): int
    {
        if ($tab === 'all') {
            return Schema::hasTable('orders') ? $this->newOrdersQuery()->count() : 0;
        }

        if ($tab === 'requires_action') {
            return Schema::hasTable('orders') ? $this->delayedNewOrdersQuery()->count() : 0;
        }

        return Schema::hasTable('shop_events') ? (int) $this->shopEventQuery($tab)->count() : 0;
    }

    private function shopEventQuery(string $tab): Builder
    {
        $query = ShopEvent::query()->whereIn('event_type', $this->supportShopEventTypes());

        match ($tab) {
            'messages' => $query->whereIn('event_type', $this->messageShopEventTypes()),
            'returns_complaints' => $query->whereIn('event_type', $this->returnComplaintShopEventTypes()),
            default => null,
        };

        return $query;
    }

    /**
     * @return Collection<int, array<string, string>>
     */
    private function orderEvents(string $tab): Collection
    {
        if (! Schema::hasTable('orders')) {
            return collect();
        }

        $query = $tab === 'requires_action'
            ? $this->delayedNewOrdersQuery()
            : $this->newOrdersQuery();

        return $query
            ->with(['items.part.images', 'items.part.storageLocation', 'items.marketplaceListing.part.images', 'items.marketplaceListing.part.storageLocation'])
            ->orderByRaw('COALESCE(ordered_at, created_at) DESC')
            ->limit(6)
            ->get()
            ->map(fn (Order $order): array => $this->orderToItem($order))
            ->values();
    }

    private function newOrdersQuery(): Builder
    {
        return Order::query()->where('status', 'new');
    }

    private function delayedNewOrdersQuery(): Builder
    {
        return $this->newOrdersQuery()
            ->whereRaw('COALESCE(ordered_at, created_at) <= ?', [now()->subDay()]);
    }

    /**
     * @return array<string, string>
     */
    private function orderToItem(Order $order): array
    {
        $date = $order->ordered_at ?: $order->created_at;
        $presentation = $this->primaryItemPresentation($order);

        return [
            'time' => $date?->format('Y-m-d H:i') ?? '—',
            'channel' => $this->normalizeOrderChannel($order),
            'title' => $presentation['title'],
            'storage' => $presentation['storage'],
            'extra' => $presentation['extra'],
            'amount' => OrderResource::formatOrderTotal($order),
            'url' => OrderResource::getUrl('view', ['record' => $order]),
            'severity' => 'warning',
        ];
    }

    /**
     * @return array{title: string, storage: string, extra: string}
     */
    private function primaryItemPresentation(Order $order): array
    {
        $items = $order->items instanceof Collection ? $order->items : collect();
        $primaryItem = $items->sortByDesc(fn (OrderItem $item): float => (float) ($item->line_total ?? $item->unit_price ?? 0))->first();

        if (! $primaryItem instanceof OrderItem) {
            return ['title' => OrderResource::displayOrderNumber($order), 'storage' => 'Brak lokalizacji', 'extra' => ''];
        }

        $resolved = OrderItemThumbnailDiagnostics::resolve($order, $primaryItem);
        $extraCount = max($items->count() - 1, 0);

        return [
            'title' => (string) ($resolved['display_name'] ?? $primaryItem->product_name ?: 'Brak danych'),
            'storage' => (string) ($resolved['storage_location'] ?? 'Brak lokalizacji'),
            'extra' => $extraCount > 0 ? '+'.$extraCount.' więcej' : '',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function shopEventToItem(ShopEvent $event): array
    {
        $date = $event->occurred_at ?: $event->created_at;

        return [
            'time' => $date?->format('Y-m-d H:i') ?? '—',
            'channel' => $event->sourceLabel(),
            'title' => $event->external_reference ?: $event->title,
            'storage' => 'Brak lokalizacji',
            'extra' => '',
            'amount' => '—',
            'url' => $event->dashboardUrl() ?: '',
            'severity' => $event->severity ?: 'info',
        ];
    }

    private function normalizeOrderChannel(Order $order): string
    {
        $source = $this->firstFilled([
            $order->marketplace,
            data_get($order->meta, 'source'),
            data_get($order->meta, 'channel'),
            $order->items->first(fn ($item): bool => filled($item->marketplace))?->marketplace,
        ]);

        return match (true) {
            Str::contains(Str::lower($source), 'allegro') => 'Allegro',
            Str::contains(Str::lower($source), 'ovoko') => 'Ovoko',
            Str::contains(Str::lower($source), 'ebay') => 'eBay',
            default => 'Sklep',
        };
    }

    /**
     * @param array<int, mixed> $values
     */
    private function firstFilled(array $values): string
    {
        foreach ($values as $value) {
            $value = trim((string) $value);

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function normalizeTab(string $tab): string
    {
        return array_key_exists($tab, $this->tabs()) ? $tab : 'all';
    }

    /** @return array<int, string> */
    private function supportShopEventTypes(): array
    {
        return array_merge($this->messageShopEventTypes(), $this->returnComplaintShopEventTypes());
    }

    /** @return array<int, string> */
    private function messageShopEventTypes(): array
    {
        return ['customer_message', 'product_question'];
    }

    /** @return array<int, string> */
    private function returnComplaintShopEventTypes(): array
    {
        return ['return', 'complaint'];
    }
}
