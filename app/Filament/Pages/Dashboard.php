<?php

namespace App\Filament\Pages;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\PartResource;
use App\Models\Order;
use App\Models\ShopEvent;
use App\Services\Admin\SalesAnalyticsService;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use NumberFormatter;

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
    public function salesRangeTabs(): array
    {
        return app(SalesAnalyticsService::class)->ranges();
    }

    public function activeSalesRange(): string
    {
        $range = request()->query('sales_range', 'last_30_days');

        return array_key_exists($range, $this->salesRangeTabs()) ? $range : 'last_30_days';
    }

    /**
     * @return array<string, mixed>
     */
    public function salesAnalytics(): array
    {
        return app(SalesAnalyticsService::class)->dashboardData($this->activeSalesRange());
    }

    public function formatPln(float|int $amount): string
    {
        return $this->formatCurrency((float) $amount, 'PLN', 'pl_PL');
    }

    public function formatEur(float|int $amount): string
    {
        return $this->formatCurrency((float) $amount, 'EUR', 'pl_PL');
    }

    private function formatCurrency(float $amount, string $currency, string $locale): string
    {
        if (class_exists(NumberFormatter::class)) {
            $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);

            return $formatter->formatCurrency($amount, $currency) ?: number_format($amount, 2, ',', ' ') . ' ' . $currency;
        }

        return number_format($amount, 2, ',', ' ') . ' ' . $currency;
    }

    /**
     * @return array<string, string>
     */
    public function shopEventTabs(): array
    {
        return [
            'all' => 'Wszystkie',
            'requires_action' => 'Wymaga reakcji',
            'orders' => 'Zamówienia',
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
     * @return Collection<int, ShopEvent>
     */
    public function shopEvents(): Collection
    {
        $events = Schema::hasTable('shop_events')
            ? $this->shopEventQuery($this->activeShopEventTab())
                ->orderByRaw('COALESCE(occurred_at, created_at) DESC')
                ->limit(15)
                ->get()
            : new Collection();

        return $events
            ->merge($this->orderShopEvents($this->activeShopEventTab()))
            ->sortByDesc(fn (ShopEvent $event): int => ($event->occurred_at ?: $event->created_at)?->getTimestamp() ?? 0)
            ->take(15)
            ->values();
    }

    private function shopEventCount(string $tab): int
    {
        $count = Schema::hasTable('shop_events') ? (int) $this->shopEventQuery($tab)->count() : 0;

        return $count + $this->orderShopEventsCount($tab);
    }

    private function shopEventQuery(string $tab): Builder
    {
        $query = ShopEvent::query()->whereIn('event_type', $this->supportShopEventTypes());

        match ($tab) {
            'requires_action' => $query->where('requires_action', true),
            'orders' => $query->whereIn('event_type', $this->orderShopEventTypes()),
            'messages' => $query->whereIn('event_type', $this->messageShopEventTypes()),
            'returns_complaints' => $query->whereIn('event_type', $this->returnComplaintShopEventTypes()),
            default => null,
        };

        return $query;
    }


    private function orderShopEventsCount(string $tab): int
    {
        if (! in_array($tab, ['all', 'requires_action', 'orders'], true) || ! Schema::hasTable('orders')) {
            return 0;
        }

        return (int) Order::query()->where('status', 'new')->count();
    }

    /**
     * @return Collection<int, ShopEvent>
     */
    private function orderShopEvents(string $tab): Collection
    {
        if (! in_array($tab, ['all', 'requires_action', 'orders'], true) || ! Schema::hasTable('orders')) {
            return new Collection();
        }

        return Order::query()
            ->where('status', 'new')
            ->latest()
            ->limit(15)
            ->get()
            ->map(fn (Order $order): ShopEvent => $this->orderToShopEvent($order));
    }

    private function orderToShopEvent(Order $order): ShopEvent
    {
        $event = new ShopEvent([
            'source' => 'storefront',
            'event_type' => 'order',
            'title' => 'Nowe zamówienie sklep ' . $order->order_number,
            'description' => trim(sprintf('Status: %s. Klient: %s.', $order->status, $order->customer_name ?: '—')),
            'occurred_at' => $order->created_at,
            'is_read' => false,
            'requires_action' => true,
            'severity' => 'warning',
            'customer_name' => $order->customer_name,
            'external_reference' => $order->order_number,
            'url' => OrderResource::getUrl('view', ['record' => $order]),
            'payload' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
            ],
        ]);
        $event->created_at = $order->created_at;
        $event->updated_at = $order->updated_at;

        return $event;
    }

    /**
     * @return array<int, string>
     */
    private function supportShopEventTypes(): array
    {
        return array_merge(
            $this->orderShopEventTypes(),
            $this->messageShopEventTypes(),
            $this->returnComplaintShopEventTypes(),
        );
    }

    /**
     * @return array<int, string>
     */
    private function orderShopEventTypes(): array
    {
        return ['order', 'payment'];
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
