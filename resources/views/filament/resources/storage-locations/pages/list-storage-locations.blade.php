@php
    use App\Filament\Resources\StorageLocationResource;

    $locations = $this->locations;
@endphp

<x-filament-panels::page>
    <style>.gps-toolbar{display:grid;grid-template-columns:minmax(280px,1fr) minmax(140px,auto) minmax(150px,auto) auto;gap:12px;align-items:end;margin-bottom:16px}.gps-field{display:flex;flex-direction:column;gap:6px}.gps-field label{font-size:12px;font-weight:700;color:#64748b}.gps-field input,.gps-field select{border:1px solid #d1d5db;border-radius:10px;background:#fff;padding:9px 12px;font-size:14px}.gps-grid{display:grid;grid-template-columns:90px minmax(220px,1fr) minmax(160px,.8fr) 120px 170px 150px;gap:14px;align-items:center}.gps-header{padding:0 16px 8px;color:#64748b;font-size:12px;font-weight:800;text-transform:uppercase}.gps-card{border:1px solid #e5e7eb;border-radius:16px;background:#fff;padding:16px}.gps-title{font-weight:800;color:#0f172a}.gps-muted{color:#64748b}.gps-badge{border-radius:999px;padding:4px 8px;font-size:12px;font-weight:800}.gps-ok{background:#dcfce7;color:#166534}.gps-no{background:#fee2e2;color:#991b1b}.gps-action{border-radius:999px;border:1px solid #bfdbfe;color:#1d4ed8;font-size:12px;font-weight:800;padding:7px 10px;text-decoration:none;background:#fff}.gps-actions{display:flex;gap:8px;flex-wrap:wrap}.gps-empty{border:1px dashed #cbd5e1;border-radius:16px;padding:28px;text-align:center;color:#64748b;background:#fff}.gps-pagination{margin-top:18px;display:flex;flex-direction:column;gap:12px}.gps-pagination-bar{display:flex;justify-content:flex-end}@media(max-width:1000px){.gps-toolbar,.gps-grid{grid-template-columns:1fr 1fr}.gps-header{display:none}}@media(max-width:700px){.gps-toolbar,.gps-grid{grid-template-columns:1fr}}</style>

    <div class="gps-toolbar">
        <div class="gps-field"><label>Szukaj</label><input type="search" wire:model.live.debounce.500ms="search" placeholder="Nazwa lub ID..."></div>
        <div class="gps-field"><label>Aktywne</label><select wire:model.live="isActive"><option value="">Wszystkie</option><option value="1">Aktywne</option><option value="0">Nieaktywne</option></select></div>
        <div class="gps-field"><label>Sortowanie</label><select wire:model.live="sort"><option value="name_asc">Nazwa A-Z</option><option value="name_desc">Nazwa Z-A</option><option value="id_desc">ID: najnowsze</option><option value="id_asc">ID: najstarsze</option><option value="updated_desc">Ostatnio zmienione</option></select></div>
        <div class="gps-field"><label>Na stronę</label><select wire:model.live="perPage">@foreach ($this->perPageOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
    </div>

    <div class="space-y-3">
        <div class="gps-grid gps-header"><div>ID</div><div>Nazwa</div><div>Liczba części</div><div>Aktywne</div><div>Zaktualizowano</div><div>Akcje</div></div>
        @forelse ($locations as $location)
            <div class="gps-card gps-grid" wire:key="storage-location-{{ $location->id }}">
                <div class="gps-title">#{{ $location->id }}</div><div class="gps-title">{{ $location->name }}</div><div class="gps-muted">{{ $location->parts_count }}</div><div><span class="gps-badge {{ $location->is_active ? 'gps-ok' : 'gps-no' }}">{{ $location->is_active ? 'Aktywne' : 'Nieaktywne' }}</span></div><div>{{ $location->updated_at?->format('Y-m-d H:i') ?? '—' }}</div>
                <div class="gps-actions"><a class="gps-action" href="{{ StorageLocationResource::getUrl('view', ['record' => $location]) }}">Podgląd</a><a class="gps-action" href="{{ StorageLocationResource::getUrl('edit', ['record' => $location]) }}">Edytuj</a></div>
            </div>
        @empty
            <div class="gps-empty">Brak miejsc składowania dla wybranych kryteriów.</div>
        @endforelse
    </div>

    <div class="gps-pagination">{{ $locations->links('vendor.pagination.gps-polish') }}</div>
</x-filament-panels::page>
