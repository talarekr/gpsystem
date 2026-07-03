@php
    use App\Models\Part;
    use Illuminate\Support\Str;

    $showListingReadyAction = $showListingReadyAction ?? false;
    $showAddedAtInPartTitle = $showAddedAtInPartTitle ?? false;
    $resourceClass = $resourceClass ?? App\Filament\Resources\PartResource::class;
    $statusOptionsClass = $statusOptionsClass ?? App\Models\Part::class;
@endphp

<div class="gps-parts-list">
        <div class="gps-parts-list-header gps-admin-parts-grid"><div>Część</div><div>Numer części</div><div>Kanały sprzedaży</div><div>Mapowanie</div><div>Status</div><div>Notatka</div><div>ID</div><div>Akcje</div></div>
        @forelse ($parts as $part)
            @php
                $images = $part->relationLoaded('images') ? $part->images : collect();
                $imageUrl = $part->adminTableImageUrl();
                $statusLabel = $statusOptionsClass::statusOptions()[$part->status] ?? ($part->status ?: '—');
                $number = trim((string) $part->part_number);
                $addedAt = $part->created_at?->copy()->timezone('Europe/Warsaw')->format('Y-m-d H:i');
            @endphp
            <div class="gps-part-card"><div class="gps-admin-parts-grid">
                <div class="gps-part-col"><div class="gps-part-main"><div class="gps-part-thumb">@if ($imageUrl)<img src="{{ $imageUrl }}" alt="Zdjęcie części #{{ $part->id }}" loading="lazy">@if ($images->count() > 1)<span class="gps-part-thumb__badge">{{ $images->count() }}</span>@endif @else <span class="gps-part-thumb__placeholder">Brak<br>zdjęcia</span>@endif</div><div class="gps-part-info"><a class="gps-part-title" href="{{ $resourceClass::getUrl('edit', ['record' => $part]) }}">{{ $part->name ?: 'Bez nazwy' }}</a><div class="gps-part-muted">Magazyn: {{ $part->storageLocation?->name ?: 'Brak lokalizacji' }}</div>@if ($showAddedAtInPartTitle)<div class="gps-part-muted">Dodano: {{ $addedAt ?: '—' }}</div>@endif</div></div></div>
                <div class="gps-part-col"><div class="gps-part-number-row"><span class="gps-part-number">{{ filled($number) ? $number : '—' }}</span>@if (filled($number))<button type="button" class="gps-part-copy" title="Kopiuj numer części" onclick="event.preventDefault(); navigator.clipboard?.writeText(@js($number));"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M7 3.5A2.5 2.5 0 0 1 9.5 1h6A2.5 2.5 0 0 1 18 3.5v6a2.5 2.5 0 0 1-2.5 2.5h-6A2.5 2.5 0 0 1 7 9.5v-6Z"/><path d="M4.5 6A2.5 2.5 0 0 0 2 8.5v8A2.5 2.5 0 0 0 4.5 19h8a2.5 2.5 0 0 0 2.5-2.5V14h-2v2.5a.5.5 0 0 1-.5.5h-8a.5.5 0 0 1-.5-.5v-8a.5.5 0 0 1 .5-.5H7V6H4.5Z"/></svg></button>@endif</div></div>
                <div class="gps-part-col">@include('filament.resources.parts.table-channels', ['part' => $part])</div>
                <div class="gps-part-col">@include('filament.resources.parts.table-manual-marketplace-links', ['part' => $part])</div>
                <div class="gps-part-col"><div><span class="gps-part-status-text {{ $statusOptionsClass::statusTextClass($part->status) }}">{{ $statusLabel }}</span></div>@if ($part->review_reason || $part->review_detected_at || $part->review_source)<div class="gps-part-review"><div class="gps-part-muted">{{ $part->review_reason }}</div><div class="gps-part-muted">Wykryto: {{ $part->review_detected_at?->format('Y-m-d H:i') ?: '—' }}</div><div class="gps-part-muted">Źródło: {{ $part->review_source ?: '—' }}</div></div>@endif</div>
                <div class="gps-part-col"><details class="gps-part-note"><summary class="gps-part-note__summary">@if (filled($part->internal_note))<span class="gps-part-note__preview">{{ Str::limit($part->internal_note, 24) }}</span>@else<span class="gps-part-note__plus">+</span>@endif</summary><form class="gps-part-note__popover" wire:submit.prevent="saveInternalNote({{ $part->id }}, $event.target.internal_note.value)"><label for="internal-note-{{ $part->id }}">Notatka wewnętrzna</label><textarea id="internal-note-{{ $part->id }}" name="internal_note" rows="3">{{ $part->internal_note }}</textarea><button type="submit">Zapisz</button></form></details></div>
                <div class="gps-part-col"><div class="gps-part-id">{{ $part->id }}</div></div>
                <div class="gps-part-col"><div class="gps-part-actions"><a class="gps-part-action" href="{{ $resourceClass::getUrl('edit', ['record' => $part]) }}">Edytuj</a>@if ($showListingReadyAction && (bool) $part->needs_listing)<button type="button" class="gps-part-action gps-part-action--success" wire:click="markListingReady({{ $part->id }})" wire:confirm="Zapisać i wystawić lokalnie tę część?">Zapisz i wystaw</button>@endif</div></div>
            </div></div>
        @empty
            <div class="gps-part-empty">Brak części pasujących do wybranych kryteriów.</div>
        @endforelse
    </div>
