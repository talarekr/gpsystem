@php
    use App\Filament\Resources\JarekGearboxResource;
@endphp

<div class="gps-parts-list">
    <div class="gps-parts-list-header gps-admin-parts-grid"><div>Część</div><div>Numer części</div><div>Kanały sprzedaży</div><div>Mapowanie</div><div>Status</div><div>Notatka</div><div>ID</div><div>Akcje</div></div>
    @forelse ($gearboxes as $gearbox)
        @php
            $images = collect($gearbox->displayImageUrls())->filter()->unique()->values();
            $imageUrl = $images->first();
            $identifier = $gearbox->allegro_offer_id ?: $gearbox->id;
            $currency = $gearbox->currency ?: 'PLN';
            $price = filled($gearbox->price) ? number_format((float) $gearbox->price, 2, ',', ' ').' '.$currency : '—';
            $allegroUrl = $gearbox->allegro_offer_url;
            $ebayPreviewUrl = route('admin.tools.jarek-gearboxes.ebay-preview', $gearbox);
        @endphp
        <div class="gps-part-card"><div class="gps-admin-parts-grid">
            <div class="gps-part-col"><div class="gps-part-main"><div class="gps-part-thumb">@if ($imageUrl)<img src="{{ $imageUrl }}" alt="Zdjęcie skrzyni #{{ $gearbox->id }}" loading="lazy">@if ($images->count() > 1)<span class="gps-part-thumb__badge">{{ $images->count() }}</span>@endif @else <span class="gps-part-thumb__placeholder">Brak<br>zdjęcia</span>@endif</div><div class="gps-part-info"><a class="gps-part-title" href="{{ JarekGearboxResource::getUrl('edit', ['record' => $gearbox]) }}">{{ $gearbox->title ?: 'Bez nazwy' }}</a><div class="gps-part-muted">Magazyn: {{ filled($gearbox->quantity) ? 'Ilość '.$gearbox->quantity : 'Ilość —' }}</div><div class="gps-part-muted">{{ $gearbox->category_name ?: 'Brak kategorii' }}</div></div></div></div>
            <div class="gps-part-col"><div class="gps-part-number-row"><span class="gps-part-number">{{ $identifier ?: '—' }}</span>@if (filled($identifier))<button type="button" class="gps-part-copy" title="Kopiuj numer części" onclick="event.preventDefault(); navigator.clipboard?.writeText(@js((string) $identifier));"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M7 3.5A2.5 2.5 0 0 1 9.5 1h6A2.5 2.5 0 0 1 18 3.5v6a2.5 2.5 0 0 1-2.5 2.5h-6A2.5 2.5 0 0 1 7 9.5v-6Z"/><path d="M4.5 6A2.5 2.5 0 0 0 2 8.5v8A2.5 2.5 0 0 0 4.5 19h8a2.5 2.5 0 0 0 2.5-2.5V14h-2v2.5a.5.5 0 0 1-.5.5h-8a.5.5 0 0 1-.5-.5v-8a.5.5 0 0 1 .5-.5H7V6H4.5Z"/></svg></button>@endif</div></div>
            <div class="gps-part-col"><div class="gps-admin-channels part-channel-list"><div class="part-channel-row"><span class="part-channel-label">Allegro Jarka</span><span class="part-channel-price">{{ $price }}</span><span class="part-channel-status">{{ filled($gearbox->allegro_status) ? '●' : '○' }}</span><span class="part-channel-link-slot">@if ($allegroUrl)<a href="{{ $allegroUrl }}" target="_blank" rel="noopener">↗</a>@endif</span></div><div class="part-channel-row"><span class="part-channel-label">eBay preview</span><span class="part-channel-price">{{ $price }}</span><span class="part-channel-status">{{ filled($gearbox->ebay_status) ? '●' : '○' }}</span><span class="part-channel-link-slot"><a href="{{ $ebayPreviewUrl }}" target="_blank" rel="noopener">↗</a></span></div></div></div>
            <div class="gps-part-col"><div class="gps-part-muted">Allegro: {{ $gearbox->allegro_offer_id ?: '—' }}</div><div class="gps-part-muted">eBay SKU: {{ $gearbox->ebay_inventory_sku ?: '—' }}</div></div>
            <div class="gps-part-col"><div><span class="gps-part-status-text {{ $gearbox->allegro_status === 'ACTIVE' ? 'gps-part-status-text--ready' : '' }}">{{ $gearbox->allegro_status ?: '—' }}</span></div><div class="gps-part-muted">eBay: {{ $gearbox->ebay_status ?: '—' }}</div></div>
            <div class="gps-part-col"><div class="gps-part-muted">Import: {{ $gearbox->imported_at?->format('Y-m-d H:i') ?: '—' }}</div></div>
            <div class="gps-part-col"><div class="gps-part-id">{{ $gearbox->id }}</div></div>
            <div class="gps-part-col"><div class="gps-part-actions"><a class="gps-part-action" href="{{ JarekGearboxResource::getUrl('edit', ['record' => $gearbox]) }}">Edytuj</a><a class="gps-part-action" href="{{ $ebayPreviewUrl }}" target="_blank" rel="noopener">eBay preview</a></div></div>
        </div></div>
    @empty
        <div class="gps-part-empty">Brak skrzyń Jarka pasujących do wybranych kryteriów.</div>
    @endforelse
</div>
