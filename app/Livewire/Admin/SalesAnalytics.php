<?php

namespace App\Livewire\Admin;

use App\Services\Admin\SalesAnalyticsService;
use Livewire\Attributes\Url;
use Livewire\Component;
use NumberFormatter;

class SalesAnalytics extends Component
{
    #[Url(as: 'sales_range', history: true)]
    public string $range = 'last_30_days';

    public function mount(): void
    {
        $this->range = $this->normalizeRange($this->range);
    }

    public function setRange(string $range): void
    {
        $this->range = $this->normalizeRange($range);
    }

    /**
     * @return array<string, string>
     */
    public function salesRangeTabs(): array
    {
        return app(SalesAnalyticsService::class)->ranges();
    }

    /**
     * @return array<string, mixed>
     */
    public function salesAnalytics(): array
    {
        return app(SalesAnalyticsService::class)->dashboardData($this->normalizeRange($this->range));
    }

    public function formatPln(float|int $amount): string
    {
        return $this->formatCurrency((float) $amount, 'PLN', 'pl_PL');
    }

    public function formatEur(float|int $amount): string
    {
        return $this->formatCurrency((float) $amount, 'EUR', 'pl_PL');
    }

    public function render()
    {
        return view('livewire.admin.sales-analytics');
    }

    private function normalizeRange(string $range): string
    {
        return array_key_exists($range, $this->salesRangeTabs()) ? $range : 'last_30_days';
    }

    private function formatCurrency(float $amount, string $currency, string $locale): string
    {
        if (class_exists(NumberFormatter::class)) {
            $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);

            return $formatter->formatCurrency($amount, $currency) ?: number_format($amount, 2, ',', ' ') . ' ' . $currency;
        }

        return number_format($amount, 2, ',', ' ') . ' ' . $currency;
    }
}
