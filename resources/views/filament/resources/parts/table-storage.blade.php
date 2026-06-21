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
        <strong>—</strong>
    @else
        <strong>{{ $storageLocation?->name ?: 'Brak lokalizacji' }}</strong>
        @if ($storageLocation?->description)
            <span>{{ $storageLocation->description }}</span><br>
        @endif
        <span class="gps-admin-chip">Ilość: {{ $part->quantity ?? 0 }}</span>
    @endif
</div>
