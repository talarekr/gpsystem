@php
    use App\Filament\Resources\CarResource;
    use App\Models\Car;

    $cars = $this->cars;
@endphp

<x-filament-panels::page>
    <style>
        .gps-toolbar{display:grid;grid-template-columns:minmax(280px,1fr) repeat(4,minmax(130px,auto)) minmax(110px,auto) auto;gap:12px;align-items:end;margin-bottom:16px}.gps-field{display:flex;flex-direction:column;gap:6px;min-width:0}.gps-field label{font-size:12px;font-weight:700;color:#64748b}.gps-field input,.gps-field select{width:100%;min-width:0;border:1px solid #d1d5db;border-radius:10px;background:#fff;padding:9px 12px;font-size:14px;color:#0f172a}.gps-button{min-height:40px;border-radius:10px;border:1px solid #d1d5db;padding:0 14px;font-size:12px;font-weight:800;color:#334155;background:#fff;white-space:nowrap}.gps-grid{display:grid;grid-template-columns:minmax(260px,1.35fr) repeat(8,minmax(90px,.65fr)) minmax(95px,.5fr);gap:16px;align-items:center}.gps-header{padding:0 16px 8px;color:#64748b;font-size:12px;font-weight:800;text-transform:uppercase}.gps-card{border:1px solid #e5e7eb;border-radius:16px;background:#fff;padding:16px;color:#1e293b;font-size:13px;font-weight:400;line-height:1.35}.gps-cell{min-width:0}.gps-car-text{overflow:hidden;color:#1e293b;font-size:13px;font-weight:400;line-height:1.35;text-overflow:ellipsis;white-space:nowrap}.gps-car-main{color:#1e293b;font-size:13px;font-weight:400;line-height:1.35}.gps-muted{color:#64748b;font-size:12px;font-weight:400;line-height:1.35}.gps-badge{display:inline-flex;border-radius:999px;background:#f1f5f9;color:#334155;padding:4px 8px;font-size:12px;font-weight:600;line-height:1.25;white-space:nowrap}.gps-actions{display:flex;gap:8px;flex-wrap:wrap;min-width:0}.gps-action{border-radius:999px;border:1px solid #bfdbfe;color:#1d4ed8;font-size:12px;font-weight:800;padding:7px 10px;text-decoration:none;background:#fff;white-space:nowrap}.gps-empty{border:1px dashed #cbd5e1;border-radius:16px;padding:28px;text-align:center;color:#64748b;background:#fff}.gps-pagination{margin-top:18px;display:flex;flex-direction:column;gap:12px}.gps-pagination-bar{display:flex;justify-content:flex-end}@media(max-width:1200px){.gps-toolbar,.gps-grid{grid-template-columns:1fr 1fr}.gps-header{display:none}.gps-actions{justify-content:flex-start}}@media(max-width:700px){.gps-toolbar,.gps-grid{grid-template-columns:1fr}.gps-card{gap:10px}.gps-actions{margin-top:2px}}
    </style>

    <div class="gps-toolbar">
        <div class="gps-field"><label>Szukaj</label><input type="search" wire:model.live.debounce.500ms="search" placeholder="Marka, model, wariant, VIN, rejestracja, kod silnika..."></div>
        <div class="gps-field"><label>Status</label><select wire:model.live="status"><option value="">Wszystkie</option>@foreach (Car::statusOptions() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
        <div class="gps-field"><label>Paliwo</label><select wire:model.live="fuelType"><option value="">Wszystkie</option>@foreach (Car::fuelTypeOptions() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
        <div class="gps-field"><label>Skrzynia</label><select wire:model.live="gearboxType"><option value="">Wszystkie</option>@foreach (Car::gearboxTypeOptions() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
        <div class="gps-field"><label>Sortowanie</label><select wire:model.live="sort"><option value="id_desc">ID: najnowsze</option><option value="id_asc">ID: najstarsze</option><option value="make_asc">Marka / model</option><option value="purchase_desc">Zakup: najnowsze</option><option value="purchase_asc">Zakup: najstarsze</option><option value="status_asc">Status</option></select></div>
        <div class="gps-field"><label>Na stronę</label><select wire:model.live="perPage">@foreach ($this->perPageOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
        @if ($this->activeFiltersCount > 0)<button class="gps-button" type="button" wire:click="resetFilters">Wyczyść filtry</button>@endif
    </div>

    <div class="space-y-3">
        <div class="gps-grid gps-header"><div>Samochód</div><div>Paliwo</div><div>Skrzynia</div><div>Silnik</div><div>Rok produkcji</div><div>Moc silnika</div><div>Napęd</div><div>Typ nadwozia</div><div>Kolor</div><div>Akcje</div></div>
        @forelse ($cars as $car)
            <div class="gps-card gps-grid" wire:key="car-{{ $car->id }}">
                <div class="gps-cell"><div class="gps-car-main">#{{ $car->id }} {{ trim(implode(' ', array_filter([$car->make, $car->model]))) ?: '—' }}</div><div class="gps-muted">Ilość części: {{ $car->parts_count }}</div></div>
                <div class="gps-cell gps-car-text">{{ $car->fuel_type ?: '—' }}</div><div class="gps-cell gps-car-text">{{ $car->gearbox_type ?: '—' }}</div><div class="gps-cell gps-car-text">{{ $car->engine_code ?: '—' }}@if($car->engine_capacity_cm3) <span class="gps-muted">{{ $car->engine_capacity_cm3 }} cm3</span>@endif</div><div class="gps-cell gps-car-text">{{ $car->production_year ?: '—' }}</div><div class="gps-cell gps-car-text">{{ filled($car->engine_power_kw) ? $car->engine_power_kw . ' kW' : '—' }}</div><div class="gps-cell gps-car-text">{{ $car->drivetrain ?: '—' }}</div><div class="gps-cell gps-car-text">{{ $car->body_type ?: '—' }}</div><div class="gps-cell gps-car-text">{{ $car->color ?: '—' }}</div>
                <div class="gps-cell gps-actions"><a class="gps-action" href="{{ CarResource::getUrl('edit', ['record' => $car]) }}">Edytuj</a></div>
            </div>
        @empty
            <div class="gps-empty">Brak samochodów dla wybranych kryteriów.</div>
        @endforelse
    </div>

    <div class="gps-pagination">{{ $cars->links('vendor.pagination.gps-polish') }}</div>
</x-filament-panels::page>
