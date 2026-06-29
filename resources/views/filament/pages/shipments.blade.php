@php
    use App\Models\Shipment;

    $shipments = $this->shipments;
    $ordersWithoutShipment = $this->ordersWithoutShipment;
@endphp

<x-filament-panels::page>
    <style>.gps-shipments-toolbar{display:grid;grid-template-columns:minmax(280px,1fr) minmax(150px,auto) minmax(150px,auto) minmax(120px,auto);gap:12px;align-items:end;margin-bottom:16px}.gps-field{display:flex;flex-direction:column;gap:6px;min-width:0}.gps-field label{font-size:12px;font-weight:700;color:#64748b}.gps-field input,.gps-field select{width:100%;border:1px solid #d1d5db;border-radius:10px;background:#fff;padding:9px 12px;font-size:14px;color:#0f172a}.gps-shipments-grid{display:grid;grid-template-columns:90px minmax(190px,1fr) minmax(100px,.55fr) minmax(130px,.7fr) minmax(160px,.9fr) minmax(140px,.7fr) minmax(280px,1.2fr);gap:14px;align-items:center}.gps-quick-grid{display:grid;grid-template-columns:minmax(180px,.8fr) minmax(220px,1fr) minmax(280px,1.2fr);gap:14px;align-items:center}.gps-header{padding:0 16px 8px;color:#64748b;font-size:12px;font-weight:800;text-transform:uppercase}.gps-card{border:1px solid #e5e7eb;border-radius:16px;background:#fff;box-shadow:0 8px 20px rgba(15,23,42,.05);padding:16px}.gps-title{font-weight:800;color:#0f172a}.gps-muted{color:#64748b;font-size:12px}.gps-badge{display:inline-flex;border-radius:999px;background:#f1f5f9;padding:4px 8px;font-size:12px;font-weight:700;color:#334155}.gps-actions{display:flex;gap:8px;flex-wrap:wrap}.gps-action{border-radius:999px;border:1px solid #bfdbfe;color:#1d4ed8;font-size:12px;font-weight:800;padding:7px 10px;text-decoration:none;background:#fff}.gps-action:hover{background:#eff6ff}.gps-empty{border:1px dashed #cbd5e1;border-radius:16px;padding:28px;text-align:center;color:#64748b;background:#fff}.gps-pagination{margin-top:18px;display:flex;flex-direction:column;gap:12px}.gps-light-section{margin-top:28px}.gps-section-heading{margin-bottom:12px}.gps-section-heading h2{font-size:16px;font-weight:800;color:#0f172a}.gps-section-heading p{margin-top:2px;color:#64748b;font-size:13px}.gps-preview{margin-top:28px;border:1px solid #e5e7eb;border-radius:16px;background:#fff;padding:16px;box-shadow:0 8px 20px rgba(15,23,42,.05)}@media(max-width:1200px){.gps-shipments-toolbar,.gps-shipments-grid,.gps-quick-grid{grid-template-columns:1fr 1fr}.gps-header{display:none}}@media(max-width:700px){.gps-shipments-toolbar,.gps-shipments-grid,.gps-quick-grid{grid-template-columns:1fr}}</style>

    <div class="gps-shipments-toolbar">
        <div class="gps-field"><label>Szukaj</label><input type="search" wire:model.live.debounce.500ms="search" placeholder="ID, tracking, numer zamówienia..."></div>
        <div class="gps-field"><label>Kurier</label><select wire:model.live="carrier"><option value="">Wszyscy kurierzy</option>@foreach (Shipment::CARRIERS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
        <div class="gps-field"><label>Status</label><select wire:model.live="status"><option value="">Wszystkie statusy</option>@foreach (Shipment::STATUSES as $value)<option value="{{ $value }}">{{ $value }}</option>@endforeach</select></div>
        <div class="gps-field"><label>Na stronę</label><select wire:model.live="perPage">@foreach ($this->perPageOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
    </div>

    <div class="space-y-3">
        <div class="gps-shipments-grid gps-header"><div>ID</div><div>Zamówienie</div><div>Kurier</div><div>Status</div><div>Tracking</div><div>Etykieta</div><div>Akcje</div></div>
        @forelse ($shipments as $shipment)
            <div class="gps-card gps-shipments-grid" wire:key="shipment-{{ $shipment->id }}">
                <div class="gps-title">#{{ $shipment->id }}</div>
                <div><div class="gps-title">{{ $shipment->order?->order_number ?? '—' }}</div><div class="gps-muted">{{ $shipment->order?->customer_name ?? 'Brak klienta' }}</div></div>
                <div><span class="gps-badge">{{ strtoupper($shipment->carrier ?: '—') }}</span></div>
                <div><span class="gps-badge">{{ $shipment->shipment_status }}</span></div>
                <div>{{ $shipment->tracking_number ?: '—' }}</div>
                <div>@if($shipment->label_path)<a class="gps-action" href="{{ route('tools.download-shipment-label', $shipment) }}">Pobierz etykietę</a>@else <span class="gps-muted">—</span> @endif</div>
                <div class="gps-actions">
                    <button type="button" wire:click="generateLabel('dhl', {{ $shipment->id }}, false)" class="gps-action">Podgląd DHL</button>
                    <button type="button" wire:click="generateLabel('dpd', {{ $shipment->id }}, false)" class="gps-action">Podgląd DPD</button>
                    <button type="button" wire:click="generateLabel('dhl', {{ $shipment->id }}, true)" wire:confirm="confirm=1: wygenerować etykietę DHL bez pickup/mail/marketplace?" class="gps-action">Generuj DHL</button>
                    <button type="button" wire:click="generateLabel('dpd', {{ $shipment->id }}, true)" wire:confirm="confirm=1: wygenerować etykietę DPD bez pickup/mail/marketplace?" class="gps-action">Generuj DPD</button>
                </div>
            </div>
        @empty
            <div class="gps-empty">Brak przesyłek. Użyj technicznego endpointu /tools/create-order-shipment albo dodaj przesyłkę dla zamówienia.</div>
        @endforelse
    </div>

    <div class="gps-pagination">{{ $shipments->links('vendor.pagination.gps-polish') }}</div>

    <section class="gps-light-section">
        <div class="gps-section-heading">
            <h2>Szybkie akcje dla zamówień bez przesyłki</h2>
            <p>Kontrolowany limit 10 najnowszych zamówień bez przesyłki.</p>
        </div>

        <div class="space-y-3">
            <div class="gps-quick-grid gps-header"><div>Zamówienie</div><div>Klient</div><div>Akcje</div></div>
            @forelse($ordersWithoutShipment as $order)
                <div class="gps-card gps-quick-grid" wire:key="order-without-shipment-{{ $order->id }}">
                    <div class="gps-title">{{ $order->order_number }}</div>
                    <div>{{ $order->customer_name }}</div>
                    <div class="gps-actions">
                        <a class="gps-action" href="{{ route('tools.create-order-shipment', ['order' => $order->id, 'carrier' => 'dhl']) }}">Dry-run DHL</a>
                        <a class="gps-action" href="{{ route('tools.create-order-shipment', ['order' => $order->id, 'carrier' => 'dpd']) }}">Dry-run DPD</a>
                        <a class="gps-action" href="{{ route('tools.create-order-shipment', ['order' => $order->id, 'carrier' => 'dhl', 'confirm' => 1]) }}">Confirm DHL</a>
                        <a class="gps-action" href="{{ route('tools.create-order-shipment', ['order' => $order->id, 'carrier' => 'dpd', 'confirm' => 1]) }}">Confirm DPD</a>
                    </div>
                </div>
            @empty
                <div class="gps-empty">Brak zamówień bez przesyłki w bieżącym limicie.</div>
            @endforelse
        </div>
    </section>

    @if($preview)
        <section class="gps-preview">
            <div class="gps-section-heading"><h2>Podgląd requestu / wynik</h2></div>
            <pre class="overflow-auto rounded bg-gray-950 p-4 text-xs text-gray-100">{{ json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        </section>
    @endif
</x-filament-panels::page>
