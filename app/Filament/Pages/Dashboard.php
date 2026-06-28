<?php

namespace App\Filament\Pages;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\PartResource;
use App\Models\Order;
use App\Models\ShopEvent;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Dashboard extends BaseDashboard
{
    protected static string $view = 'filament.pages.dashboard';
    protected static ?string $navigationIcon = 'heroicon-o-home';
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $navigationLabel = 'Strona główna';
    protected static ?string $title = '';
    protected static ?int $navigationSort = 10;

    public function getHeading(): string|Htmlable
    {
        return '';
    }


    public function addPartUrl(): string
    {
        return PartResource::getUrl('create');
    }

    public function ordersUrl(): string
    {
        return OrderResource::getUrl('index');
    }

    /**
     * @return array<string, string>
     */
    public function shopEventTabs(): array
    {
        return [
            'all' => 'Wszystkie',
            'requires_action' => 'Wymaga reakcji',
            'messages' => 'Wiadomości',
            'returns_complaints' => 'Zwroty/Reklamacje',
        ];
    }

    /**
     * @return array<string, int>
     */
    public function shopEventTabCounts(): array
    {
        try {
            return collect(array_keys($this->shopEventTabs()))
                ->mapWithKeys(fn (string $tab): array => [$tab => $this->shopEventCount($tab)])
                ->all();
        } catch (QueryException) {
            return array_fill_keys(array_keys($this->shopEventTabs()), 0);
        }
    }

    public function activeShopEventTab(): string
    {
        $tab = request()->query('shop_event_tab', 'all');

        return array_key_exists($tab, $this->shopEventTabs()) ? $tab : 'all';
    }

    /**
     * @return Collection<int, array<string, string>>
     */
    public function shopEvents(): Collection
    {
        $tab = $this->activeShopEventTab();

        if (in_array($tab, ['all', 'requires_action'], true)) {
            return $this->orderShopEvents($tab);
        }

        if (! Schema::hasTable('shop_events')) {
            return collect();
        }

        return $this->shopEventQuery($tab)
            ->orderByRaw('COALESCE(occurred_at, created_at) DESC')
            ->get()
            ->map(fn (ShopEvent $event): array => $this->shopEventToDashboardItem($event))
            ->values();
    }

    private function shopEventCount(string $tab): int
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
    private function orderShopEvents(string $tab): Collection
    {
        if (! Schema::hasTable('orders')) {
            return collect();
        }

        $query = $tab === 'requires_action'
            ? $this->delayedNewOrdersQuery()
            : $this->newOrdersQuery();

        return $query
            ->with('items')
            ->orderByRaw('COALESCE(ordered_at, created_at) DESC')
            ->get()
            ->map(fn (Order $order): array => $this->orderToDashboardItem($order))
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
    private function orderToDashboardItem(Order $order): array
    {
        $date = $order->ordered_at ?: $order->created_at;

        return [
            'time' => $date?->format('Y-m-d H:i') ?? '—',
            'channel' => $this->normalizeOrderChannel($order),
            'reference' => OrderResource::displayOrderNumber($order),
            'amount' => OrderResource::formatOrderTotal($order),
            'url' => OrderResource::getUrl('view', ['record' => $order]),
            'severity' => 'warning',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function shopEventToDashboardItem(ShopEvent $event): array
    {
        $date = $event->occurred_at ?: $event->created_at;

        return [
            'time' => $date?->format('Y-m-d H:i') ?? '—',
            'channel' => $event->sourceLabel(),
            'reference' => $event->external_reference ?: $event->title,
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

    /**
     * @return array<int, string>
     */
    private function supportShopEventTypes(): array
    {
        return array_merge(
            $this->messageShopEventTypes(),
            $this->returnComplaintShopEventTypes(),
        );
    }

    /**
     * @return array<int, string>
     */
    private function messageShopEventTypes(): array
    {
        return ['customer_message', 'product_question'];
    }

    /**
     * @return array<int, string>
     */
    private function returnComplaintShopEventTypes(): array
    {
        return ['return', 'complaint'];
    }
}
