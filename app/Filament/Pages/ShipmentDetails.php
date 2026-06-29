<?php

namespace App\Filament\Pages;

use App\Models\Shipment;
use App\Services\Shipments\DhlShipmentService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Throwable;

class ShipmentDetails extends Page
{
    protected static ?string $navigationGroup = 'Przesyłki';
    protected static ?string $navigationIcon = null;
    protected static ?string $navigationLabel = null;
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $slug = 'shipments/{shipment}';
    protected static string $view = 'filament.pages.shipment-details';

    public Shipment $shipment;

    public ?array $tracking = null;

    public ?string $trackingError = null;

    public bool $trackingLoaded = false;

    public function mount(Shipment $shipment): void
    {
        $this->shipment = $shipment->loadMissing('order');
    }

    public function refreshTracking(DhlShipmentService $dhl): void
    {
        $this->trackingError = null;
        $this->trackingLoaded = true;

        if ($this->shipment->carrier !== 'dhl') {
            $this->tracking = null;
            $this->trackingError = 'Tracking DHL jest dostępny tylko dla przesyłek DHL.';
            return;
        }

        $shipmentId = $this->trackingNumber();
        if ($shipmentId === null) {
            $this->tracking = null;
            $this->trackingError = 'Brak numeru przesyłki potrzebnego do pobrania trackingu DHL.';
            return;
        }

        try {
            $this->tracking = $dhl->trackShipment($shipmentId);
            Notification::make()->title('Odświeżono tracking DHL')->success()->send();
        } catch (Throwable $exception) {
            $this->tracking = null;
            $this->trackingError = 'Nie udało się pobrać trackingu DHL: '.$exception->getMessage();
        }
    }

    public function getTrackingEventsProperty(): array
    {
        $events = (array) data_get($this->tracking, 'events', []);

        usort($events, function (array $left, array $right): int {
            return strcmp((string) ($right['timestamp'] ?? ''), (string) ($left['timestamp'] ?? ''));
        });

        return $events;
    }

    public function trackingNumber(): ?string
    {
        $number = trim((string) ($this->shipment->tracking_number ?: $this->shipment->carrier_shipment_id));

        return $number !== '' ? $number : null;
    }

    public function receiver(): array
    {
        $snapshot = (array) $this->shipment->receiver_snapshot;
        $order = $this->shipment->order;

        return [
            'name' => $snapshot['person_name'] ?? $snapshot['name'] ?? $order?->customer_name,
            'company' => (($snapshot['receiver_type'] ?? null) === 'company' ? ($snapshot['name'] ?? null) : null) ?: $order?->company_name,
            'street' => trim(implode(' ', array_filter([$snapshot['street'] ?? null, $snapshot['house_number'] ?? null]))),
            'apartment' => $snapshot['apartment_number'] ?? null,
            'postal_code' => $snapshot['postal_code'] ?? $order?->postal_code,
            'city' => $snapshot['city'] ?? $order?->city,
            'country' => $snapshot['country'] ?? $order?->country,
            'phone' => $snapshot['phone'] ?? $order?->phone,
            'email' => $snapshot['email'] ?? $order?->email,
        ];
    }

    public function formatDateTime(mixed $value): string
    {
        if (blank($value)) {
            return '—';
        }

        try {
            return Carbon::parse($value)->format('d.m.Y H:i');
        } catch (Throwable) {
            return (string) $value;
        }
    }
}
