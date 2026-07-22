        <form wire:submit="createDhlShipment" class="gps-dhl-form">
            <div class="gps-dhl-shell gps-party-grid">
                <div class="gps-form-section">
                    <h3>Dane nadawcy</h3>
                    <div class="gps-form-grid-2">
                        <div class="gps-field"><label>Kraj</label><select wire:model="dhlForm.shipper.country">@foreach ($dhlCountryOptions as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select></div>
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
                <div class="gps-field gps-parcel-qty"><label>Ilość</label><input type="number" min="1" wire:model="dhlForm.parcel.quantity"></div><span class="gps-parcel-separator">x</span><div class="gps-field gps-parcel-type"><label>Typ</label><select wire:model="dhlForm.parcel.type"><option value="ENVELOPE">Koperta</option><option value="PACKAGE">Paczka</option><option value="PALLET">Paleta</option></select></div><span class="gps-parcel-separator">x</span><div class="gps-field gps-parcel-measure"><label>Waga</label><input type="number" step="0.1" min="0.1" wire:model="dhlForm.parcel.weight"></div><span class="gps-parcel-unit">kg</span><div class="gps-field gps-parcel-measure"><label>Długość</label><input type="number" min="1" wire:model="dhlForm.parcel.length"></div><span class="gps-parcel-separator">x</span><div class="gps-field gps-parcel-measure"><label>Szerokość</label><input type="number" min="1" wire:model="dhlForm.parcel.width"></div><span class="gps-parcel-separator">x</span><div class="gps-field gps-parcel-measure"><label>Wysokość</label><input type="number" min="1" wire:model="dhlForm.parcel.height"></div><span class="gps-parcel-unit">cm</span><div class="gps-parcel-checks"><label class="gps-checkbox-line"><input type="checkbox" wire:model="dhlForm.parcel.non_standard"> Niestandardowa</label><label class="gps-checkbox-line"><input type="checkbox" wire:model="dhlForm.parcel.volumetric"> Wolumetryk</label><label class="gps-checkbox-line"><input type="checkbox" wire:model="dhlForm.parcel.euro_return"> Zwrot palety</label><label class="gps-checkbox-line"><input type="checkbox" wire:model="dhlForm.parcel.half_pallet"> Półpaleta</label></div>
            </div><div class="gps-parcel-notes"><div class="gps-field"><label>Zawartość</label><input wire:model="dhlForm.parcel.content"></div><div class="gps-field"><label>Uwagi</label><input wire:model="dhlForm.parcel.comment"></div><div class="gps-field"><label>Referencja</label><input wire:model="dhlForm.parcel.reference"></div><div class="gps-field"><label>MPK</label><input wire:model="dhlForm.parcel.mpk"></div><div class="gps-field"><label>Dodatkowe</label><select wire:model="dhlForm.parcel.dhl_option"><option value="">Brak</option><option value="standard">Standard</option></select></div></div></div>
            <div class="gps-form-section"><h3>Doręczenie / usługa</h3><div class="gps-service-grid"><div class="gps-service-card"><div class="gps-form-grid-2">
                <div class="gps-field"><label>Produkt DHL</label><select wire:model="dhlForm.service.service_type"><option value="AH">DHL Parcel Polska / AH</option><option value="09">DHL 09</option><option value="12">DHL 12</option><option value="DW">DHL Doręczenie wieczorne / DW</option><option value="SP">SP</option><option value="EK">EK</option><option value="PI">PI</option><option value="PR">PR</option><option value="CP">CP</option><option value="CM">CM</option></select></div><div class="gps-field"><label>Etykieta PDF</label><select wire:model="dhlForm.service.label_type"><option value="LBLP">LBLP PDF A4</option><option value="BLP">BLP PDF</option></select></div><div class="gps-field"><label>Data nadania</label><input type="date" wire:model="dhlForm.service.shipment_date"></div><div class="gps-field"><label>Typ nadania</label><select wire:model="dhlForm.service.drop_off_type"><option value="REGULAR_PICKUP">REGULAR_PICKUP</option><option value="REQUEST_COURIER">REQUEST_COURIER</option></select></div><div class="gps-field"><label>Od godz.</label><input wire:model="dhlForm.service.shipment_start_hour"></div><div class="gps-field"><label>Do godz.</label><input wire:model="dhlForm.service.shipment_end_hour"></div>
            </div><div class="gps-checks" style="margin-top:10px"><label><input type="checkbox" wire:model="dhlForm.service.order_courier"> Zamów kuriera</label></div></div><div class="gps-service-card"><p class="gps-product-note"><strong>DHL Parcel Polska / AH:</strong> Krajowy standard. Przesyłki krajowe do 31,5 kg. Doręczenie do drzwi kurierem.</p><h4 class="gps-subheading">Usługi dodatkowe</h4><div class="gps-special-services">
                <label class="gps-special-service"><span><input type="checkbox" wire:model.live="dhlForm.special_services.insurance"> Ubezpieczenie przesyłki</span>@if (data_get($this->dhlForm, 'special_services.insurance'))<input type="number" step="0.01" min="0.01" placeholder="Wartość (zł)" wire:model="dhlForm.special_services.insurance_value" required>@endif</label><label class="gps-special-service"><span><input type="checkbox" wire:model.live="dhlForm.special_services.cod"> Zwrot pobrania (COD)</span>@if (data_get($this->dhlForm, 'special_services.cod'))<input type="number" step="0.01" min="0.01" placeholder="Wartość (zł)" wire:model="dhlForm.special_services.cod_value" required>@endif</label><label class="gps-special-service"><span><input type="checkbox" wire:model="dhlForm.special_services.pdi"> PDI</span></label><label class="gps-special-service"><span><input type="checkbox" wire:model="dhlForm.special_services.pod"> POD</span></label><label class="gps-special-service"><span><input type="checkbox" wire:model="dhlForm.special_services.rod"> ROD</span></label><label class="gps-special-service"><span><input type="checkbox" wire:model="dhlForm.special_services.sas"> SAS / Doręczenie do sąsiada</span></label><label class="gps-special-service"><span><input type="checkbox" wire:model="dhlForm.special_services.odb"> ODB</span></label>
            </div></div></div></div>
            @if ($errors->any())<div class="gps-card" style="border-color:#fecaca;color:#991b1b;margin-bottom:12px">{{ $errors->first() }}</div>@endif
            <div class="gps-actions gps-action-bar"><button type="submit" class="gps-action gps-primary" wire:confirm="To utworzy live przesyłkę DHL createShipment. Kontynuować?">Utwórz przesyłkę DHL</button>@if($this instanceof \App\Filament\Pages\CreateShipment)<a href="{{ \App\Filament\Pages\Shipments::getUrl() }}" class="gps-action" wire:navigate>Anuluj</a>@else<button type="button" class="gps-action" wire:click="$set('showDhlForm', false)">Anuluj</button>@endif</div>
        </form>