@php
    $part = (isset($part) && $part instanceof \App\Models\Part) ? $part : null;

    if (! $part && isset($getRecord) && is_callable($getRecord)) {
        $candidate = $getRecord();
        $part = $candidate instanceof \App\Models\Part ? $candidate : null;
    } elseif (isset($record) && $record instanceof \App\Models\Part) {
        $part = $record;
    }

    $editUrl = $part ? \App\Filament\Resources\PartResource::getUrl('edit', ['record' => $part]) : null;
    $storageLocation = ($part instanceof \App\Models\Part && $part->relationLoaded('storageLocation')) ? $part->storageLocation : null;
    $storageName = $part instanceof \App\Models\Part ? ($storageLocation?->name ?: 'Brak lokalizacji') : '—';
@endphp

<div class="gps-admin-part-cell gps-admin-part-title">
    @if (! $part)
        <span>—</span>
    @else
        <a href="{{ $editUrl }}" wire:navigate>{{ $part->name ?: 'Część #'.$part->id }}</a>
        <small>Magazyn: {{ $storageName }}</small>
    @endif
</div>
