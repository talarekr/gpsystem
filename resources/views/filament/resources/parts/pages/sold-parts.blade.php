@php
    $soldParts = $this->soldParts;
@endphp

<x-filament-panels::page>
    <style>
        .gps-parts-toolbar{display:grid;grid-template-columns:minmax(280px,1fr);gap:12px;align-items:end;margin-bottom:16px}.gps-parts-field{display:flex;flex-direction:column;gap:6px;min-width:0}.gps-parts-field label{font-size:12px;font-weight:700;color:#64748b}.gps-parts-field input,.gps-parts-field select{width:100%;border:1px solid #d1d5db;border-radius:10px;background:#fff;padding:9px 12px;font-size:14px;color:#0f172a}
        .gps-sold-parts-list { display: flex; flex-direction: column; gap: 12px; width: 100%; }
        .gps-sold-parts-grid { display: grid; grid-template-columns: minmax(360px, 2fr) minmax(130px, .65fr) minmax(150px, .75fr) minmax(140px, .7fr) minmax(120px, .6fr) minmax(80px, .4fr) minmax(150px, .75fr); gap: 18px; align-items: center; width: 100%; }
        .gps-sold-parts-header { padding: 0 18px 4px; color: #64748b; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; }
        .gps-sold-part-card { border: 1px solid #e5e7eb; border-radius: 18px; background: #fff; box-shadow: 0 10px 24px rgba(15, 23, 42, .06); padding: 18px; }
        .gps-sold-part-item { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .gps-sold-part-thumb, .gps-sold-part-placeholder { flex: 0 0 120px; width: 120px; height: 90px; }
        .gps-sold-part-thumb { display: block; object-fit: cover; border-radius: 6px; background: #f1f5f9; }
        .gps-sold-part-placeholder { display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; background: linear-gradient(135deg, #f8fafc, #e2e8f0); color: #94a3b8; border: 1px solid #e2e8f0; }
        .gps-sold-part-info, .gps-sold-part-col { min-width: 0; }
        .gps-sold-part-value { color: #1e293b; font-size: 13px; font-weight: 500; line-height: 1.35; overflow-wrap: anywhere; }
        .gps-sold-part-muted { color: #64748b; font-size: 12px; margin-top: 5px; overflow-wrap: anywhere; }
        .gps-sold-part-total { color: #1e293b; font-size: 13px; font-weight: 700; white-space: nowrap; }
        .gps-sold-part-actions { display: flex; flex-wrap: wrap; gap: 6px; }
        .gps-sold-part-action { border-radius: 999px; border: 1px solid #bfdbfe; color: #1d4ed8; font-size: 12px; font-weight: 800; padding: 7px 10px; text-decoration: none; white-space: nowrap; }
        .gps-sold-part-empty { border: 1px dashed #cbd5e1; border-radius: 18px; padding: 32px; text-align: center; color: #64748b; background: #fff; }
        .gps-sold-parts-pagination { margin-top: 18px; }
        @media (max-width: 1200px) { .gps-sold-parts-grid { grid-template-columns: 1fr 1fr; } .gps-sold-parts-header { display: none; } }
        @media (max-width: 700px) { .gps-sold-parts-grid { grid-template-columns: 1fr; } }
    </style>

    <div class="gps-parts-toolbar">
        <div class="gps-parts-field">
            <label for="sold-parts-search">Szukaj</label>
            <input id="sold-parts-search" type="search" wire:model.live.debounce.500ms="search" placeholder="ID, nazwa, SKU, numer, OEM, zamówienie, źródło...">
        </div>
    </div>

    <div class="gps-sold-parts-list">
        <div class="gps-sold-parts-header gps-sold-parts-grid">
            <div>Część</div>
            <div>Źródło</div>
            <div>Zamówienie / ref.</div>
            <div>Data sprzedaży</div>
            <div>Cena</div>
            <div>ID</div>
            <div>Linki</div>
        </div>

        @forelse ($soldParts as $row)
            <div class="gps-sold-part-card">
                <div class="gps-sold-parts-grid">
                    <div class="gps-sold-part-col">
                        <div class="gps-sold-part-item">
                            @if (($row['thumbnail_source'] ?? null) === 'admin_parts_thumbnail' && $row['part'] instanceof \App\Models\Part)
                                @include('filament.resources.parts.table-image', ['part' => $row['part']])
                            @elseif (! empty($row['thumbnail_url']))
                                <img class="gps-sold-part-thumb" src="{{ $row['thumbnail_url'] }}" alt="{{ $row['name'] }}" loading="lazy">
                            @else
                                <div class="gps-sold-part-placeholder" aria-hidden="true"><x-heroicon-o-photo class="h-7 w-7" /></div>
                            @endif
                            <div class="gps-sold-part-info">
                                <div class="gps-sold-part-value">{{ $row['name'] }}</div>
                                <div class="gps-sold-part-muted">Magazyn: {{ $row['storage_location'] ?? 'Brak lokalizacji' }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="gps-sold-part-col"><div class="gps-sold-part-value">@include('filament.resources.orders.partials.source-wordmark', ['marketplace' => $row['source'] ?: 'sklep'])</div></div>
                    <div class="gps-sold-part-col"><div class="gps-sold-part-value">{{ $row['reference'] }}</div></div>
                    <div class="gps-sold-part-col"><div class="gps-sold-part-value">{{ $row['sold_at'] ? $row['sold_at']->format('Y-m-d H:i') : '—' }}</div></div>
                    <div class="gps-sold-part-col"><div class="gps-sold-part-total">{{ number_format((float) $row['price'], 2, ',', ' ') }} {{ $row['currency'] }}</div></div>
                    <div class="gps-sold-part-col"><div class="gps-sold-part-value">{{ $row['part_id'] ?: '—' }}</div></div>
                    <div class="gps-sold-part-col">
                        <div class="gps-sold-part-actions">
                            @if ($row['part_url'])<a class="gps-sold-part-action" href="{{ $row['part_url'] }}">Część</a>@endif
                            @if ($row['order_url'])<a class="gps-sold-part-action" href="{{ $row['order_url'] }}">Zamówienie</a>@endif
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="gps-sold-part-empty">Brak sprzedanych części w lokalnych danych.</div>
        @endforelse
    </div>

    <div class="gps-sold-parts-pagination">{{ $soldParts->links() }}</div>
</x-filament-panels::page>
