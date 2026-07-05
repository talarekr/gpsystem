@php
    $source = strtolower(trim((string) ($marketplace ?? '')));
    $source = match (true) {
        $source === '', in_array($source, ['sklep', 'local', 'store', 'storefront', 'shop'], true) => 'local',
        in_array($source, ['sprzedaż lokalna', 'sprzedaz lokalna', 'local_sale', 'local sale'], true) => 'local_sale',
        in_array($source, ['ręczna zmiana stanu magazynowego', 'reczna zmiana stanu magazynowego', 'manual_stock_change', 'manual stock change'], true) => 'manual_stock_change',
        str_starts_with($source, 'allegro') => 'allegro',
        str_starts_with($source, 'ovoko') => 'ovoko',
        str_starts_with($source, 'ebay') => 'ebay',
        default => $source,
    };
    $sourceClass = preg_replace('/[^a-z0-9_-]+/', '-', $source) ?: 'unknown';
@endphp

@once
    <style>
        .gps-order-source {
            display: inline-flex;
            align-items: center;
            max-width: 100%;
            border: 0;
            border-radius: 0;
            padding: 0;
            background: transparent;
            font-size: 12px;
            font-weight: 700;
            line-height: 1.25;
            vertical-align: baseline;
        }

        .gps-order-source--allegro {
            color: #ff5a00;
            font-family: "Open Sans", Arial, sans-serif;
        }

        .gps-order-source--ovoko {
            color: #FF7A00;
            font-family: Inter, Arial, Helvetica, sans-serif;
        }

        .gps-order-source--ebay {
            font-family: "Market Sans", Arial, "Helvetica Neue", sans-serif;
            letter-spacing: -.02em;
        }

        .gps-order-source--local,
        .gps-order-source--local_sale,
        .gps-order-source--manual_stock_change {
            color: #334155;
            font-family: inherit;
            font-weight: 600;
        }
    </style>
@endonce

<span class="gps-order-source gps-order-source--{{ $sourceClass }}" aria-label="Źródło: {{ match ($source) { 'local' => 'Sklep', 'local_sale' => 'Sprzedaż lokalna', 'manual_stock_change' => 'Ręczna zmiana stanu magazynowego', default => $source } }}">
    @if ($source === 'ebay')
        <span style="color:#E53238">e</span><span style="color:#0064D2">B</span><span style="color:#F5AF02">a</span><span style="color:#86B817">y</span>
    @elseif ($source === 'allegro')
        allegro
    @elseif ($source === 'ovoko')
        ovoko
    @elseif ($source === 'local')
        Sklep
    @elseif ($source === 'local_sale')
        Sprzedaż lokalna
    @elseif ($source === 'manual_stock_change')
        Ręczna zmiana stanu magazynowego
    @else
        {{ ucfirst($source) }}
    @endif
</span>
