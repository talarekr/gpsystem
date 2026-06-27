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
            $this->row('ovoko', 'Ovoko', $part->ovoko_price, 'zł', $ovoko !== null, $this->externalOfferId($ovoko), $this->listingUrl($ovoko), $ovoko ? 'Oferta Ovoko wystawiona lokalnie' : 'Brak lokalnej oferty Ovoko'),
            $this->row('ebay', 'eBay', $part->ebay_price, 'zł', $ebay !== null, $this->externalOfferId($ebay), $this->listingUrl($ebayUrlListing), $ebay ? 'Oferta eBay wystawiona lokalnie' : 'Brak lokalnej oferty eBay', $ebayMarkets ?: null),
            $this->row('allegro', 'Allegro', $part->allegro_price, 'zł', $allegroListed, $this->externalOfferId($allegro), $this->allegroUrl($allegro), $this->allegroTitle($allegro, $allegroListed)),
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

        return $listing->last_api_status === 'ACTIVE' || $listing->status === 'ACTIVE';
    }

    private function allegroTitle(?MarketplaceListing $listing, bool $listed): string
    {
        if (! $listing || $this->externalOfferId($listing) === null) {
            return 'Brak lokalnej oferty Allegro';
        }

        return $listed
            ? 'Oferta Allegro aktywna według ostatniego odświeżenia API'
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
        $id = trim((string) ($listing?->external_offer_id ?? ''));

        return $id === '' ? null : $id;
    }

    private function listingUrl(?MarketplaceListing $listing): ?string
    {
        $url = trim((string) ($listing?->url ?? ''));

        return $url === '' ? null : $url;
    }

    private function allegroUrl(?MarketplaceListing $listing): ?string
    {
        $offerId = $this->externalOfferId($listing);

        return $offerId ? 'https://allegro.pl/oferta/'.$offerId : null;
    }
}
