<?php

namespace App\Services\Admin;

use App\Models\MarketplaceListing;
use App\Models\Part;
use Illuminate\Support\Collection;

class PartMarketplaceStatusResolver
{
    /**
     * @return array<int, array{key: string, label: string, price: string, listed: bool, is_active: bool, has_link: bool, display_icon: string, icon: string, reason: string, external_offer_id: ?string, url: ?string, title: string, note: ?string}>
     */
    public function rowsForPart(Part $part): array
    {
        $listings = $part->relationLoaded('marketplaceListings')
            ? $part->marketplaceListings
            : $part->loadMissing('marketplaceListings')->marketplaceListings;

        $partSold = $this->isSold($part);
        $partAvailable = ! $partSold && $part->status === 'ready' && (int) $part->quantity > 0;

        $allegro = $this->preferredListing($listings, ['allegro', 'allegro_main'], 'allegro', $partAvailable);
        $ovoko = $this->preferredListing($listings, ['ovoko'], 'ovoko', $partAvailable);
        $ebayListings = $this->mappedListings($listings, ['ebay_de', 'ebay']);
        $ebay = $this->preferredEbayListing($ebayListings, $partAvailable);

        $allegroState = $this->channelState($part, $allegro, 'allegro');
        $ovokoState = $this->channelState($part, $ovoko, 'ovoko');
        $ebayState = $this->channelState($part, $ebay, 'ebay');

        $storefrontVisible = ! $part->needs_listing
            && ! in_array($part->status, ['sold', 'archived'], true)
            && (int) $part->quantity > 0;

        $ebayMarkets = $ebayListings
            ->pluck('marketplace')
            ->map(fn (string $marketplace): string => match ($marketplace) {
                'ebay_de' => 'DE',
                'ebay' => 'DE',
                default => $marketplace,
            })
            ->unique()
            ->implode('/');

        return [
            $this->row('storefront', 'Sklep', $part->price, 'zł', $storefrontVisible, null, null, $storefrontVisible ? 'Widoczny w sklepie' : 'Niewidoczny w sklepie'),
            $this->row('allegro', 'Allegro', $part->allegro_price, 'zł', $allegroState['is_active'], $this->externalOfferId($allegro), $this->allegroUrl($allegro), $this->channelTitle('Allegro', $allegroState), null, $allegroState),
            $this->row('ovoko', 'Ovoko', $part->ovoko_price ?? $ovoko?->price, $this->currencyLabel($ovoko?->currency), $ovokoState['is_active'], $this->externalOfferId($ovoko), $this->ovokoUrl($ovoko, $part), $this->channelTitle('Ovoko', $ovokoState), null, $ovokoState),
            $this->row('ebay', 'eBay', $part->ebay_price, 'zł', $ebayState['is_active'], $this->externalOfferId($ebay), $this->ebayUrl($ebay), $this->channelTitle('eBay', $ebayState), $ebayMarkets ?: null, $ebayState),
        ];
    }

    /**
     * @return array{resolved_is_listed: bool, resolved_url: ?string, link_visible: bool, link_hidden_reason: ?string, has_link: bool, is_active: bool, icon: string, display_icon: string, reason: ?string}
     */
    public function diagnosticsForPartChannel(Part $part, string $channel): array
    {
        $row = collect($this->rowsForPart($part))->firstWhere('key', $channel);
        $listed = (bool) ($row['listed'] ?? false);
        $url = $row['url'] ?? null;
        $linkVisible = filled($url);

        return [
            'resolved_is_listed' => $listed,
            'resolved_url' => $url,
            'link_visible' => $linkVisible,
            'link_hidden_reason' => $linkVisible ? null : $this->linkHiddenReason($listed, $url),
            'has_link' => (bool) ($row['has_link'] ?? false),
            'is_active' => (bool) ($row['is_active'] ?? false),
            'icon' => $row['icon'] ?? ($listed ? 'check' : 'x'),
            'display_icon' => $row['display_icon'] ?? ($listed ? '✓' : '✕'),
            'reason' => $row['reason'] ?? null,
        ];
    }


    /**
     * @param Collection<int, MarketplaceListing> $listings
     */
    private function isSold(Part $part): bool
    {
        return $part->status === 'sold'
            || $part->adminLocalAvailability() === 'sold';
    }

    /**
     * @param Collection<int, MarketplaceListing> $listings
     * @param array<int, string> $marketplaces
     */
    private function preferredListing(Collection $listings, array $marketplaces, string $channel, bool $partAvailable): ?MarketplaceListing
    {
        return $this->mappedListings($listings, $marketplaces)
            ->sortByDesc(fn (MarketplaceListing $listing): int => $this->channelStateForAvailablePart($listing, $channel, $partAvailable)['is_active'] ? 1 : 0)
            ->first();
    }

    /**
     * @param Collection<int, MarketplaceListing> $ebayListings
     */
    private function preferredEbayListing(Collection $ebayListings, bool $partAvailable): ?MarketplaceListing
    {
        return $ebayListings
            ->sortByDesc(fn (MarketplaceListing $listing): int => $this->ebayListingPriority($listing, $partAvailable))
            ->first();
    }

    private function ebayListingPriority(MarketplaceListing $listing, bool $partAvailable): int
    {
        if ($this->channelStateForAvailablePart($listing, 'ebay', $partAvailable)['is_active']) {
            return 3000000 + (int) $listing->id;
        }

        if (! $this->isEndedOrStaleEbayListing($listing)) {
            return 2000000 + (int) $listing->id;
        }

        return 1000000 + (int) $listing->id;
    }

    /**
     * @param Collection<int, MarketplaceListing> $listings
     * @param array<int, string> $marketplaces
     * @return Collection<int, MarketplaceListing>
     */
    private function mappedListings(Collection $listings, array $marketplaces): Collection
    {
        return $listings
            ->whereIn('marketplace', $marketplaces)
            ->filter(fn (MarketplaceListing $listing): bool => $this->externalOfferId($listing) !== null || $this->listingUrl($listing) !== null)
            ->values();
    }

    /**
     * @return array{is_active: bool, reason: string}
     */
    private function channelState(Part $part, ?MarketplaceListing $listing, string $channel): array
    {
        if ($this->isSold($part) || (int) $part->quantity <= 0) {
            return ['is_active' => false, 'reason' => 'part_sold_or_zero_quantity'];
        }

        if ($part->status !== 'ready') {
            return ['is_active' => false, 'reason' => 'part_not_ready'];
        }

        return $this->channelStateForAvailablePart($listing, $channel, true);
    }

    /**
     * @return array{is_active: bool, reason: string}
     */
    private function channelStateForAvailablePart(?MarketplaceListing $listing, string $channel, bool $partAvailable): array
    {
        if (! $partAvailable) {
            return ['is_active' => false, 'reason' => 'part_not_available'];
        }

        if (! $listing || ! $this->hasListingReference($listing, $channel)) {
            return ['is_active' => false, 'reason' => 'missing_listing'];
        }

        if ($this->hasBlockingError($listing)) {
            return ['is_active' => false, 'reason' => 'blocking_sync_error'];
        }

        $status = strtolower((string) $listing->status);
        $apiStatus = strtolower((string) $listing->last_api_status);

        return match ($channel) {
            'allegro' => in_array($status, ['active'], true) && ! in_array($apiStatus, ['error', 'failed'], true)
                ? ['is_active' => true, 'reason' => 'allegro_active']
                : ['is_active' => false, 'reason' => 'allegro_not_active'],
            'ovoko' => $this->isActiveOvokoListing($listing)
                ? ['is_active' => true, 'reason' => 'ovoko_active']
                : ['is_active' => false, 'reason' => 'ovoko_not_active'],
            'ebay' => $this->isEndedOrStaleEbayListing($listing)
                ? ['is_active' => false, 'reason' => $this->ebayEndDateIsPast($listing) ? 'ebay_end_date_in_past' : 'ebay_ended_stale']
                : ($this->isActiveEbayListing($listing)
                    ? ['is_active' => true, 'reason' => 'ebay_active_with_inventory']
                    : ['is_active' => false, 'reason' => 'ebay_not_active_or_no_inventory']),
            default => ['is_active' => false, 'reason' => 'unknown_channel'],
        };
    }

    private function isActiveOvokoListing(MarketplaceListing $listing): bool
    {
        $activeStatuses = ['active', 'published', 'in_stock', 'in-stock', 'for_sale', 'for-sale'];
        $status = strtolower((string) $listing->status);
        $syncStatus = strtolower((string) $listing->sync_status);
        $matchStatus = strtolower((string) $listing->match_status);

        return in_array($status, $activeStatuses, true)
            || in_array($syncStatus, $activeStatuses, true)
            || ($status === 'imported' && $syncStatus === 'mapped' && $matchStatus === 'confirmed');
    }

    private function isEndedOrStaleEbayListing(MarketplaceListing $listing): bool
    {
        $status = strtolower((string) $listing->status);
        $apiStatus = strtolower((string) $listing->last_api_status);

        return $this->ebayEndDateIsPast($listing)
            || in_array($status, ['ended', 'inactive', 'deleted', 'archived', 'not_found', 'unavailable'], true)
            || in_array($apiStatus, ['ended', 'inactive', 'deleted', 'archived', 'not_found', 'unavailable'], true)
            || $listing->not_seen_in_active_api_at !== null;
    }

    private function isActiveEbayListing(MarketplaceListing $listing): bool
    {
        $status = strtolower((string) $listing->status);
        $apiStatus = strtolower((string) $listing->last_api_status);

        if ($this->isEndedOrStaleEbayListing($listing)) {
            return false;
        }

        if (! in_array($status, ['active', 'published', 'live'], true)) {
            return false;
        }

        if (in_array($apiStatus, ['ended', 'inactive', 'deleted', 'archived', 'not_found', 'unavailable', 'failed', 'error'], true)) {
            return false;
        }

        if ($listing->not_seen_in_active_api_at !== null) {
            return false;
        }

        return ($listing->quantity === null || (int) $listing->quantity > 0);
    }

    private function ebayEndDateIsPast(MarketplaceListing $listing): bool
    {
        $endDate = data_get($listing->raw_payload, 'itemEndDate')
            ?? data_get($listing->raw_payload, 'item_end_date')
            ?? data_get($listing->raw_payload, 'api.end_date')
            ?? data_get($listing->raw_payload, 'response_summary.itemEndDate');

        return filled($endDate)
            && strtotime((string) $endDate) !== false
            && strtotime((string) $endDate) < now()->timestamp;
    }

    private function hasListingReference(MarketplaceListing $listing, string $channel): bool
    {
        if ($this->externalOfferId($listing) !== null) {
            return true;
        }

        return in_array($channel, ['ovoko', 'ebay'], true) && $this->listingUrl($listing) !== null;
    }

    private function hasBlockingError(MarketplaceListing $listing): bool
    {
        return filled($listing->last_error)
            || in_array(strtolower((string) $listing->sync_status), ['error', 'failed', 'sync_error', 'relist_error'], true)
            || in_array(strtolower((string) $listing->last_api_status), ['error', 'failed', 'sync_error'], true);
    }

    /**
     * @param array{is_active: bool, reason: string, has_link?: bool} $state
     */
    private function channelTitle(string $label, array $state): string
    {
        return $state['is_active']
            ? 'Oferta '.$label.' aktywna'
            : 'Oferta '.$label.' nieaktywna: '.$state['reason'];
    }

    private function row(string $key, string $label, mixed $price, ?string $currency, bool $listed, ?string $externalOfferId, ?string $url, string $title, ?string $note = null, ?array $state = null): array
    {
        $isActive = $state['is_active'] ?? $listed;
        $hasLink = filled($url);
        $displayIcon = $isActive ? '✓' : '✕';

        return compact('key', 'label', 'listed', 'externalOfferId', 'url', 'title', 'note') + [
            'price' => $this->formatPrice($price, $currency),
            'external_offer_id' => $externalOfferId,
            'has_link' => $hasLink,
            'is_active' => $isActive,
            'display_icon' => $displayIcon,
            'icon' => $isActive ? 'check' : 'x',
            'reason' => $state['reason'] ?? ($isActive ? 'active' : 'inactive'),
        ];
    }

    private function formatPrice(mixed $price, ?string $currency = null): string
    {
        if (! is_numeric($price)) {
            return '—';
        }

        return number_format((float) $price, 2, ',', ' ').' '.($currency ?: 'zł');
    }

    private function currencyLabel(?string $currency): string
    {
        return strtoupper((string) $currency) === 'PLN' || blank($currency) ? 'zł' : (string) $currency;
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

    private function ovokoUrl(?MarketplaceListing $listing, Part $part): ?string
    {
        $offerId = $this->externalOfferId($listing);

        return $this->listingUrl($listing) ?: ($offerId ? 'https://ovoko.pl/czesci-samochodowe/hgf'.$offerId.'-'.$this->ovokoSlug($part) : null);
    }

    private function ovokoSlug(Part $part): string
    {
        $base = trim((string) (($part->oem_number ?: $part->part_number ?: $part->sku ?: '').' '.$part->name));
        $slug = str($base)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', '-')->trim('-')->toString();

        return $slug !== '' ? $slug : 'czesc';
    }

    private function allegroUrl(?MarketplaceListing $listing): ?string
    {
        $offerId = $this->externalOfferId($listing);

        return $this->listingUrl($listing) ?: ($offerId ? 'https://allegro.pl/oferta/'.$offerId : null);
    }

    private function ebayUrl(?MarketplaceListing $listing): ?string
    {
        $offerId = $this->externalOfferId($listing);

        return $this->listingUrl($listing) ?: ($offerId ? 'https://www.ebay.de/itm/'.$offerId : null);
    }
}
