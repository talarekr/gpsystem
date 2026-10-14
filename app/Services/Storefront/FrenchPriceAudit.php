<?php

namespace App\Services\Storefront;

use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Services\Marketplace\NbpExchangeRateService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;

/** Streams local data in chunks. Existing NBP cache only; never refreshes or calls a provider. */
class FrenchPriceAudit
{
    public const EXAMPLES = [
        'audi-rs3-8y-dpf-katalizator-filtr-czastek-stalych-8s0131701f-8s0131765' => 147635,
        'a2904905000' => 348700,
    ];

    private ?array $rate = null;

    public function __construct(private FrenchPriceResolver $prices, private EbayDeLocalPrice $de, private NbpExchangeRateService $rates) {}

    public function rows(): \Generator
    {
        foreach (Part::query()->storefrontVisible()->orderBy('id')->lazyById(200)->chunk(200) as $parts) {
            yield from $this->chunk($parts);
        }
    }

    public function report(): array
    {
        $summary = ['total_checked' => 0, 'exact_match_count' => 0, 'mismatch_count' => 0,
            'missing_ebay_de_count' => 0, 'missing_price_count' => 0, 'ambiguous_count' => 0, 'no_visible_fr_price_count' => 0,
            'previous_policy_mismatch_count' => 0];
        $top = [];
        $oldTop = [];
        foreach ($this->rows() as $row) {
            $summary['total_checked']++;
            $summary['exact_match_count'] += $row['reason_code'] === 'exact_match' ? 1 : 0;
            $summary['mismatch_count'] += $row['price_difference_abs'] !== null && $row['price_difference_abs'] > 0 ? 1 : 0;
            $summary['missing_ebay_de_count'] += $row['de_listing_count'] === 0 ? 1 : 0;
            $summary['missing_price_count'] += $row['de_listing_count'] > 0 && $row['ebay_de_price'] === null ? 1 : 0;
            $summary['ambiguous_count'] += $row['reason_code'] === 'ambiguous_ebay_listing' ? 1 : 0;
            $summary['no_visible_fr_price_count'] += $row['gpswiss_fr_display_price'] === null ? 1 : 0;
            $summary['previous_policy_mismatch_count'] += $row['previous_price_difference_abs'] !== null && $row['previous_price_difference_abs'] > 0 ? 1 : 0;
            $top = $this->top($top, $row, 'price_difference_abs');
            $oldTop = $this->top($oldTop, $row, 'previous_price_difference_abs');
        }
        $examples = [];
        foreach (self::EXAMPLES as $slug => $reportedPrice) {
            $part = Part::query()->where('slug', $slug)->first();
            $row = $part ? $this->chunk(collect([$part]))->first() : ['slug' => $slug, 'part_id' => null, 'reason_code' => 'part_not_found'];
            $examples[] = $row + ['url' => 'https://gpswiss.fr/produkt/'.$slug,
                'user_reported_ebay_de_price' => $reportedPrice / 100, 'reported_price_is_not_a_live_read' => true];
        }

        return ['generated_at' => now()->toISOString(), 'summary' => $summary, 'top_50_differences' => $top,
            'top_50_previous_policy_differences' => $oldTop, 'examples' => $examples, 'nbp_cache' => $this->cachedRate(),
            'scope' => 'storefront-visible Part only; Jarek excluded', 'marketplace_calls' => false, 'writes' => false,
            'limitations' => ['Local ingested listings, not live eBay prices.', 'No NBP cache refresh; missing cache makes the PLN fallback unavailable in this report.',
                'Previous prices simulate the pre-fix resolver with the same existing cache.', 'No age cutoff is invented; explicit stale/not-seen/ended flags are excluded.']];
    }

    private function chunk($parts)
    {
        $this->de->preload($parts);
        $all = collect();
        $deRows = collect();
        if ($this->de->available()) {
            $query = MarketplaceListing::query()->whereIn('part_id', $parts->pluck('id'))->whereIn('marketplace', ['ebay', 'ebay_de', 'ebay_fr'])->orderBy('id');
            if (Schema::hasTable('marketplace_accounts')) {
                $query->with('account:id,code');
            }
            $all = $query->get()->groupBy('part_id');
            $deIds = $this->de->channel(MarketplaceListing::query())->whereIn('part_id', $parts->pluck('id'))->pluck('marketplace_listings.id')->flip();
            $deRows = $all->flatten(1)->filter(fn ($l) => $deIds->has($l->id))->groupBy('part_id');
        }

        return $parts->map(function (Part $part) use ($all, $deRows): array {
            $records = $deRows->get($part->id, collect());
            $price = $this->prices->resolve($part, $this->cachedRate());
            $de = $this->de->resolve($part);
            $old = $this->previous($part, $all->get($part->id, collect()));
            $cents = $price['cents'];
            $deCents = $de['cents'];
            $difference = $cents !== null && $deCents !== null ? abs($cents - $deCents) : null;
            $oldDifference = $old['cents'] !== null && $deCents !== null ? abs($old['cents'] - $deCents) : null;
            $reason = $this->reason($price, $de, $records, $difference);
            $snapshots = $records->map(fn ($l) => ['id' => $l->id, 'external_listing_id' => $l->external_listing_id,
                'price' => $l->price, 'currency' => $l->currency, 'status' => $l->status, 'quantity' => $l->quantity,
                'sync_status' => $l->sync_status, 'last_synced_at' => $l->last_synced_at?->toISOString(),
                'last_seen_at' => $l->last_seen_at?->toISOString(), 'last_api_status' => $l->last_api_status,
                'not_seen_in_active_api_at' => $l->not_seen_in_active_api_at?->toISOString(), 'snapshot_prices' => $this->snapshotPrices($l->raw_payload ?? [])])->all();

            return ['part_id' => $part->id, 'sku' => $part->sku, 'part_number' => $part->part_number, 'slug' => $part->slug,
                'parts_price' => $part->price, 'parts_currency' => $part->currency, 'ebay_price_pln' => $part->ebay_price,
                'gpswiss_fr_display_price' => $cents === null ? null : $cents / 100, 'gpswiss_fr_price_source' => $price['source'],
                'ebay_de_listing_id' => $de['listing_ids'][0] ?? $records->first()?->id, 'ebay_de_listing_ids' => $de['listing_ids'],
                'ebay_de_price' => $deCents === null ? null : $deCents / 100, 'ebay_de_currency' => $deCents !== null ? 'EUR' : $records->first()?->currency,
                'price_difference_abs' => $difference === null ? null : $difference / 100,
                'price_difference_percent' => $difference !== null && $deCents > 0 ? round(($cents - $deCents) / $deCents * 100, 2) : null,
                'reason_code' => $reason, 'de_listing_count' => $records->count(), 'local_listing_diagnostics' => $snapshots,
                'previous_policy_price' => $old['cents'] === null ? null : $old['cents'] / 100,
                'previous_policy_source' => $old['source'], 'previous_reason_code' => $this->reason($old, $de, $records, $oldDifference),
                'previous_price_difference_abs' => $oldDifference === null ? null : $oldDifference / 100];
        });
    }

    private function reason(array $price, array $de, $records, ?int $difference): string
    {
        if ($de['reason'] === 'ambiguous_ebay_listing') {
            return 'ambiguous_ebay_listing';
        }
        if ($records->isEmpty()) {
            return 'missing_ebay_de_listing';
        }
        if ($de['cents'] === null) {
            if ($records->contains(fn ($r) => $r->sync_status === 'stale' || $r->not_seen_in_active_api_at !== null
                || in_array(strtolower((string) $r->last_api_status), ['ended', 'remote_ended', 'not_found', 'deleted', 'archived', 'unavailable'], true))) {
                return 'stale_listing_price';
            }
            if ($records->every(fn ($r) => strtoupper(trim((string) $r->currency)) !== 'EUR')) {
                return 'currency_mismatch';
            }

            return 'missing_ebay_de_price';
        }
        if ($price['cents'] === null) {
            return 'no_visible_fr_price';
        }
        if ($difference === 0) {
            return 'exact_match';
        }
        $source = $price['source'] ?? '';

        return match (true) {
            str_contains($source, 'NBP') => 'fr_uses_nbp_fallback',
            $source === 'parts.price(EUR)' => 'fr_uses_part_price_eur',
            str_contains($source, 'ebay_fr') => 'fr_uses_ebay_fr_price',
            default => 'fr_uses_wrong_marketplace_price',
        };
    }

    /** Explain the old policy without invoking the old resolver's network fallback. */
    private function previous(Part $part, $all): array
    {
        $policy = config('storefront-fr-checkout.pricing_policy', 'existing_eur_nbp');
        if (! in_array($policy, ['existing_eur', 'catalog_eur', 'existing_eur_nbp'], true)) {
            return ['cents' => null, 'source' => null];
        }
        if ($part->currency === 'EUR' && (float) $part->price > 0) {
            return ['cents' => (int) round((float) $part->price * 100), 'source' => 'parts.price(EUR)'];
        }
        if ($policy === 'catalog_eur') {
            return ['cents' => null, 'source' => null];
        }
        $fr = $all->filter(fn ($l) => ($l->marketplace === 'ebay_fr' || ($l->marketplace === 'ebay' && ($l->relationLoaded('account') ? $l->getRelation('account')?->code : null) === 'ebay_fr'))
            && $l->currency === 'EUR' && (float) $l->price > 0 && (int) $l->quantity > 0
            && in_array($l->status, ['active', 'ACTIVE', 'published', 'listed'], true));
        $amounts = $fr->map(fn ($l) => (int) round((float) $l->price * 100))->unique();
        if ($amounts->count() > 1) {
            return ['cents' => null, 'source' => null];
        }
        if ($amounts->count() === 1) {
            return ['cents' => $amounts->first(), 'source' => 'marketplace_listings:'.$fr->first()->id.'(ebay_fr,EUR)'];
        }
        $rate = $this->cachedRate();

        return ['cents' => $policy === 'existing_eur_nbp' && $part->currency === 'PLN' && ($rate['ok'] ?? false)
            ? (int) round(round((float) $part->price / $rate['rate'], 2, PHP_ROUND_HALF_UP) * 100) : null,
            'source' => $policy === 'existing_eur_nbp' && $part->currency === 'PLN' && ($rate['ok'] ?? false) ? 'parts.price(PLN)->NBP_TABLE_A(EUR)' : null];
    }

    private function cachedRate(): array
    {
        return $this->rate ??= $this->rates->cachedEurPlnForPreview();
    }

    private function top(array $rows, array $row, string $key): array
    {
        if ($row[$key] === null || $row[$key] <= 0) {
            return $rows;
        }
        $rows[] = $row;
        usort($rows, fn ($a, $b) => [$b[$key], $a['part_id']] <=> [$a[$key], $b['part_id']]);

        return array_slice($rows, 0, 50);
    }

    private function snapshotPrices(array $raw): array
    {
        $prices = [];
        foreach (['price', 'pricingSummary.price', 'offers.0.pricingSummary.price', 'sellingStatus.currentPrice', 'buyItNowPrice'] as $path) {
            $value = Arr::get($raw, $path);
            $number = is_array($value) ? ($value['value'] ?? $value['__value__'] ?? null) : $value;
            if (is_numeric($number) && is_finite((float) $number)) {
                $prices[] = ['path' => $path, 'value' => (float) $number,
                    'currency' => is_array($value) ? ($value['currency'] ?? $value['currencyId'] ?? $value['@currencyID'] ?? null) : null];
            }
        }

        return $prices;
    }
}
