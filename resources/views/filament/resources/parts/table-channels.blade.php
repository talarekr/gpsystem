@php
    $listings = $record->relationLoaded('marketplaceListings') ? $record->marketplaceListings : collect();
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

    $storefrontVisible = ! $record->needs_listing && ! in_array($record->status, ['sold', 'archived'], true) && (int) $record->quantity > 0;
    $ovoko = $listings->where('marketplace', 'ovoko');
    $ebay = $listings->whereIn('marketplace', ['ebay_de', 'ebay_fr']);
    $allegro = $listings->where('marketplace', 'allegro_main');

    $ovokoPrice = optional($ovoko->first(fn ($listing) => is_numeric($listing->price)))->price;
    $ebayPrice = is_numeric($record->ebay_price) ? $record->ebay_price : (is_numeric($record->price) ? ((float) $record->price * 1.25) : null);
    $ebayCalc = ! is_numeric($record->ebay_price) && is_numeric($record->price);
    $allegroPrice = is_numeric($record->allegro_price) ? $record->allegro_price : $record->price;
    $ebayMarkets = $ebay->pluck('marketplace')->map(fn ($marketplace) => match ($marketplace) { 'ebay_de' => 'DE', 'ebay_fr' => 'FR', default => $marketplace })->unique()->implode('/');

    $rows = [
        ['Sklep', $formatPrice($record->price, 'zł'), $storefrontVisible ? ['✓', 'gps-admin-channel__state--ok', 'Widoczny w sklepie'] : ['—', 'gps-admin-channel__state--empty', 'Niewidoczny w sklepie'], null],
        ['Ovoko', $formatPrice($ovokoPrice, 'zł'), $state($ovoko), null],
        ['eBay', $formatPrice($ebayPrice, 'zł'), $state($ebay), $ebayCalc ? 'calc' : ($ebayMarkets ?: null)],
        ['Allegro', $formatPrice($allegroPrice, 'zł'), $state($allegro), null],
    ];
@endphp

<div class="gps-admin-channels">
    @foreach ($rows as [$label, $price, $status, $note])
        <div class="gps-admin-channel">
            <span class="gps-admin-channel__name">{{ $label }}:</span>
            <span class="gps-admin-channel__price">{{ $price }} @if ($note)<span class="gps-admin-channel__calc">{{ $note }}</span>@endif</span>
            <span class="gps-admin-channel__state {{ $status[1] }}" title="{{ $status[2] }}">{{ $status[0] }}</span>
        </div>
    @endforeach
</div>
