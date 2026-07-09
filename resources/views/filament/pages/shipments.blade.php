@php
    use App\Models\Shipment;

    $shipments = $this->shipments;
    $dhlCountryOptions = $this->dhlCountryOptions;
@endphp

<x-filament-panels::page>
    {{-- code_marker = shipments_listing_ui_final_v3 --}}
    @include('filament.pages.partials.shipment-styles')


    @if($showDhlForm)
        @include('filament.pages.partials.dhl-shipment-form')
    @endif

    <div class="gps-shipments-toolbar">
        <div class="gps-field"><label>Szukaj</label><input type="search" wire:model.live.debounce.500ms="search" placeholder="ID, tracking, numer zamówienia..."></div>
        <div class="gps-field"><label>Kurier</label><select wire:model.live="carrier"><option value="">Wszyscy kurierzy</option>@foreach (Shipment::CARRIERS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
        <div class="gps-field"><label>Status</label><select wire:model.live="status"><option value="">Wszystkie statusy</option>@foreach (Shipment::STATUSES as $value)<option value="{{ $value }}">{{ $value }}</option>@endforeach</select></div>
        <div class="gps-field"><label>Na stronę</label><select wire:model.live="perPage">@foreach ($this->perPageOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
    </div>

    <div class="space-y-3">
        <div class="gps-shipments-grid gps-header"><div>ID</div><div>Zamówienie</div><div>Data</div><div>Kurier</div><div>Numer przesyłki</div><div>Etykieta</div><div>Akcje</div></div>
        @forelse ($shipments as $shipment)
            @php
                $tracking = $this->safeString($shipment->tracking_number) ?: $this->safeString($shipment->carrier_shipment_id);
                $labelPath = $this->safeString($shipment->label_path);
                $labelExists = $this->labelExists($shipment);
                $carrierLabel = $this->shipmentCarrierLabel($shipment);
                $trackingUrl = $tracking ? $this->trackingUrl($shipment, $tracking) : null;
            @endphp
            <div class="gps-card gps-shipments-grid" wire:key="shipment-{{ $shipment->id }}">
                <div class="gps-cell gps-cell-text">#{{ $shipment->id }}</div>
                <div class="gps-cell gps-order-cell">
                    <span class="gps-cell-text">{{ $this->safeString($shipment->order?->order_number) ?: '—' }}</span>
                    @if($shipment->order?->marketplace)
                        <span class="gps-muted">·</span>
                        @include('filament.resources.orders.partials.source-wordmark', ['marketplace' => $shipment->order->marketplace])
                    @endif
                </div>
                <div class="gps-cell gps-cell-text">{{ $shipment->created_at?->format('Y-m-d H:i') ?: '—' }}</div>
                <div class="gps-cell gps-cell-text">{{ $carrierLabel }}</div>
                <div class="gps-cell gps-cell-text">
                    @if($tracking && $trackingUrl)
                        <a class="gps-tracking-link" href="{{ $trackingUrl }}" target="_blank" rel="noopener noreferrer">{{ $tracking }}</a>
                    @else
                        {{ $tracking ?: '—' }}
                    @endif
                </div>
                <div class="gps-cell">@if($labelExists)<a class="gps-action" href="{{ route('tools.download-shipment-label', ['shipment' => $shipment->id]) }}">Pobierz etykietę PDF</a>@elseif($labelPath)<span class="gps-muted">Brak pliku etykiety</span>@else <span class="gps-muted">Brak etykiety</span> @endif</div>
                <div class="gps-cell gps-actions">
                    <a href="{{ \App\Filament\Pages\ShipmentDetails::getUrl(['shipment' => $shipment->id]) }}" class="gps-action">Szczegóły</a>
                </div>
            </div>
        @empty
            <div class="gps-empty">Brak przesyłek. Użyj technicznego endpointu /tools/create-order-shipment albo dodaj przesyłkę dla zamówienia.</div>
        @endforelse
    </div>

    <div class="gps-pagination">{{ $shipments->links('vendor.pagination.gps-polish') }}</div>



    @if($preview)
        <section class="gps-preview">
            <div class="gps-section-heading"><h2>Podgląd requestu / wynik</h2></div>
            <pre class="overflow-auto rounded bg-gray-950 p-4 text-xs text-gray-100">{{ json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        </section>
    @endif
</x-filament-panels::page>
