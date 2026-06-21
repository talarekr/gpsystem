@php($editUrl = \App\Filament\Resources\PartResource::getUrl('edit', ['record' => $record]))
<div class="gps-admin-part-title">
    <a href="{{ $editUrl }}">{{ $record->name ?: 'Część #'.$record->id }}</a>
    @if (filled($record->sku))
        <small>SKU: {{ $record->sku }}</small>
    @endif
</div>
