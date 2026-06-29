@php
    use App\Models\Shipment;

    $shipments = $this->shipments;
    $ordersWithoutShipment = $this->ordersWithoutShipment;
    $dhlCountryOptions = $this->dhlCountryOptions;
@endphp

<x-filament-panels::page>
    <style>
        .gps-shipments-toolbar{display:grid;grid-template-columns:minmax(280px,1fr) minmax(150px,auto) minmax(150px,auto) minmax(120px,auto);gap:12px;align-items:end;margin-bottom:16px}.gps-field{display:flex;flex-direction:column;gap:6px;min-width:0}.gps-field label,.gps-inline-label{font-size:12px;font-weight:700;color:#64748b}.gps-field input,.gps-field select{width:100%;min-width:0;border:1px solid #d1d5db;border-radius:10px;background:#fff;padding:9px 12px;font-size:14px;color:#0f172a}.gps-field input[type=checkbox]{width:auto}.gps-shipments-grid{display:grid;grid-template-columns:90px minmax(190px,1fr) minmax(100px,.55fr) minmax(130px,.7fr) minmax(160px,.9fr) minmax(140px,.7fr) minmax(280px,1.2fr);gap:14px;align-items:center}.gps-quick-grid{display:grid;grid-template-columns:minmax(180px,.8fr) minmax(220px,1fr) minmax(280px,1.2fr);gap:14px;align-items:center}.gps-header{padding:0 16px 8px;color:#64748b;font-size:12px;font-weight:800;text-transform:uppercase}.gps-card{border:1px solid #e5e7eb;border-radius:16px;background:#fff;box-shadow:0 8px 20px rgba(15,23,42,.05);padding:16px}.gps-title{font-weight:800;color:#0f172a}.gps-muted{color:#64748b;font-size:12px}.gps-badge{display:inline-flex;border-radius:999px;background:#f1f5f9;padding:4px 8px;font-size:12px;font-weight:700;color:#334155}.gps-actions{display:flex;gap:8px;flex-wrap:wrap}.gps-action{border-radius:999px;border:1px solid #bfdbfe;color:#1d4ed8;font-size:12px;font-weight:800;padding:7px 10px;text-decoration:none;background:#fff}.gps-action:hover{background:#eff6ff}.gps-empty{border:1px dashed #cbd5e1;border-radius:16px;padding:28px;text-align:center;color:#64748b;background:#fff}.gps-pagination{margin-top:18px;display:flex;flex-direction:column;gap:12px}.gps-light-section{margin-top:28px}.gps-section-heading{margin-bottom:12px}.gps-section-heading h2{font-size:16px;font-weight:800;color:#0f172a}.gps-section-heading p{margin-top:2px;color:#64748b;font-size:13px}.gps-preview{margin-top:28px;border:1px solid #e5e7eb;border-radius:16px;background:#fff;padding:16px;box-shadow:0 8px 20px rgba(15,23,42,.05)}
        .gps-dhl-form{margin-bottom:22px}.gps-dhl-shell{border:1px solid #e5e7eb;border-radius:18px;background:#f8fafc;padding:14px;margin-bottom:14px}.gps-form-section{border:1px solid #e5e7eb;border-radius:16px;background:#fff;padding:14px;margin-bottom:14px;min-width:0}.gps-dhl-shell .gps-form-section{margin-bottom:0}.gps-form-section h3{font-size:15px;font-weight:900;color:#0f172a;margin-bottom:12px}.gps-subheading{font-size:12px;font-weight:900;color:#334155;margin:12px 0 8px;text-transform:uppercase;letter-spacing:.02em}.gps-party-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.gps-form-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.gps-form-grid-2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.gps-parcel-row{display:grid;grid-template-columns:.65fr .85fr .75fr .75fr .75fr .75fr auto;gap:10px;align-items:end}.gps-parcel-notes{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-top:10px}.gps-service-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px}.gps-service-card{border:1px solid #e2e8f0;border-radius:14px;background:#f8fafc;padding:12px}.gps-product-note{color:#475569;font-size:13px;line-height:1.45}.gps-wide{grid-column:span 2}.gps-radio-group{display:flex;gap:22px;flex-wrap:wrap;align-items:center;min-height:40px}.gps-radio-option{display:inline-flex;align-items:center;gap:7px;color:#334155;font-size:13px;font-weight:700}.gps-radio-option input[type=radio]{appearance:auto;-webkit-appearance:radio;display:inline-block!important;position:static!important;width:16px!important;height:16px!important;margin:0!important;opacity:1!important;visibility:visible!important;border:1px solid #64748b;border-radius:50%;background:#fff;padding:0;accent-color:#1d4ed8}.gps-checks{display:flex;gap:10px 14px;flex-wrap:wrap}.gps-checks label,.gps-checkbox-line{font-size:13px;font-weight:700;color:#334155}.gps-checkbox-line{display:inline-flex;align-items:center;gap:7px;min-height:40px}.gps-special-services{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.gps-special-service{display:flex;align-items:center;gap:8px;flex-wrap:wrap;border:1px solid #e2e8f0;border-radius:12px;background:#fff;padding:9px 10px}.gps-special-service span{font-size:13px;font-weight:800;color:#334155}.gps-special-service input[type=text],.gps-special-service input[type=number]{flex:1 1 125px;min-width:110px;border:1px solid #d1d5db;border-radius:10px;background:#fff;padding:7px 10px;font-size:13px;color:#0f172a}.gps-action-bar{justify-content:flex-end;border:1px solid #e5e7eb;border-radius:16px;background:#fff;padding:12px}.gps-primary{background:#1d4ed8;color:#fff;border-color:#1d4ed8}@media(max-width:1200px){.gps-shipments-toolbar,.gps-shipments-grid,.gps-quick-grid{grid-template-columns:1fr 1fr}.gps-header{display:none}.gps-parcel-row{grid-template-columns:repeat(3,minmax(0,1fr))}.gps-service-grid{grid-template-columns:1fr}}@media(max-width:900px){.gps-party-grid,.gps-form-grid,.gps-form-grid-2,.gps-parcel-notes,.gps-special-services{grid-template-columns:1fr}.gps-wide{grid-column:span 1}}@media(max-width:700px){.gps-shipments-toolbar,.gps-shipments-grid,.gps-quick-grid,.gps-parcel-row{grid-template-columns:1fr}.gps-action-bar{justify-content:flex-start}}
    </style>

    <div class="gps-actions" style="margin-bottom:14px"><button type="button" wire:click="openDhlForm" class="gps-action gps-primary">Utwórz DHL</button><span class="gps-muted">createShipment DHL24 WebAPI v2; domyślnie REGULAR_PICKUP, bez automatycznego kuriera.</span></div>

    @if($showDhlForm)
        <form wire:submit="createDhlShipment" class="gps-dhl-form">
            <div class="gps-dhl-shell gps-party-grid">
                <div class="gps-form-section">
                    <h3>Dane nadawcy</h3>
                    <div class="gps-form-grid-2">
                        <div class="gps-field"><label>Kraj</label><input wire:model="dhlForm.shipper.country"></div>
                        <div class="gps-field"><label>Nazwa</label><input wire:model="dhlForm.shipper.name"></div>
                    </div>
                    <h4 class="gps-subheading">Adres</h4>
                    <div class="gps-form-grid-2">
                        <div class="gps-field"><label>Miejscowość</label><input wire:model="dhlForm.shipper.city"></div>
                        <div class="gps-field"><label>Kod pocztowy</label><input wire:model="dhlForm.shipper.postal_code"></div>
                        <div class="gps-field"><label>Ulica</label><input wire:model="dhlForm.shipper.street"></div>
                        <div class="gps-field"><label>Numer domu</label><input wire:model="dhlForm.shipper.house_number"></div>
                        <div class="gps-field"><label>Numer lokalu</label><input wire:model="dhlForm.shipper.apartment_number"></div>
                    </div>
                    <h4 class="gps-subheading">Kontakt</h4>
                    <div class="gps-form-grid-2">
                        <div class="gps-field"><label>Osoba kontaktowa</label><input wire:model="dhlForm.shipper.person_name"></div>
                        <div class="gps-field"><label>Email</label><input type="email" wire:model="dhlForm.shipper.email"></div>
                        <div class="gps-field"><label>Telefon</label><input wire:model="dhlForm.shipper.phone"></div>
                    </div>
                </div>
                <div class="gps-form-section">
                    <h3>Dane odbiorcy</h3>
                    <div class="gps-form-grid-2">
                        <div class="gps-field"><label>Kraj</label><select wire:model="dhlForm.receiver.country">@foreach ($dhlCountryOptions as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select></div>
                        <div class="gps-field"><label>Typ odbiorcy</label><div class="gps-radio-group"><label class="gps-radio-option"><input type="radio" value="private" wire:model="dhlForm.receiver.receiver_type"> <span>Osoba prywatna</span></label><label class="gps-radio-option"><input type="radio" value="company" wire:model="dhlForm.receiver.receiver_type"> <span>Firma/Instytucja</span></label></div></div>
                        <div class="gps-field"><label>Nazwa skrócona</label><input wire:model="dhlForm.receiver.short_name"></div>
                        <div class="gps-field"><label>Nazwa</label><input wire:model="dhlForm.receiver.name" placeholder="Imię i nazwisko albo nazwa firmy/instytucji"></div>
                        <div class="gps-field"><label>Numer klienta SAP</label><input wire:model="dhlForm.receiver.sap_number"></div>
                    </div>
                    <h4 class="gps-subheading">Adres</h4>
                    <div class="gps-form-grid-2">
                        <div class="gps-field"><label>Miejscowość</label><input wire:model="dhlForm.receiver.city"></div>
                        <div class="gps-field"><label>Ulica</label><input wire:model="dhlForm.receiver.street"></div>
                        <div class="gps-field"><label>Numer domu</label><input wire:model="dhlForm.receiver.house_number"></div>
                        <div class="gps-field"><label>Numer lokalu</label><input wire:model="dhlForm.receiver.apartment_number"></div>
                        <div class="gps-field"><label>Kod pocztowy</label><input wire:model="dhlForm.receiver.postal_code"></div>
                    </div>
                    <h4 class="gps-subheading">Kontakt</h4>
                    <div class="gps-form-grid-2">
                        <div class="gps-field"><label>Osoba kontaktowa</label><input wire:model="dhlForm.receiver.person_name"></div>
                        <div class="gps-field"><label>Email</label><input type="email" wire:model="dhlForm.receiver.email"></div>
                        <div class="gps-field"><label>Telefon</label><input wire:model="dhlForm.receiver.phone"></div>
                    </div>
                    <div class="gps-checks" style="margin-top:10px"><label><input type="checkbox" wire:model="dhlForm.receiver.neighbour_delivery"> Doręczenie do sąsiada</label><label><input type="checkbox" wire:model="dhlForm.receiver.save_to_address_book" disabled> Dodaj do książki adresowej</label></div>
                </div>
            </div>
            <div class="gps-form-section"><h3>Szczegóły przesyłki</h3><div class="gps-parcel-row">
                <div class="gps-field"><label>Liczba paczek</label><input type="number" min="1" wire:model="dhlForm.parcel.quantity"></div><div class="gps-field"><label>Typ</label><select wire:model="dhlForm.parcel.type"><option value="PACKAGE">Paczka</option><option value="ENVELOPE">Koperta</option><option value="PALLET">Paleta</option></select></div><div class="gps-field"><label>Waga kg</label><input type="number" step="0.1" min="0.1" wire:model="dhlForm.parcel.weight"></div><div class="gps-field"><label>Długość cm</label><input type="number" min="1" wire:model="dhlForm.parcel.length"></div><div class="gps-field"><label>Szerokość cm</label><input type="number" min="1" wire:model="dhlForm.parcel.width"></div><div class="gps-field"><label>Wysokość cm</label><input type="number" min="1" wire:model="dhlForm.parcel.height"></div><label class="gps-checkbox-line"><input type="checkbox" wire:model="dhlForm.parcel.non_standard"> Niestandardowa</label>
            </div><div class="gps-parcel-notes"><div class="gps-field"><label>Zawartość</label><input wire:model="dhlForm.parcel.content"></div><div class="gps-field"><label>Uwagi</label><input wire:model="dhlForm.parcel.comment"></div><div class="gps-field"><label>Referencja</label><input wire:model="dhlForm.parcel.reference"></div></div></div>
            <div class="gps-form-section"><h3>Doręczenie / usługa</h3><div class="gps-service-grid"><div class="gps-service-card"><div class="gps-form-grid-2">
                <div class="gps-field"><label>Produkt DHL</label><select wire:model="dhlForm.service.service_type"><option value="AH">DHL Parcel Polska / AH</option><option value="09">DHL 09</option><option value="12">DHL 12</option><option value="DW">DHL Doręczenie wieczorne / DW</option><option value="SP">SP</option><option value="EK">EK</option><option value="PI">PI</option><option value="PR">PR</option><option value="CP">CP</option><option value="CM">CM</option></select></div><div class="gps-field"><label>Etykieta PDF</label><select wire:model="dhlForm.service.label_type"><option value="LBLP">LBLP PDF A4</option><option value="BLP">BLP PDF</option></select></div><div class="gps-field"><label>Data nadania</label><input type="date" wire:model="dhlForm.service.shipment_date"></div><div class="gps-field"><label>Typ nadania</label><select wire:model="dhlForm.service.drop_off_type"><option value="REGULAR_PICKUP">REGULAR_PICKUP</option><option value="REQUEST_COURIER">REQUEST_COURIER</option></select></div><div class="gps-field"><label>Od godz.</label><input wire:model="dhlForm.service.shipment_start_hour"></div><div class="gps-field"><label>Do godz.</label><input wire:model="dhlForm.service.shipment_end_hour"></div>
            </div><div class="gps-checks" style="margin-top:10px"><label><input type="checkbox" wire:model="dhlForm.service.order_courier"> Zamów kuriera</label></div></div><div class="gps-service-card"><p class="gps-product-note"><strong>DHL Parcel Polska / AH:</strong> Krajowy standard. Przesyłki krajowe do 31,5 kg. Doręczenie do drzwi kurierem.</p><h4 class="gps-subheading">Usługi dodatkowe</h4><div class="gps-special-services">
                <label class="gps-special-service"><span><input type="checkbox" wire:model.live="dhlForm.special_services.insurance"> Ubezpieczenie przesyłki</span>@if (data_get($this->dhlForm, 'special_services.insurance'))<input type="number" step="0.01" min="0.01" placeholder="Wartość (zł)" wire:model="dhlForm.special_services.insurance_value" required>@endif</label><label class="gps-special-service"><span><input type="checkbox" wire:model.live="dhlForm.special_services.cod"> Zwrot pobrania (COD)</span>@if (data_get($this->dhlForm, 'special_services.cod'))<input type="number" step="0.01" min="0.01" placeholder="Wartość (zł)" wire:model="dhlForm.special_services.cod_value" required>@endif</label><label class="gps-special-service"><span><input type="checkbox" wire:model="dhlForm.special_services.pdi"> PDI</span></label><label class="gps-special-service"><span><input type="checkbox" wire:model="dhlForm.special_services.pod"> POD</span></label><label class="gps-special-service"><span><input type="checkbox" wire:model="dhlForm.special_services.rod"> ROD</span></label><label class="gps-special-service"><span><input type="checkbox" wire:model="dhlForm.special_services.sas"> SAS / Doręczenie do sąsiada</span></label><label class="gps-special-service"><span><input type="checkbox" wire:model="dhlForm.special_services.odb"> ODB</span></label>
            </div></div></div></div>
            @if ($errors->any())<div class="gps-card" style="border-color:#fecaca;color:#991b1b;margin-bottom:12px">{{ $errors->first() }}</div>@endif
            <div class="gps-actions gps-action-bar"><button type="submit" class="gps-action gps-primary" wire:confirm="To utworzy live przesyłkę DHL createShipment. Kontynuować?">Utwórz przesyłkę DHL</button><button type="button" class="gps-action" wire:click="$set('showDhlForm', false)">Anuluj</button></div>
        </form>
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
                    <button type="button" wire:click="openDhlForm(null, {{ $shipment->id }})" class="gps-action">Utwórz DHL</button>
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
                        <button type="button" class="gps-action" wire:click="openDhlForm({{ $order->id }})">Utwórz DHL</button>
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
