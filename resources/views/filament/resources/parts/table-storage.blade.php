@php
    $part = (isset($part) && $part instanceof \App\Models\Part) ? $part : null;

    if (! $part && isset($getRecord) && is_callable($getRecord)) {
        $candidate = $getRecord();
        $part = $candidate instanceof \App\Models\Part ? $candidate : null;
    } elseif (isset($record) && $record instanceof \App\Models\Part) {
        $part = $record;
    }

    $storageLocation = ($part instanceof \App\Models\Part && $part->relationLoaded('storageLocation')) ? $part->storageLocation : null;
@endphp

<div class="gps-admin-storage">
    @if (! $part)
        <span class="gps-admin-storage__location">—</span>
    @else
        <span class="gps-admin-storage__location">{{ $storageLocation?->name ?: 'Brak lokalizacji' }}</span>
        @if ($storageLocation?->description)
            <span class="gps-admin-storage__description">{{ $storageLocation->description }}</span>
        @endif
        <span class="gps-admin-storage__quantity">Ilość: {{ $part->quantity ?? 0 }}</span>
    @endif
</div>
