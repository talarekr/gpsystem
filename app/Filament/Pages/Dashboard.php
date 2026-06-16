<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Orders;
use App\Filament\Resources\PartResource;
use App\Models\ShopEvent;
use App\Services\Admin\SalesAnalyticsService;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use NumberFormatter;

class Dashboard extends BaseDashboard
{
    protected static string $view = 'filament.pages.dashboard';
    protected static ?string $navigationIcon = 'heroicon-o-home';
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
        return class_exists(Orders::class) ? Orders::getUrl() : '#';
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
        $query = ShopEvent::query();

        match ($this->activeShopEventTab()) {
            'requires_action' => $query->where('requires_action', true),
            'orders' => $query->whereIn('event_type', ['order', 'payment']),
            'messages' => $query->whereIn('event_type', ['customer_message', 'product_question']),
            'returns_complaints' => $query->whereIn('event_type', ['return', 'complaint']),
            default => null,
        };

        // TODO: Techniczne zdarzenia typu import/API/stock sync będą później osobnym modułem Administrator / Dziennik techniczny.
        return $query
            ->orderByRaw('COALESCE(occurred_at, created_at) DESC')
            ->limit(15)
            ->get();
    }
}
