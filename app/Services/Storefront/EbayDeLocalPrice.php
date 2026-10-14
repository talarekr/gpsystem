<?php

namespace App\Services\Storefront;

use App\Models\MarketplaceListing;
use App\Models\Part;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Local records only. No account credentials, API clients, sync, or listing writes. */
class EbayDeLocalPrice
{
    private array $columns = [];

    public function available(): bool
    {
        return Schema::hasTable('marketplace_listings');
    }

    public function channel($query)
    {
        return $query->where(function ($q): void {
            $q->where(function ($q): void {
                $q->where('marketplace_listings.marketplace', 'ebay_de');
                if (Schema::hasTable('marketplace_accounts')) {
                    $q->whereNotExists(fn ($a) => $a->selectRaw('1')->from('marketplace_accounts')->whereColumn('marketplace_accounts.id', 'marketplace_listings.marketplace_account_id')->where('code', 'ebay_fr'));
                }
            })->orWhere(function ($q): void {
                $q->where('marketplace_listings.marketplace', 'ebay')->where(function ($q): void {
                    if (Schema::hasTable('marketplace_accounts')) {
                        $q->whereExists(fn ($a) => $a->selectRaw('1')->from('marketplace_accounts')
                            ->whereColumn('marketplace_accounts.id', 'marketplace_listings.marketplace_account_id')->where('code', 'ebay_de'));
                    }
                    // Unassigned legacy listings require an explicit DE URL, never a guessed default.
                    $q->orWhere(function ($q): void {
                        $q->whereNull('marketplace_listings.marketplace_account_id')->where(function ($q): void {
                            foreach (['https://www.ebay.de/%', 'https://ebay.de/%', 'http://www.ebay.de/%', 'http://ebay.de/%'] as $url) {
                                $q->orWhere('marketplace_listings.url', 'like', $url);
                            }
                        });
                    });
                });
            });
        });
    }

    public function active($query)
    {
        $query->whereRaw("LOWER(TRIM(marketplace_listings.status)) IN ('active', 'published', 'listed', 'live')")
            ->where('marketplace_listings.quantity', '>', 0);
        if ($this->hasColumn('sync_status')) {
            $query->where(fn ($q) => $q->whereNull('marketplace_listings.sync_status')->orWhereRaw("LOWER(TRIM(marketplace_listings.sync_status)) <> 'stale'"));
        }
        if ($this->hasColumn('not_seen_in_active_api_at')) {
            $query->whereNull('marketplace_listings.not_seen_in_active_api_at');
        }
        if ($this->hasColumn('last_api_status')) {
            $query->where(fn ($q) => $q->whereNull('marketplace_listings.last_api_status')
                ->orWhereRaw("LOWER(TRIM(marketplace_listings.last_api_status)) NOT IN ('ended', 'remote_ended', 'not_found', 'deleted', 'archived', 'unavailable')"));
        }

        if ($this->hasColumn('match_status')) {
            $query->where(fn ($q) => $q->whereNull('marketplace_listings.match_status')->orWhereRaw("LOWER(TRIM(marketplace_listings.match_status)) NOT IN ('unmatched', 'ambiguous', 'conflict', 'unlinked')"));
        }

        return $query;
    }

    public function priced($query)
    {
        return $query->whereRaw("UPPER(TRIM(marketplace_listings.currency)) = 'EUR'")
            ->where('marketplace_listings.price', '>=', 0.01)->where('marketplace_listings.price', '<=', 999999.99);
    }

    public function query()
    {
        return $this->priced($this->active($this->channel(MarketplaceListing::query())));
    }

    public function resolve(Part $part): array
    {
        $empty = ['cents' => null, 'source' => null, 'listing_ids' => [], 'reason' => 'missing_ebay_de_price'];
        if (! $this->available()) {
            return $empty;
        }
        $listings = $part->relationLoaded('fr_de_price_listings') ? $part->getRelation('fr_de_price_listings')
            : $this->query()->where('marketplace_listings.part_id', $part->id)->orderBy('marketplace_listings.id')->get(['marketplace_listings.id', 'part_id', 'price', 'currency']);
        $amounts = $listings->map(fn ($l) => (int) round((float) $l->price * 100, 0, PHP_ROUND_HALF_UP))->unique();
        if ($amounts->isEmpty()) {
            return $empty;
        }
        $ids = $listings->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($amounts->count() > 1) {
            return ['cents' => null, 'source' => null, 'listing_ids' => $ids, 'reason' => 'ambiguous_ebay_listing'];
        }

        return ['cents' => $amounts->first(), 'source' => 'marketplace_listings:'.$ids[0].'(ebay_de,EUR)',
            'listing_ids' => $ids, 'reason' => 'exact_match'];
    }

    public function needsNbp($parts): bool
    {
        $query = (clone $parts)->reorder()->setEagerLoads([])->where('parts.currency', 'PLN');
        if ($this->available()) {
            $query->whereNotExists($this->query()->whereColumn('marketplace_listings.part_id', 'parts.id')->selectRaw('1')->toBase());
        }

        return $query->exists();
    }

    public function preload($parts): void
    {
        if ($parts->isEmpty() || ! $this->available()) {
            return;
        }
        $byPart = $this->query()->whereIn('marketplace_listings.part_id', $parts->pluck('id'))
            ->orderBy('marketplace_listings.id')->get(['marketplace_listings.id', 'part_id', 'price', 'currency'])->groupBy('part_id');
        foreach ($parts as $part) {
            $part->setRelation('fr_de_price_listings', $byPart->get($part->id, collect()));
        }
    }

    /** Same channel/status/currency/range guards for global sorting and EUR bounds. */
    public function sqlExpression($partsQuery, float $rate, string $policy): string
    {
        if (! in_array($policy, ['existing_eur', 'catalog_eur', 'existing_eur_nbp'], true)) {
            return 'NULL';
        }
        $expression = 'CASE ';
        if ($this->available()) {
            $de = $this->priced($this->active($this->channel(DB::table('marketplace_listings'))))
                ->selectRaw('part_id as de_part_id, COUNT(DISTINCT ROUND(price * 100, 0)) as de_price_count, MIN(ROUND(price * 100, 0)) as de_cents')->groupBy('part_id');
            $partsQuery->leftJoinSub($de, 'de_prices', fn ($join) => $join->on('de_prices.de_part_id', '=', 'parts.id'));
            $expression .= 'WHEN de_prices.de_price_count = 1 THEN de_prices.de_cents WHEN de_prices.de_price_count > 1 THEN NULL ';
        }
        $expression .= "WHEN parts.currency = 'EUR' AND parts.price BETWEEN 0.01 AND 999999.99 THEN ROUND(parts.price * 100, 0) ";
        if ($policy === 'existing_eur_nbp' && $rate > 0 && is_finite($rate)) {
            $converted = 'ROUND(ROUND(parts.price / '.sprintf('%.12F', $rate).', 2) * 100, 0)';
            $expression .= "WHEN parts.currency = 'PLN' AND {$converted} BETWEEN 1 AND 99999999 THEN {$converted} ";
        }

        return $expression.'ELSE NULL END';
    }

    private function hasColumn(string $column): bool
    {
        return $this->columns[$column] ??= Schema::hasColumn('marketplace_listings', $column);
    }
}
