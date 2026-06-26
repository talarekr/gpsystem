<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section heading="Przesyłki" description="Istniejący moduł przesyłek rozszerzony o dry-run oraz generowanie etykiet DHL/DPD. Akcje nie zamawiają pickupów, nie wysyłają maili i nie zapisują nic do marketplace.">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead><tr class="text-left"><th class="p-2">ID</th><th class="p-2">Zamówienie</th><th class="p-2">Kurier</th><th class="p-2">Status</th><th class="p-2">Tracking</th><th class="p-2">Etykieta</th><th class="p-2">Akcje</th></tr></thead>
                    <tbody>
                    @forelse ($this->shipments() as $shipment)
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
        </x-filament::section>

        <x-filament::section heading="Szybkie akcje dla zamówień bez przesyłki">
            <div class="space-y-2 text-sm">
                @foreach($this->ordersWithoutShipment() as $order)
                    <div class="flex flex-wrap gap-3 rounded border p-3">
                        <strong>{{ $order->order_number }}</strong><span>{{ $order->customer_name }}</span>
                        <a class="text-primary-600 underline" href="{{ route('tools.create-order-shipment', ['order' => $order->id, 'carrier' => 'dhl']) }}">Dry-run DHL</a>
                        <a class="text-primary-600 underline" href="{{ route('tools.create-order-shipment', ['order' => $order->id, 'carrier' => 'dpd']) }}">Dry-run DPD</a>
                        <a class="text-primary-600 underline" href="{{ route('tools.create-order-shipment', ['order' => $order->id, 'carrier' => 'dhl', 'confirm' => 1]) }}">Confirm DHL</a>
                        <a class="text-primary-600 underline" href="{{ route('tools.create-order-shipment', ['order' => $order->id, 'carrier' => 'dpd', 'confirm' => 1]) }}">Confirm DPD</a>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        @if($preview)
            <x-filament::section heading="Podgląd requestu / wynik">
                <pre class="overflow-auto rounded bg-gray-950 p-4 text-xs text-gray-100">{{ json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
