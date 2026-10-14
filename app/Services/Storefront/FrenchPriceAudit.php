<?php

namespace App\Services\Storefront;

use App\Models\JarekFrProduct;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Services\Marketplace\NbpExchangeRateService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;

/** Streams local data in chunks. Existing NBP cache only; never refreshes or calls a provider. */
class FrenchPriceAudit
{
    public const EXAMPLES = [
        'vw-t-roc-2-0tdi-katalizator-dpf-filtr-czastek-stalych-05l131705p-05l131601f' => 220000,
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
        if (Schema::hasTable('jarek_fr_products') && Schema::hasTable('jarek_gearboxes')) {
            foreach (JarekFrProduct::query()->active()->with('gearbox')->lazyById(200) as $product) {
                $gearbox = $product->gearbox;
                $price = FrenchPlnPricing::convert($gearbox?->price, $gearbox?->currency, FrenchPlnPricing::JAREK_MULTIPLIER, $this->cachedRate(), JarekFrPresentation::DELIVERY_CENTS);
                yield ['source_type' => 'jarek', 'source_id' => $gearbox?->id, 'part_id' => null, 'sku' => null, 'part_number' => null, 'slug' => null,
                    'parts_price' => null, 'parts_currency' => null, 'source_jarek_price_pln' => $gearbox?->price,
                    'source_currency' => $gearbox?->currency, 'applied_margin' => 50, 'applied_margin_steps' => [25, 20],
                    'price_pln_after_margin' => $price['price_pln_after_margin'], 'nbp_eur_rate' => $price['nbp_eur_rate'],
                    'final_fr_price_eur' => $price['cents'] === null ? null : $price['cents'] / 100, 'ebay_de_price' => null,
                    'price_difference_abs' => null, 'reason_code' => $price['reason'] ?? 'priced_from_source_pln', 'warnings' => []];
            }
        }
    }

    public function report(): array
    {
        $summary = ['total_checked' => 0, 'part_count' => 0, 'jarek_count' => 0, 'no_visible_fr_price_count' => 0, 'warning_count' => 0];
        $top = [];
        $jarek = [];
        foreach ($this->rows() as $row) {
            $summary['total_checked']++;
            $summary[$row['source_type'] === 'part' ? 'part_count' : 'jarek_count']++;
            $summary['no_visible_fr_price_count'] += $row['final_fr_price_eur'] === null ? 1 : 0;
            $summary['warning_count'] += $row['warnings'] !== [] ? 1 : 0;
            if ($row['source_type'] === 'part') {
                $top = $this->top($top, $row, 'price_difference_abs');
            } elseif (count($jarek) < 50) {
                $jarek[] = $row;
            }
        }
        $examples = [];
        foreach (self::EXAMPLES as $slug => $reportedPrice) {
            $part = Part::query()->where('slug', $slug)->first();
            $examples[] = ($part ? $this->chunk(collect([$part]))->first() : ['slug' => $slug, 'part_id' => null, 'reason_code' => 'part_not_found'])
                + ['url' => 'https://gpswiss.fr/produkt/'.$slug, 'reported_price_is_not_a_live_read' => true];
        }

        return ['generated_at' => now()->toISOString(), 'summary' => $summary, 'top_50_differences' => $top,
            'jarek_examples' => $jarek, 'examples' => $examples, 'nbp_cache' => $this->cachedRate(),
            'scope' => 'storefront-visible Part and active Jarek FR products', 'marketplace_calls' => false, 'writes' => false,
            'policy' => ['part' => 'parts.price PLN * 1.25 / NBP EUR', 'jarek' => 'jarek_gearboxes.price PLN * 1.50 / NBP EUR'],
            'limitations' => ['eBay.de is diagnostic only, never the pricing authority.', 'Warnings are heuristics, not proof of incorrect marketplace data.',
                'No NBP refresh; missing/invalid cache makes report prices unavailable. Runtime may use the existing NBP fetch.', 'Delivery is separate and unchanged.']];
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

        return $parts->map(function (Part $part) use ($deRows): array {
            $records = $deRows->get($part->id, collect());
            $price = $this->prices->resolve($part, $this->cachedRate());
            $de = $this->de->resolve($part);
            $cents = $price['cents'];
            $deCents = $de['cents'];
            $difference = $cents !== null && $deCents !== null ? abs($cents - $deCents) : null;
            $warnings = [];
            if ($de['reason'] === 'ambiguous_ebay_listing') {
                $warnings[] = 'ambiguous_local_ebay_de_prices';
            }
            if ($part->currency === 'PLN' && $records->contains(fn ($l) => strtoupper(trim((string) $l->currency)) === 'EUR'
                && abs((float) $l->price - (float) $part->price) < 0.005)) {
                $warnings[] = 'possible_pln_amount_labelled_eur';
            }
            if ($difference !== null && $cents > 0 && $difference / $cents >= 0.5) {
                $warnings[] = 'large_local_ebay_de_difference';
            }
            $snapshots = $records->map(fn ($l) => ['id' => $l->id, 'external_listing_id' => $l->external_listing_id,
                'price' => $l->price, 'currency' => $l->currency, 'status' => $l->status, 'quantity' => $l->quantity,
                'sync_status' => $l->sync_status, 'last_synced_at' => $l->last_synced_at?->toISOString(),
                'last_seen_at' => $l->last_seen_at?->toISOString(), 'last_api_status' => $l->last_api_status,
                'not_seen_in_active_api_at' => $l->not_seen_in_active_api_at?->toISOString(), 'snapshot_prices' => $this->snapshotPrices($l->raw_payload ?? [])])->all();

            return ['source_type' => 'part', 'source_id' => $part->id, 'source_jarek_price_pln' => null,
                'applied_margin' => 25, 'applied_margin_steps' => [25], 'price_pln_after_margin' => $price['price_pln_after_margin'],
                'nbp_eur_rate' => $price['nbp_eur_rate'], 'final_fr_price_eur' => $cents === null ? null : $cents / 100, 'warnings' => $warnings,
                'part_id' => $part->id, 'sku' => $part->sku, 'part_number' => $part->part_number, 'slug' => $part->slug,
                'parts_price' => $part->price, 'parts_currency' => $part->currency, 'ebay_price_pln' => $part->ebay_price,
                'gpswiss_fr_display_price' => $cents === null ? null : $cents / 100, 'gpswiss_fr_price_source' => $price['source'],
                'ebay_de_listing_id' => $de['listing_ids'][0] ?? $records->first()?->id, 'ebay_de_listing_ids' => $de['listing_ids'],
                'ebay_de_price' => $deCents === null ? null : $deCents / 100, 'ebay_de_currency' => $deCents !== null ? 'EUR' : $records->first()?->currency,
                'price_difference_abs' => $difference === null ? null : $difference / 100,
                'price_difference_percent' => $difference !== null && $deCents > 0 ? round(($cents - $deCents) / $deCents * 100, 2) : null,
                'reason_code' => $price['reason'] ?? 'priced_from_source_pln', 'de_listing_count' => $records->count(), 'local_listing_diagnostics' => $snapshots];
        });
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
