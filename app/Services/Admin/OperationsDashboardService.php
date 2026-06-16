<?php

namespace App\Services\Admin;

use App\Filament\Pages\Orders;
use App\Models\ShopEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

class OperationsDashboardService
{
    public function unhandledOrdersCount(): int
    {
        return $this->unhandledShopEventsCount(['order', 'payment']);
    }

    public function unhandledReturnsCount(): int
    {
        return $this->unhandledShopEventsCount(['return', 'complaint']);
    }

    public function ordersUrl(): string
    {
        return class_exists(Orders::class) ? Orders::getUrl() : '#';
    }

    public function returnsUrl(): string
    {
        // TODO: Podłączyć do docelowej strony/resource zwrotów po wdrożeniu modułu zwrotów.
        return '#';
    }

    /**
     * @param array<int, string> $eventTypes
     */
    private function unhandledShopEventsCount(array $eventTypes): int
    {
        // TODO: Po wdrożeniu docelowych modeli zamówień/zwrotów przepiąć liczniki z ShopEvent na właściwe tabele operacyjne.
        if (! class_exists(ShopEvent::class) || ! Schema::hasTable('shop_events')) {
            return 0;
        }

        try {
            return (int) ShopEvent::query()
                ->where('requires_action', true)
                ->whereIn('event_type', $eventTypes)
                ->count();
        } catch (QueryException) {
            return 0;
        }
    }
}
