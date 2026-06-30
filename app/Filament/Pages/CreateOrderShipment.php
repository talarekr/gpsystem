<?php

namespace App\Filament\Pages;

use App\Models\Order;
use App\Models\Shipment;
use App\Services\Shipments\DhlShipmentService;
use Filament\Notifications\Notification;

class CreateOrderShipment extends CreateShipment
{
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $slug = 'orders/{order}/shipments/create';
    protected static ?string $title = 'Dodaj przesyłkę DHL do zamówienia';

    public Order $order;

    public function mount(DhlShipmentService $dhl): void
    {
        $order = request()->route('order');

        if (! $order instanceof Order) {
            $order = Order::query()->findOrFail($order);
        }

        abort_unless(in_array(strtolower((string) $order->marketplace), ['allegro', 'ebay', 'ebay_de', 'ebay_fr'], true), 404);

        $this->order = $order;
        $this->dhlForm = $dhl->defaults($order);
        $this->showDhlForm = true;
    }

    protected function afterDhlShipmentCreated(Shipment $shipment): void
    {
        $shipment->forceFill([
            'response_payload' => array_merge((array) $shipment->response_payload, [
                'marketplace_fulfillment' => [
                    'status' => 'not_sent',
                    'reason' => 'Fulfillment write is manual-only via the hidden admin fulfillment-sync endpoint.',
                ],
            ]),
        ])->save();

        Notification::make()
            ->title('Utworzono przesyłkę DHL')
            ->body('Tracking nie został automatycznie wysłany do marketplace. Użyj endpointu fulfillment dry-run/apply.')
            ->success()
            ->send();

        $this->redirect(\App\Filament\Resources\OrderResource::getUrl('view', ['record' => $this->order]));
    }
}
