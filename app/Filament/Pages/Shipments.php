<?php

namespace App\Filament\Pages;

use App\Models\Order;
use App\Models\Shipment;
use App\Services\Shipments\ShipmentLabelService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class Shipments extends Page
{
    protected static ?string $navigationGroup = 'Przesyłki';
    protected static ?string $navigationIcon = null;
    protected static ?string $navigationLabel = 'Przesyłki';
    protected static ?string $title = 'Przesyłki';
    protected static ?int $navigationSort = 70;
    protected static string $view = 'filament.pages.shipments';

    public ?array $preview = null;

    public function generateLabel(string $carrier, ?int $shipmentId = null, bool $confirm = false): void
    {
        $shipment = $shipmentId ? Shipment::query()->with('order')->find($shipmentId) : null;
        $order = $shipment?->order ?: Order::query()->latest('id')->first();
        $service = app(ShipmentLabelService::class);
        $this->preview = $confirm ? $service->confirm($carrier, $order, $shipment) : $service->preview($carrier, $order, $shipment);

        Notification::make()
            ->title($confirm ? 'Próba wygenerowania etykiety zakończona' : 'Dry-run etykiety zakończony')
            ->body(($this->preview['validation']['ok'] ?? false) ? 'Dane kompletne.' : 'Brakuje danych: '.implode(', ', $this->preview['validation']['missing'] ?? []))
            ->send();
    }

    public function shipments()
    {
        return Shipment::query()->with('order')->latest('id')->limit(50)->get();
    }

    public function ordersWithoutShipment()
    {
        return Order::query()->doesntHave('shipments')->latest('id')->limit(10)->get();
    }
}
