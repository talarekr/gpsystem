@php
    use App\Models\Shipment;

    $shipments = $this->shipments;
    // code_marker = shipment_admin_ui_missing_label_hotfix_v1
@endphp

<x-filament-panels::page>
    @include('filament.pages.partials.shipment-styles')

    <div class="gps-actions" style="margin-bottom:14px"><a href="{{ \App\Filament\Pages\CreateShipment::getUrl() }}" class="gps-action gps-primary">Dodaj przesyłkę</a></div>

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
        <div class="gps-shipments-grid gps-header"><div>ID</div><div>Zamówienie</div><div>Kurier</div><div>Status</div><div>Tracking</div><div>Etykieta</div><div>Akcje</div></div>
        @forelse ($shipments as $shipment)
            @php
                try {
                    $shipmentId = is_scalar($shipment->id ?? null) ? (string) $shipment->id : '—';
                    $orderNumber = $this->safeString($shipment->order?->order_number) ?: '—';
                    $customerName = $this->safeString($shipment->order?->customer_name) ?: '—';
                    $carrier = $this->safeString($shipment->carrier);
                    $status = $this->safeString($shipment->shipment_status);
                    $tracking = $this->safeString($shipment->tracking_number) ?: $this->safeString($shipment->carrier_shipment_id);
                    $labelPath = $this->safeString($shipment->label_path);
                    $labelExists = $this->labelExists($shipment);
                    $rowError = false;
                } catch (\Throwable $exception) {
                    report($exception);
                    $shipmentId = is_scalar($shipment->id ?? null) ? (string) $shipment->id : '—';
                    $orderNumber = '—';
                    $customerName = '—';
                    $carrier = null;
                    $status = null;
                    $tracking = null;
                    $labelPath = null;
                    $labelExists = false;
                    $rowError = true;
                }
            @endphp
            <div class="gps-card gps-shipments-grid" wire:key="shipment-{{ $shipment->id }}">
                <div class="gps-title">#{{ $shipmentId }}</div>
                <div><div class="gps-title">{{ $orderNumber }}</div><div class="gps-muted">{{ $customerName }}</div></div>
                <div><span class="gps-badge">{{ $carrier ? strtoupper($carrier) : '—' }}</span></div>
                <div><span class="gps-badge">{{ $status ?: '—' }}</span></div>
                <div>{{ $tracking ?: '—' }}</div>
                <div>@if($rowError)<span class="gps-muted">Brak etykiety</span>@elseif($labelExists)<a class="gps-action" href="{{ route('tools.download-shipment-label', ['shipment' => $shipment->id]) }}">Pobierz etykietę</a>@elseif($labelPath)<span class="gps-muted">Brak pliku etykiety</span>@else <span class="gps-muted">Brak etykiety</span> @endif</div>
                <div class="gps-actions">
                    <a href="{{ \App\Filament\Pages\ShipmentDetails::getUrl(['shipment' => $shipment->id]) }}" class="gps-action">Szczegóły</a>
                    @if($labelExists)<button type="button" wire:click="downloadLabel({{ $shipment->id }})" class="gps-action">Pobierz etykietę PDF</button>@endif
                    <button type="button" wire:click="generateLabel('dpd', {{ $shipment->id }}, false)" class="gps-action">Podgląd DPD</button>
                    @if(! $tracking)<button type="button" wire:click="openDhlForm(null, {{ $shipment->id }})" class="gps-action">Utwórz DHL</button>@else<span class="gps-muted">DHL już istnieje — napraw etykietę</span>@endif
                    <button type="button" wire:click="generateLabel('dpd', {{ $shipment->id }}, true)" wire:confirm="confirm=1: wygenerować etykietę DPD bez pickup/mail/marketplace?" class="gps-action">Generuj DPD</button>
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
