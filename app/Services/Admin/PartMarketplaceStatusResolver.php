<?php

namespace App\Services\Admin;

use App\Models\MarketplaceListing;
use App\Models\Part;
use Illuminate\Support\Collection;

class PartMarketplaceStatusResolver
{
    /**
     * @return array<int, array{key: string, label: string, price: string, listed: bool, external_offer_id: ?string, url: ?string, title: string, note: ?string}>
     */
    public function rowsForPart(Part $part): array
    {
        $listings = $part->relationLoaded('marketplaceListings')
            ? $part->marketplaceListings
            : collect();

        $ovoko = $this->listedListing($listings, ['ovoko']);
        $ebayListings = $this->listedListings($listings, ['ebay_de', 'ebay_fr']);
        $ebay = $ebayListings->first();
        $ebayUrlListing = $ebayListings->first(fn (MarketplaceListing $listing): bool => $this->listingUrl($listing) !== null);
        $allegro = $this->allegroListing($listings);
        $allegroListed = $this->isActiveAllegroListing($allegro);

        $storefrontVisible = ! $part->needs_listing
            && ! in_array($part->status, ['sold', 'archived'], true)
            && (int) $part->quantity > 0;

        $ebayMarkets = $ebayListings
            ->pluck('marketplace')
            ->map(fn (string $marketplace): string => match ($marketplace) {
                'ebay_de' => 'DE',
                'ebay_fr' => 'FR',
                default => $marketplace,
            })
            ->unique()
            ->implode('/');

        return [
            $this->row('storefront', 'Sklep', $part->price, 'zł', $storefrontVisible, null, null, $storefrontVisible ? 'Widoczny w sklepie' : 'Niewidoczny w sklepie'),
            $this->row('allegro', 'Allegro', $part->allegro_price, 'zł', $allegroListed, $this->externalOfferId($allegro), $this->allegroUrl($allegro), $this->allegroTitle($allegro, $allegroListed)),
            $this->row('ovoko', 'Ovoko', $part->ovoko_price, 'zł', $ovoko !== null, $this->externalOfferId($ovoko), $this->listingUrl($ovoko), $ovoko ? 'Oferta Ovoko wystawiona lokalnie' : 'Brak lokalnej oferty Ovoko'),
            $this->row('ebay', 'eBay', $part->ebay_price, 'zł', $ebay !== null, $this->externalOfferId($ebay), $this->listingUrl($ebayUrlListing), $ebay ? 'Oferta eBay wystawiona lokalnie' : 'Brak lokalnej oferty eBay', $ebayMarkets ?: null),
        ];
    }

    /**
     * @return array{resolved_is_listed: bool, resolved_url: ?string, link_visible: bool, link_hidden_reason: ?string}
     */
    public function diagnosticsForPartChannel(Part $part, string $channel): array
    {
        $row = collect($this->rowsForPart($part))->firstWhere('key', $channel);
        $listed = (bool) ($row['listed'] ?? false);
        $url = $row['url'] ?? null;
        $linkVisible = $listed && filled($url);

        return [
            'resolved_is_listed' => $listed,
            'resolved_url' => $url,
            'link_visible' => $linkVisible,
            'link_hidden_reason' => $linkVisible ? null : $this->linkHiddenReason($listed, $url),
        ];
    }


    /**
     * @param Collection<int, MarketplaceListing> $listings
     */
    private function allegroListing(Collection $listings): ?MarketplaceListing
    {
        return $listings
            ->whereIn('marketplace', ['allegro', 'allegro_main'])
            ->filter(fn (MarketplaceListing $listing): bool => $this->externalOfferId($listing) !== null)
            ->sortByDesc(fn (MarketplaceListing $listing): int => $this->isActiveAllegroListing($listing) ? 1 : 0)
            ->first();
    }

    private function isActiveAllegroListing(?MarketplaceListing $listing): bool
    {
        if (! $listing || $this->externalOfferId($listing) === null) {
            return false;
        }

        if (in_array($listing->last_api_status, ['ended', 'inactive', 'deleted', 'archived', 'not_found', 'NOT_FOUND_IN_ACTIVE_API'], true)
            || in_array($listing->status, ['ended', 'inactive', 'deleted', 'archived', 'not_found', 'NOT_FOUND_IN_ACTIVE_API'], true)) {
            return false;
        }

        return in_array($listing->last_api_status, ['ACTIVE'], true)
            || in_array($listing->status, ['ACTIVE', 'published', 'publication_pending'], true);
    }

    private function allegroTitle(?MarketplaceListing $listing, bool $listed): string
    {
        if (! $listing || $this->externalOfferId($listing) === null) {
            return 'Brak lokalnej oferty Allegro';
        }

        if ($listed && $listing->status === 'publication_pending') {
            return 'Oferta Allegro została utworzona lokalnie; publikacja czeka na asynchroniczne potwierdzenie Allegro';
        }

        return $listed
            ? 'Oferta Allegro aktywna lub wystawiona lokalnie'
            : 'Oferta Allegro nie została znaleziona w ACTIVE API podczas ostatniego odświeżenia';
    }

    /**
     * @param Collection<int, MarketplaceListing> $listings
     * @param array<int, string> $marketplaces
     */
    private function listedListing(Collection $listings, array $marketplaces): ?MarketplaceListing
    {
        return $this->listedListings($listings, $marketplaces)->first();
    }

    /**
     * @param Collection<int, MarketplaceListing> $listings
     * @param array<int, string> $marketplaces
     * @return Collection<int, MarketplaceListing>
     */
    private function listedListings(Collection $listings, array $marketplaces): Collection
    {
        return $listings
            ->whereIn('marketplace', $marketplaces)
            ->filter(fn (MarketplaceListing $listing): bool => $this->externalOfferId($listing) !== null)
            ->values();
    }

    private function row(string $key, string $label, mixed $price, ?string $currency, bool $listed, ?string $externalOfferId, ?string $url, string $title, ?string $note = null): array
    {
        return compact('key', 'label', 'listed', 'externalOfferId', 'url', 'title', 'note') + [
            'price' => $this->formatPrice($price, $currency),
            'external_offer_id' => $externalOfferId,
        ];
    }

    private function formatPrice(mixed $price, ?string $currency = null): string
    {
        if (! is_numeric($price)) {
            return '—';
        }

        return number_format((float) $price, 2, ',', ' ').' '.($currency ?: 'zł');
    }

    private function externalOfferId(?MarketplaceListing $listing): ?string
    {
        foreach ([$listing?->external_offer_id, $listing?->external_listing_id] as $value) {
            $id = trim((string) ($value ?? ''));
            if ($id !== '') {
                return $id;
            }
        }

        return null;
    }

    private function listingUrl(?MarketplaceListing $listing): ?string
    {
        $url = trim((string) ($listing?->url ?? ''));

        return $url === '' ? null : $url;
    }

    private function linkHiddenReason(bool $listed, mixed $url): ?string
    {
        if (! $listed) {
            return 'not_listed';
        }

        if (! filled($url)) {
            return 'missing_marketplace_listings_url';
        }

        return null;
    }

    private function allegroUrl(?MarketplaceListing $listing): ?string
    {
        $offerId = $this->externalOfferId($listing);

        return $this->listingUrl($listing) ?: ($offerId ? 'https://allegro.pl/oferta/'.$offerId : null);
    }
}
