@php
    $part = (isset($part) && $part instanceof \App\Models\Part) ? $part : null;

    if (! $part && isset($getRecord) && is_callable($getRecord)) {
        $candidate = $getRecord();
        $part = $candidate instanceof \App\Models\Part ? $candidate : null;
    } elseif (isset($record) && $record instanceof \App\Models\Part) {
        $part = $record;
    }

    $editUrl = $part ? \App\Filament\Resources\PartResource::getUrl('edit', ['record' => $part]) : null;
@endphp

<div class="gps-admin-part-title">
    @if (! $part)
        <span>—</span>
    @else
        <a href="{{ $editUrl }}">{{ $part->name ?: 'Część #'.$part->id }}</a>
        @if (filled($part->sku))
            <small>SKU: {{ $part->sku }}</small>
        @endif
    @endif
</div>
