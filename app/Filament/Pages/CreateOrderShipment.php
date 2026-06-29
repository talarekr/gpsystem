<?php

namespace App\Filament\Pages;

use App\Models\Order;
use App\Models\Shipment;
use App\Services\Marketplace\Api\EbayFulfillmentService;
use App\Services\Shipments\DhlShipmentService;
use Filament\Notifications\Notification;

class CreateOrderShipment extends CreateShipment
{
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $slug = 'orders/{order}/shipments/create';
    protected static ?string $title = 'Dodaj przesyłkę DHL do zamówienia';

    public Order $order;

    public function mount(DhlShipmentService $dhl, Order $order): void
    {
        abort_unless(str_starts_with(strtolower((string) $order->marketplace), 'ebay'), 404);
        $this->order = $order;
        $this->dhlForm = $dhl->defaults($order);
        $this->showDhlForm = true;
    }

    protected function afterDhlShipmentCreated(Shipment $shipment): void
    {
        $result = ['status' => 'skipped'];

        try {
            $result = app(EbayFulfillmentService::class)->sendTracking($this->order, $shipment);
            Notification::make()->title('Tracking wysłany do eBay')->body('Numer: '.$shipment->tracking_number)->success()->send();
        } catch (\Throwable $exception) {
            $result = ['ok' => false, 'error' => $exception->getMessage(), 'failed_at' => now()->toISOString()];
            Notification::make()->title('DHL utworzony, ale eBay odrzucił tracking')->body($exception->getMessage())->danger()->persistent()->send();
        }

        $shipment->forceFill(['response_payload' => array_merge((array) $shipment->response_payload, ['ebay_fulfillment' => $result])])->save();
        $this->redirect(\App\Filament\Resources\OrderResource::getUrl('view', ['record' => $this->order]));
    }
}
