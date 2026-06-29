@php
    $dhlCountryOptions = $this->dhlCountryOptions;
@endphp

<x-filament-panels::page>
    @include('filament.pages.partials.shipment-styles')

    @include('filament.pages.partials.dhl-shipment-form')

    @if($preview)
        <section class="gps-preview">
            <div class="gps-section-heading"><h2>Podgląd requestu / wynik</h2></div>
            <pre class="overflow-auto rounded bg-gray-950 p-4 text-xs text-gray-100">{{ json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        </section>
    @endif
</x-filament-panels::page>
