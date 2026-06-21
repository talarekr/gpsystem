@php
    $part = (isset($part) && $part instanceof \App\Models\Part) ? $part : null;

    if (! $part && isset($getRecord) && is_callable($getRecord)) {
        $candidate = $getRecord();
        $part = $candidate instanceof \App\Models\Part ? $candidate : null;
    } elseif (isset($record) && $record instanceof \App\Models\Part) {
        $part = $record;
    }

    $listings = ($part instanceof \App\Models\Part && $part->relationLoaded('marketplaceListings')) ? $part->marketplaceListings : collect();
    $formatPrice = function ($price, ?string $currency = null): string {
        if (! is_numeric($price)) {
            return '—';
        }

        return number_format((float) $price, 2, ',', ' ').' '.($currency ?: 'zł');
    };
    $hasConflict = fn ($items): bool => $items->contains(fn ($listing): bool => in_array($listing->sync_status, ['conflict'], true) || in_array($listing->match_status, ['conflict'], true) || $listing->status === 'conflict');
    $isMapped = fn ($items): bool => $items->isNotEmpty() && ! $hasConflict($items);
    $state = function ($items) use ($hasConflict, $isMapped): array {
        if ($hasConflict($items)) {
            return ['!', 'gps-admin-channel__state--warn', 'Konflikt mapowania'];
        }

        if ($isMapped($items)) {
            return ['✓', 'gps-admin-channel__state--ok', 'Wystawione / zmapowane'];
        }

        return ['—', 'gps-admin-channel__state--empty', 'Nie wystawione'];
    };

    $rows = [];

    if ($part instanceof \App\Models\Part) {
        $storefrontVisible = ! $part->needs_listing && ! in_array($part->status, ['sold', 'archived'], true) && (int) $part->quantity > 0;
        $ovoko = $listings->where('marketplace', 'ovoko');
        $ebay = $listings->whereIn('marketplace', ['ebay_de', 'ebay_fr']);
        $allegro = $listings->where('marketplace', 'allegro_main');

        $ovokoPrice = optional($ovoko->first(fn ($listing) => is_numeric($listing->price)))->price;
        $ebayPrice = is_numeric($part->ebay_price) ? $part->ebay_price : (is_numeric($part->price) ? ((float) $part->price * 1.25) : null);
        $ebayCalc = ! is_numeric($part->ebay_price) && is_numeric($part->price);
        $allegroPrice = is_numeric($part->allegro_price) ? $part->allegro_price : $part->price;
        $ebayMarkets = $ebay->pluck('marketplace')->map(fn ($marketplace) => match ($marketplace) { 'ebay_de' => 'DE', 'ebay_fr' => 'FR', default => $marketplace })->unique()->implode('/');

        $rows = [
            ['Sklep', $formatPrice($part->price, 'zł'), $storefrontVisible ? ['✓', 'gps-admin-channel__state--ok', 'Widoczny w sklepie'] : ['—', 'gps-admin-channel__state--empty', 'Niewidoczny w sklepie'], null],
            ['Ovoko', $formatPrice($ovokoPrice, 'zł'), $state($ovoko), null],
            ['eBay', $formatPrice($ebayPrice, 'zł'), $state($ebay), $ebayCalc ? 'calc' : ($ebayMarkets ?: null)],
            ['Allegro', $formatPrice($allegroPrice, 'zł'), $state($allegro), null],
        ];
    }
@endphp

<div class="gps-admin-channels">
    @if (! $part)
        <div class="gps-admin-channel">
            <span class="gps-admin-channel__name">—</span>
            <span class="gps-admin-channel__price">—</span>
            <span class="gps-admin-channel__state gps-admin-channel__state--empty" title="Brak rekordu">—</span>
        </div>
    @else
        @foreach ($rows as [$label, $price, $status, $note])
            <div class="gps-admin-channel">
                <span class="gps-admin-channel__name">{{ $label }}:</span>
                <span class="gps-admin-channel__price">{{ $price }} @if ($note)<span class="gps-admin-channel__calc">{{ $note }}</span>@endif</span>
                <span class="gps-admin-channel__state {{ $status[1] }}" title="{{ $status[2] }}">{{ $status[0] }}</span>
            </div>
        @endforeach
    @endif
</div>
