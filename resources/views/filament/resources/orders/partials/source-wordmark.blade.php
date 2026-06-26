@php
    $source = strtolower(trim((string) ($marketplace ?? '')));
    $source = match (true) {
        $source === '', in_array($source, ['sklep', 'local', 'store', 'storefront', 'shop'], true) => 'local',
        str_starts_with($source, 'allegro') => 'allegro',
        str_starts_with($source, 'ovoko') => 'ovoko',
        str_starts_with($source, 'ebay') => 'ebay',
        default => $source,
    };
    $sourceClass = preg_replace('/[^a-z0-9_-]+/', '-', $source) ?: 'unknown';
@endphp

<span class="gps-order-source gps-order-source--{{ $sourceClass }}" aria-label="Źródło: {{ $source === 'local' ? 'Sklep' : $source }}">
    @if ($source === 'ebay')
        <span style="color:#0064D2">e</span><span style="color:#E53238">B</span><span style="color:#F5AF02">a</span><span style="color:#86B817">y</span>
    @elseif ($source === 'allegro')
        allegro
    @elseif ($source === 'ovoko')
        ovoko
    @elseif ($source === 'local')
        Sklep
    @else
        {{ ucfirst($source) }}
    @endif
</span>
