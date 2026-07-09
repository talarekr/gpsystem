@php
    use App\Filament\Resources\OrderResource;
    use App\Models\Shipment;

    $shipments = $this->shipments;
    $dhlCountryOptions = $this->dhlCountryOptions;
@endphp

<x-filament-panels::page>
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
        <div class="gps-shipments-grid gps-header"><div>ID</div><div>Zamówienie</div><div>Kurier</div><div>Numer przesyłki</div><div>Etykieta</div><div>Akcje</div></div>
        @forelse ($shipments as $shipment)
            @php
                $carrier = $this->safeString($shipment->carrier);
                $tracking = $this->safeString($shipment->tracking_number) ?: $this->safeString($shipment->carrier_shipment_id);
                $trackingUrl = $this->trackingUrl($shipment);
                $order = $shipment->order;
                $orderNumber = $order ? OrderResource::displayOrderNumber($order) : '—';
                $marketplace = $this->marketplaceLabel($order?->marketplace);
                $labelExists = $this->labelExists($shipment);
            @endphp
            <div class="gps-card gps-shipments-grid" wire:key="shipment-{{ $shipment->id }}">
                <div class="gps-cell gps-cell-text">#{{ $shipment->id }}</div>
                <div class="gps-cell">
                    <div class="gps-cell-text gps-shipment-order-line">
                        <span class="gps-shipment-order-number">{{ $orderNumber }}</span>
                        @if($marketplace)
                            <span class="gps-muted">·</span>
                            @include('filament.resources.orders.partials.source-wordmark', ['marketplace' => $marketplace])
                        @endif
                    </div>
                    <div class="gps-muted">{{ $this->safeString($shipment->order?->customer_name) ?: 'Brak klienta' }}</div>
                </div>
                <div class="gps-cell gps-cell-text gps-shipment-carrier">{{ in_array(strtolower($carrier ?? ''), ['dhl', 'dpd'], true) ? strtoupper($carrier) : '—' }}</div>
                <div class="gps-cell gps-cell-text">@if($tracking && $trackingUrl)<a class="gps-link" href="{{ $trackingUrl }}" target="_blank" rel="noopener">{{ $tracking }}</a>@else{{ $tracking ?: '—' }}@endif</div>
                <div class="gps-cell">@if($labelExists)<a class="gps-link" href="{{ route('tools.download-shipment-label', ['shipment' => $shipment->id]) }}">Pobierz etykietę</a>@else<span class="gps-muted">Brak pliku etykiety</span>@endif</div>
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
