@php
    use App\Models\Shipment;

    $shipments = $this->shipments;
    $ordersWithoutShipment = $this->ordersWithoutShipment;
@endphp

<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section heading="Przesyłki" description="Istniejący moduł przesyłek rozszerzony o dry-run oraz generowanie etykiet DHL/DPD. Akcje nie zamawiają pickupów, nie wysyłają maili i nie zapisują nic do marketplace.">
            <div class="mb-4 grid gap-3 md:grid-cols-4">
                <input class="rounded-lg border-gray-300" type="search" wire:model.live.debounce.500ms="search" placeholder="ID, tracking, numer zamówienia...">
                <select class="rounded-lg border-gray-300" wire:model.live="carrier"><option value="">Wszyscy kurierzy</option>@foreach (Shipment::CARRIERS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                <select class="rounded-lg border-gray-300" wire:model.live="status"><option value="">Wszystkie statusy</option>@foreach (Shipment::STATUSES as $value)<option value="{{ $value }}">{{ $value }}</option>@endforeach</select>
                <select class="rounded-lg border-gray-300" wire:model.live="perPage">@foreach ($this->perPageOptions as $value => $label)<option value="{{ $value }}">{{ $label }} / str.</option>@endforeach</select>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead><tr class="text-left"><th class="p-2">ID</th><th class="p-2">Zamówienie</th><th class="p-2">Kurier</th><th class="p-2">Status</th><th class="p-2">Tracking</th><th class="p-2">Etykieta</th><th class="p-2">Akcje</th></tr></thead>
                    <tbody>
                    @forelse ($shipments as $shipment)
                        <tr class="border-t">
                            <td class="p-2">#{{ $shipment->id }}</td>
                            <td class="p-2">{{ $shipment->order?->order_number ?? '—' }}</td>
                            <td class="p-2">{{ strtoupper($shipment->carrier ?: '—') }}</td>
                            <td class="p-2">{{ $shipment->shipment_status }}</td>
                            <td class="p-2">{{ $shipment->tracking_number ?: '—' }}</td>
                            <td class="p-2">@if($shipment->label_path)<a class="text-primary-600 underline" href="{{ route('tools.download-shipment-label', $shipment) }}">Pobierz etykietę</a>@else — @endif</td>
                            <td class="p-2 space-x-2 whitespace-nowrap">
                                <button wire:click="generateLabel('dhl', {{ $shipment->id }}, false)" class="text-primary-600 underline">Podgląd DHL</button>
                                <button wire:click="generateLabel('dpd', {{ $shipment->id }}, false)" class="text-primary-600 underline">Podgląd DPD</button>
                                <button wire:click="generateLabel('dhl', {{ $shipment->id }}, true)" wire:confirm="confirm=1: wygenerować etykietę DHL bez pickup/mail/marketplace?" class="text-primary-600 underline">Generuj etykietę DHL</button>
                                <button wire:click="generateLabel('dpd', {{ $shipment->id }}, true)" wire:confirm="confirm=1: wygenerować etykietę DPD bez pickup/mail/marketplace?" class="text-primary-600 underline">Generuj etykietę DPD</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="p-4 text-gray-500">Brak przesyłek. Użyj technicznego endpointu /tools/create-order-shipment albo dodaj przesyłkę dla zamówienia.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $shipments->links('vendor.pagination.gps-polish') }}</div>
        </x-filament::section>

        <x-filament::section heading="Szybkie akcje dla zamówień bez przesyłki" description="Kontrolowany limit 10 najnowszych zamówień bez przesyłki.">
            <div class="space-y-2 text-sm">
                @forelse($ordersWithoutShipment as $order)
                    <div class="flex flex-wrap gap-3 rounded border p-3">
                        <strong>{{ $order->order_number }}</strong><span>{{ $order->customer_name }}</span>
                        <a class="text-primary-600 underline" href="{{ route('tools.create-order-shipment', ['order' => $order->id, 'carrier' => 'dhl']) }}">Dry-run DHL</a>
                        <a class="text-primary-600 underline" href="{{ route('tools.create-order-shipment', ['order' => $order->id, 'carrier' => 'dpd']) }}">Dry-run DPD</a>
                        <a class="text-primary-600 underline" href="{{ route('tools.create-order-shipment', ['order' => $order->id, 'carrier' => 'dhl', 'confirm' => 1]) }}">Confirm DHL</a>
                        <a class="text-primary-600 underline" href="{{ route('tools.create-order-shipment', ['order' => $order->id, 'carrier' => 'dpd', 'confirm' => 1]) }}">Confirm DPD</a>
                    </div>
                @empty
                    <div class="rounded border border-dashed p-3 text-gray-500">Brak zamówień bez przesyłki w bieżącym limicie.</div>
                @endforelse
            </div>
        </x-filament::section>

        @if($preview)
            <x-filament::section heading="Podgląd requestu / wynik">
                <pre class="overflow-auto rounded bg-gray-950 p-4 text-xs text-gray-100">{{ json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
