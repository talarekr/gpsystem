@php
    $receiver = $this->receiver();
    $trackingEvents = $this->trackingEvents;
@endphp

<x-filament-panels::page>
    @include('filament.pages.partials.shipment-styles')

    <div class="gps-actions" style="margin-bottom:14px">
        <a href="{{ \App\Filament\Pages\Shipments::getUrl() }}" class="gps-action">Wróć do listy</a>
        @if($shipment->label_path)
            <a class="gps-action gps-primary" href="{{ route('tools.download-shipment-label', $shipment) }}">Pobierz etykietę PDF</a>
        @endif
    </div>

    <div class="gps-shipment-detail-grid">
        <section class="gps-card gps-detail-section">
            <div class="gps-section-heading">
                <h2>Tracking</h2>
                <p>Tracking DHL pobierany jest ręcznie i tylko odczytuje dane z DHL24.</p>
            </div>

            <div class="gps-actions" style="margin-bottom:14px">
                <button type="button" wire:click="refreshTracking" wire:loading.attr="disabled" class="gps-action gps-primary">
                    <span wire:loading.remove wire:target="refreshTracking">Odśwież tracking</span>
                    <span wire:loading wire:target="refreshTracking">Pobieranie...</span>
                </button>
            </div>

            @if($trackingError)
                <div class="gps-empty gps-empty-compact">{{ $trackingError }}</div>
            @elseif(! $trackingLoaded)
                <div class="gps-empty gps-empty-compact">Kliknij „Odśwież tracking”, aby pobrać aktualne zdarzenia DHL.</div>
            @elseif($trackingEvents === [])
                <div class="gps-empty gps-empty-compact">Brak zdarzeń trackingowych</div>
            @else
                <div class="gps-timeline">
                    @foreach($trackingEvents as $event)
                        <div class="gps-timeline-item" wire:key="tracking-event-{{ $loop->index }}">
                            <div class="gps-timeline-date">{{ $this->formatDateTime($event['timestamp'] ?? null) }}</div>
                            <div class="gps-title">{{ $event['description'] ?? $event['status'] ?? 'Zdarzenie DHL' }}</div>
                            @if(! blank($event['status'] ?? null))
                                <div class="gps-muted">Status: {{ $event['status'] }}</div>
                            @endif
                            @if(! blank($event['terminal'] ?? null))
                                <div class="gps-muted">Terminal: {{ $event['terminal'] }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        <aside class="gps-card gps-detail-section">
            <div class="gps-section-heading"><h2>Informacje o przesyłce</h2></div>
            <dl class="gps-info-list">
                <dt>Przewoźnik</dt><dd>{{ strtoupper($shipment->carrier ?: '—') }}</dd>
                <dt>Numer przesyłki</dt><dd>{{ $this->trackingNumber() ?? '—' }}</dd>
                <dt>Status lokalny</dt><dd><span class="gps-badge">{{ $shipment->shipment_status ?: '—' }}</span></dd>
                <dt>Data utworzenia</dt><dd>{{ $this->formatDateTime($shipment->created_at) }}</dd>
                <dt>Zamówienie</dt><dd>{{ $shipment->order?->order_number ?? '—' }}</dd>
            </dl>

            <div class="gps-section-heading" style="margin-top:18px"><h2>Adres dostawy</h2></div>
            <div class="gps-address">
                <div class="gps-title">{{ $receiver['name'] ?: '—' }}</div>
                @if(! blank($receiver['company']))<div>{{ $receiver['company'] }}</div>@endif
                <div>{{ trim(($receiver['street'] ?: '').(! blank($receiver['apartment']) ? '/'.$receiver['apartment'] : '')) ?: '—' }}</div>
                <div>{{ trim(($receiver['postal_code'] ?? '').' '.($receiver['city'] ?? '')) ?: '—' }}</div>
                <div>{{ $receiver['country'] ?: '—' }}</div>
                @if(! blank($receiver['phone']))<div class="gps-muted">Tel.: {{ $receiver['phone'] }}</div>@endif
                @if(! blank($receiver['email']))<div class="gps-muted">E-mail: {{ $receiver['email'] }}</div>@endif
            </div>
        </aside>
    </div>
</x-filament-panels::page>
