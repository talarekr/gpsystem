@php
    $hasQuery = trim((string) ($query ?? '')) !== '';
    $marketplaces = ['allegro', 'ovoko', 'ebay'];
@endphp

@once
    <style>
        .gps-part-marketplace-price-links {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            gap: 8px 14px;
            margin-top: -2px;
            font-size: 12px;
            line-height: 1.35;
        }

        .gps-part-marketplace-price-links__label {
            color: #64748b;
            font-weight: 500;
        }

        .gps-part-marketplace-price-links__items {
            display: inline-flex;
            flex-wrap: wrap;
            align-items: baseline;
            gap: 12px;
        }

        .gps-part-marketplace-price-links__link {
            display: inline-flex;
            align-items: baseline;
            text-decoration: none;
            opacity: .92;
            transition: opacity .15s ease, transform .15s ease;
        }

        .gps-part-marketplace-price-links__link:hover {
            opacity: 1;
            text-decoration: underline;
            text-underline-offset: 3px;
        }

        .gps-part-marketplace-price-links__disabled {
            display: inline-flex;
            align-items: baseline;
            cursor: not-allowed;
            opacity: .38;
        }
    </style>
@endonce

<div class="gps-part-marketplace-price-links">
    <span class="gps-part-marketplace-price-links__label">Sprawdź ceny:</span>
    <span class="gps-part-marketplace-price-links__items">
        @foreach ($marketplaces as $marketplace)
            @if ($hasQuery && filled($links[$marketplace] ?? null))
                <a class="gps-part-marketplace-price-links__link" href="{{ $links[$marketplace] }}" target="_blank" rel="noopener noreferrer">
                    @include('filament.resources.orders.partials.source-wordmark', ['marketplace' => $marketplace])
                </a>
            @else
                <span class="gps-part-marketplace-price-links__disabled" aria-disabled="true" title="Wpisz główny kod części, aby włączyć link wyszukiwania.">
                    @include('filament.resources.orders.partials.source-wordmark', ['marketplace' => $marketplace])
                </span>
            @endif
        @endforeach
    </span>
</div>
