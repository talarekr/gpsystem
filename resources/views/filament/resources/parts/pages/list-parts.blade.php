@php
    use App\Filament\Resources\PartResource;
    use App\Models\Part;

    $parts = $this->parts;
@endphp

<x-filament-panels::page>
    <style>
        .gps-parts-toolbar{display:grid;grid-template-columns:minmax(280px,1fr) minmax(180px,auto) minmax(110px,auto) minmax(110px,auto) auto;gap:12px;align-items:end;margin-bottom:16px}.gps-parts-field{display:flex;flex-direction:column;gap:6px;min-width:0}.gps-parts-field label{font-size:12px;font-weight:700;color:#64748b}.gps-parts-field input,.gps-parts-field select{width:100%;border:1px solid #d1d5db;border-radius:10px;background:#fff;padding:9px 12px;font-size:14px;color:#0f172a}.gps-parts-filter-summary{display:inline-flex;align-items:center;justify-content:center;min-height:40px;border-radius:10px;background:#eff6ff;color:#1d4ed8;font-weight:800;padding:0 12px;white-space:nowrap}.gps-parts-button,.gps-parts-reset-button{min-height:40px;border-radius:10px;border:1px solid #d1d5db;padding:0 14px;font-weight:800;color:#334155;background:#fff}.gps-parts-button{border-color:#bfdbfe;color:#1d4ed8}.gps-parts-button:hover,.gps-parts-reset-button:hover{background:#f8fafc}.gps-parts-advanced{display:grid;grid-template-columns:repeat(6,minmax(140px,1fr));gap:12px;margin:-4px 0 20px;padding:16px;border:1px solid #e5e7eb;border-radius:16px;background:#f8fafc}.gps-parts-pagination{margin-top:18px;display:flex;flex-direction:column;gap:12px}.gps-parts-pagination-bar{display:flex;justify-content:flex-end}.gps-parts-list{display:flex;flex-direction:column;gap:12px;width:100%}.gps-admin-parts-grid{display:grid;grid-template-columns:minmax(360px,1.45fr) minmax(260px,1fr) minmax(170px,.65fr) minmax(190px,.75fr) minmax(170px,.65fr);gap:20px;width:100%;align-items:center}.gps-parts-list-header{padding:0 18px 4px;color:#64748b;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.04em}.gps-part-card{width:100%;min-height:140px;display:block;border:1px solid #e5e7eb;border-radius:18px;background:#fff;box-shadow:0 10px 24px rgba(15,23,42,.06);padding:18px;color:#1e293b;font-size:13px;font-weight:400;line-height:1.35}.gps-part-col{min-width:0}.gps-part-main{display:flex;align-items:center;gap:12px;min-width:0}.gps-part-thumb{position:relative;flex:0 0 150px;width:150px;height:112px;border:1px solid #e5e7eb;border-radius:6px;background:#f1f5f9;overflow:hidden}.gps-part-thumb img{width:100%;height:100%;object-fit:cover;display:block}.gps-part-thumb__placeholder{display:flex;align-items:center;justify-content:center;height:100%;text-align:center;color:#94a3b8;font-size:12px}.gps-part-thumb__badge{position:absolute;right:5px;bottom:5px;border-radius:999px;background:rgba(248,250,252,.88);border:1px solid rgba(226,232,240,.9);color:#64748b;padding:1px 5px;font-size:10px;font-weight:500;line-height:1.25}.gps-part-info{min-width:0}.gps-part-id{color:#64748b;font-size:12px;font-weight:400;margin-top:5px}.gps-part-title{display:-webkit-box;overflow:hidden;color:#1e293b;font-size:13px;font-weight:400;line-height:1.35;text-decoration:none;text-overflow:ellipsis;white-space:normal;-webkit-box-orient:vertical;-webkit-line-clamp:2}.gps-part-title:hover{color:#2563eb;text-decoration:underline}.gps-part-muted{color:#64748b;font-size:12px;font-weight:400;margin-top:5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.gps-part-number-row{display:flex;align-items:center;gap:6px;margin-top:0}.gps-part-number{overflow:hidden;color:#334155;font-size:14px;font-weight:700;line-height:1.25;text-overflow:ellipsis;white-space:nowrap}.gps-part-copy{display:inline-flex;width:22px;height:22px;align-items:center;justify-content:center;border:0;border-radius:999px;background:#f1f5f9;color:#64748b;cursor:pointer}.gps-part-copy:hover{background:#e2e8f0;color:#1e293b}.gps-part-copy svg{width:14px;height:14px}.gps-part-review{margin-top:8px;padding-top:8px;border-top:1px dashed #e2e8f0}.gps-part-actions{display:flex;flex-wrap:wrap;gap:7px}.gps-part-action{border-radius:999px;border:1px solid #bfdbfe;color:#1d4ed8;font-size:12px;font-weight:800;padding:7px 10px;text-decoration:none;white-space:nowrap;background:#fff}.gps-part-action--success{border-color:#bbf7d0;color:#15803d}.gps-part-empty{border:1px dashed #cbd5e1;border-radius:18px;padding:32px;text-align:center;color:#64748b;background:#fff}.gps-admin-channels.part-channel-list{width:100%!important;min-width:0!important;max-width:none!important}@media(max-width:1300px){.gps-parts-toolbar,.gps-parts-advanced,.gps-admin-parts-grid{grid-template-columns:1fr 1fr}.gps-parts-list-header{display:none}}@media(max-width:700px){.gps-parts-toolbar,.gps-parts-advanced,.gps-admin-parts-grid{grid-template-columns:1fr}.gps-parts-pagination-bar{justify-content:stretch}.gps-part-main{align-items:flex-start}.gps-part-thumb{flex-basis:120px;width:120px;height:90px}}
    </style>

    <div x-data="{ filtersOpen: false }">
        <div class="gps-parts-toolbar">
            <div class="gps-parts-field"><label for="parts-search">Szukaj</label><input id="parts-search" type="search" wire:model.live.debounce.500ms="search" placeholder="ID, nazwa, SKU, numer, OEM, kategoria, auto..."></div>
            <div class="gps-parts-field"><label>Sortowanie</label><select wire:model.live="sort"><option value="id_desc">ID: najnowsze</option><option value="id_asc">ID: najstarsze</option><option value="quantity_desc">Ilość: malejąco</option><option value="quantity_asc">Ilość: rosnąco</option><option value="status_asc">Status</option><option value="review_detected_desc">Wykryto: najnowsze</option><option value="created_desc">Utworzono: najnowsze</option><option value="created_asc">Utworzono: najstarsze</option><option value="updated_desc">Zaktualizowano: najnowsze</option><option value="updated_asc">Zaktualizowano: najstarsze</option></select></div>
            <div class="gps-parts-filter-summary">Filtry: {{ $this->activeFiltersCount }}</div>
            <button class="gps-parts-button" type="button" x-on:click="filtersOpen = ! filtersOpen" x-text="filtersOpen ? 'Ukryj filtry' : 'Filtry'"></button>
            @if ($this->activeFiltersCount > 0)
                <button class="gps-parts-reset-button" type="button" wire:click="resetFilters">Wyczyść filtry</button>
            @endif
        </div>

        <div class="gps-parts-advanced" x-cloak x-show="filtersOpen" x-transition>
            <div class="gps-parts-field"><label>Status</label><select wire:model.live="status"><option value="">Wszystkie</option>@foreach (Part::statusOptions() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
            <div class="gps-parts-field"><label>Kategoria</label><select wire:model.live="categoryId"><option value="">Wszystkie</option>@foreach (PartResource::categoryOptions() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
            <div class="gps-parts-field"><label>Miejsce składowania</label><select wire:model.live="storageLocationId"><option value="">Wszystkie</option>@foreach ($this->storageLocationOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
            @foreach ([['categoryNeedsReview','Kategoria wymaga sprawdzenia'],['isVisibleStorefront','Widoczna w sklepie'],['needsListing','Część do wystawienia'],['needsReview','Do wyjaśnienia'],['missingImages','Brak zdjęć'],['missingPrice','Brak ceny'],['missingSku','Brak SKU'],['missingPartNumber','Brak numeru części']] as [$model,$label])
                <div class="gps-parts-field"><label>{{ $label }}</label><select wire:model.live="{{ $model }}"><option value="">Wszystkie</option><option value="1">Tak</option><option value="0">Nie</option></select></div>
            @endforeach
            <div class="gps-parts-field"><label>Data od</label><input type="date" wire:model.live="createdFrom"></div><div class="gps-parts-field"><label>Data do</label><input type="date" wire:model.live="createdUntil"></div>
            <div class="gps-parts-field"><label>Samochód</label><select wire:model.live="carId"><option value="">Wszystkie</option>@foreach ($this->carOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
            <div class="gps-parts-field"><label>Stan / uwagi</label><input type="text" wire:model.live.debounce.500ms="conditionNotes"></div><div class="gps-parts-field"><label>Cena od</label><input type="number" step="0.01" wire:model.live.debounce.500ms="priceFrom"></div><div class="gps-parts-field"><label>Cena do</label><input type="number" step="0.01" wire:model.live.debounce.500ms="priceUntil"></div><div class="gps-parts-field"><label>Cena Allegro od</label><input type="number" step="0.01" wire:model.live.debounce.500ms="allegroPriceFrom"></div><div class="gps-parts-field"><label>Cena Allegro do</label><input type="number" step="0.01" wire:model.live.debounce.500ms="allegroPriceUntil"></div><div class="gps-parts-field"><label>Cena eBay od</label><input type="number" step="0.01" wire:model.live.debounce.500ms="ebayPriceFrom"></div><div class="gps-parts-field"><label>Cena eBay do</label><input type="number" step="0.01" wire:model.live.debounce.500ms="ebayPriceUntil"></div><div class="gps-parts-field"><label>Utworzył</label><input type="text" wire:model.live.debounce.500ms="createdBy"></div>
        </div>
    </div>

    <div class="gps-parts-list">
        <div class="gps-parts-list-header gps-admin-parts-grid"><div>Część</div><div>Kanały sprzedaży</div><div>Numer części</div><div>Status</div><div>Akcje</div></div>
        @forelse ($parts as $part)
            @php
                $images = $part->relationLoaded('images') ? $part->images : collect();
                $imageUrl = $part->adminTableImageUrl();
                $statusLabel = Part::statusOptions()[$part->status] ?? ($part->status ?: '—');
                $number = trim((string) $part->part_number);
            @endphp
            <div class="gps-part-card"><div class="gps-admin-parts-grid">
                <div class="gps-part-col"><div class="gps-part-main"><div class="gps-part-thumb">@if ($imageUrl)<img src="{{ $imageUrl }}" alt="Zdjęcie części #{{ $part->id }}" loading="lazy">@if ($images->count() > 1)<span class="gps-part-thumb__badge">{{ $images->count() }}</span>@endif @else <span class="gps-part-thumb__placeholder">Brak<br>zdjęcia</span>@endif</div><div class="gps-part-info"><a class="gps-part-title" href="{{ PartResource::getUrl('edit', ['record' => $part]) }}">{{ $part->name ?: 'Bez nazwy' }}</a><div class="gps-part-muted">Magazyn: {{ $part->storageLocation?->name ?: 'Brak lokalizacji' }}</div><div class="gps-part-id">#{{ $part->id }}</div></div></div></div>
                <div class="gps-part-col">@include('filament.resources.parts.table-channels', ['part' => $part])</div>
                <div class="gps-part-col"><div class="gps-part-number-row"><span class="gps-part-number">{{ filled($number) ? $number : '—' }}</span>@if (filled($number))<button type="button" class="gps-part-copy" title="Kopiuj numer części" onclick="event.preventDefault(); navigator.clipboard?.writeText(@js($number));"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M7 3.5A2.5 2.5 0 0 1 9.5 1h6A2.5 2.5 0 0 1 18 3.5v6a2.5 2.5 0 0 1-2.5 2.5h-6A2.5 2.5 0 0 1 7 9.5v-6Z"/><path d="M4.5 6A2.5 2.5 0 0 0 2 8.5v8A2.5 2.5 0 0 0 4.5 19h8a2.5 2.5 0 0 0 2.5-2.5V14h-2v2.5a.5.5 0 0 1-.5.5h-8a.5.5 0 0 1-.5-.5v-8a.5.5 0 0 1 .5-.5H7V6H4.5Z"/></svg></button>@endif</div></div>
                <div class="gps-part-col"><div>{{ $statusLabel }}</div>@if ($part->review_reason || $part->review_detected_at || $part->review_source)<div class="gps-part-review"><div class="gps-part-muted">{{ $part->review_reason }}</div><div class="gps-part-muted">Wykryto: {{ $part->review_detected_at?->format('Y-m-d H:i') ?: '—' }}</div><div class="gps-part-muted">Źródło: {{ $part->review_source ?: '—' }}</div></div>@endif</div>
                <div class="gps-part-col"><div class="gps-part-actions"><a class="gps-part-action" href="{{ PartResource::getUrl('edit', ['record' => $part]) }}">Edytuj</a><a class="gps-part-action" href="{{ PartResource::getUrl('view', ['record' => $part]) }}">Podgląd</a>@if ($part->needs_listing)<button class="gps-part-action gps-part-action--success" type="button" wire:click="markListingReady({{ $part->id }})" wire:confirm="Oznaczyć część jako gotową?">Oznacz jako gotowe</button>@endif</div></div>
            </div></div>
        @empty
            <div class="gps-part-empty">Brak części pasujących do wybranych kryteriów.</div>
        @endforelse
    </div>

    <div class="gps-parts-pagination">
        <div class="gps-parts-pagination-bar">
            <div class="gps-parts-field">
                <label>Na stronę</label>
                <select wire:model.live="perPage">
                    @foreach ($this->perPageOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        {{ $parts->links('vendor.pagination.gps-polish') }}
    </div>
</x-filament-panels::page>
