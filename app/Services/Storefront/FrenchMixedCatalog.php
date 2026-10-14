<?php

namespace App\Services\Storefront;

use App\Models\JarekFrProduct;
use App\Models\Part;
use App\Models\PartCategory;
use App\Support\StorefrontContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** One grid and one page sequence; hydrate images/translations only for the selected page. */
class FrenchMixedCatalog
{
    public const CATEGORY_PATH = 'uklad-napedowy/skrzynie-biegow-i-inne-elementy/manualna-6-biegowa-skrzynia-biegow';

    public function category(): ?PartCategory
    {
        return PartCategory::query()->visibleForPublic()
            ->where(fn ($q) => $q->where('source_system', 'woo')->orWhereNull('source_system'))
            ->where('full_slug_path', self::CATEGORY_PATH)->first();
    }

    public function includesCategory(?array $categoryIds): bool
    {
        $category = $this->category();

        return $category && ($categoryIds === null || in_array((int) $category->id, $categoryIds, true));
    }

    public function paginate(Builder $parts, Request $request, ?array $categoryIds = null, int $perPage = 60): LengthAwarePaginator
    {
        abort_unless(StorefrontContext::isFrench($request), 404);
        $needsPrice = in_array($request->input('sort'), ['price_asc', 'price_desc'], true)
            || collect(['price_from', 'price_to', 'price_min', 'price_max'])->contains(fn ($key) => $request->filled($key));
        $target = $this->category();
        $include = Schema::hasTable('jarek_fr_products') && $this->includesCategory($categoryIds);
        if ($request->filled('category')) {
            $needle = trim((string) $request->input('category'));
            $fr = $target?->translationForLocale('fr');
            $include = $include && ($needle === $target?->slug || mb_stripos($target?->storefrontNameForLocale('fr') ?? '', $needle) !== false
                || ($target && $needle === app(FrenchCategoryUrls::class)->segment($target)['slug']) || in_array($needle, (array) ($fr?->slug_history ?? []), true)
                || (str_contains($needle, '/') && app(CategoryTreeService::class)->forLocale('fr')->findByPublicPath($needle)?->id === $target?->id));
        }
        // No trustworthy make/model mapping exists for the supplier source. Never return unrelated boxes.
        foreach (['producer', 'model', 'vehicle_model'] as $filter) {
            $include = $include && ! $request->filled($filter);
        }
        $needsNbp = $needsPrice && ((config('storefront-fr-checkout.pricing_policy') === 'existing_eur_nbp' && app(EbayDeLocalPrice::class)->needsNbp($parts))
            || ($include && app(JarekFrCatalog::class)->query($request)->exists()));
        $rate = $needsNbp ? app(FrenchPriceResolver::class)->rateForCurrentRequest() : [];
        if ($needsNbp && $include) {
            request()->attributes->set('storefront.fr.jarek.eur_rate', $rate);
        }
        $eurRate = ($rate['ok'] ?? false) ? (float) ($rate['rate'] ?? 0) : 0;
        $partRows = (clone $parts)->reorder()->setEagerLoads([]);
        $partPrice = $needsPrice ? $this->partPriceExpression($partRows, $eurRate) : 'NULL';
        $partName = $this->translatedName('part_translations', 'part_id', 'parts.id', 'name', 'parts.name');
        $partRows = $partRows->selectRaw("parts.id as product_id, 'part' as source_type, {$partPrice} as sort_price, {$partName} as sort_name, parts.updated_at as sort_updated")->toBase();
        $union = $partRows;
        if ($include) {
            $jarek = app(JarekFrCatalog::class)->query($request)->setEagerLoads([])
                ->join('jarek_gearboxes', 'jarek_gearboxes.id', '=', 'jarek_fr_products.jarek_gearbox_id');
            $jarekPrice = $eurRate > 0 && is_finite($eurRate)
                ? 'ROUND(ROUND(ROUND(jarek_gearboxes.price * 1.25, 2) / '.sprintf('%.12F', $eurRate).', 2) * 100, 0)'
                : 'NULL';
            $jarekName = $this->translatedName('jarek_gearbox_translations', 'jarek_gearbox_id', 'jarek_gearboxes.id', 'title', 'jarek_gearboxes.title');
            $union->unionAll($jarek->selectRaw("jarek_fr_products.id as product_id, 'jarek' as source_type, {$jarekPrice} as sort_price, {$jarekName} as sort_name, jarek_fr_products.updated_at as sort_updated")->toBase());
        }
        $rows = DB::query()->fromSub($union, 'catalog_products');
        foreach (['price_from' => ['price_min', '>='], 'price_to' => ['price_max', '<=']] as $name => [$alias, $operator]) {
            $value = $request->input($name, $request->input($alias));
            if (is_numeric($value) && is_finite((float) $value) && (float) $value >= 0) {
                $rows->whereRaw('sort_price '.$operator.' CAST(? AS DECIMAL(20, 2))', [(float) $value * 100]);
            }
        }
        if (in_array($request->input('sort'), ['price_asc', 'price_desc'], true)) {
            $rows->orderByRaw('sort_price is null');
        }
        match ($request->input('sort')) {
            'price_asc' => $rows->orderBy('sort_price'),
            'price_desc' => $rows->orderByDesc('sort_price'),
            'name' => $rows->orderBy('sort_name'),
            default => $rows->orderByDesc('sort_updated'),
        };
        $page = $rows->orderBy('source_type')->orderByDesc('product_id')->paginate(max(1, min(60, $perPage)))->withQueryString();
        $ids = $page->getCollection();
        $ordinary = Part::query()->with(['images', 'category', 'car'])->withStorefrontTranslations('fr')
            ->whereIn('id', $ids->where('source_type', 'part')->pluck('product_id'))->get()->keyBy('id');
        app(EbayDeLocalPrice::class)->preload($ordinary);
        $supplier = $include ? JarekFrProduct::query()->active()->with('gearbox.translations')
            ->whereIn('id', $ids->where('source_type', 'jarek')->pluck('product_id'))->get()->keyBy('id') : collect();
        $page->setCollection($ids->map(fn ($row) => $row->source_type === 'part'
            ? $ordinary->get($row->product_id)
            : ($supplier->has($row->product_id) ? new JarekCatalogProduct($supplier[$row->product_id], $target) : null))->filter()->values());

        return $page;
    }

    private function partPriceExpression(Builder $query, float $rate): string
    {
        return app(EbayDeLocalPrice::class)->sqlExpression($query, $rate, config('storefront-fr-checkout.pricing_policy', 'existing_eur_nbp'));
    }

    private function translatedName(string $table, string $foreignKey, string $id, string $field, string $fallback): string
    {
        if (! Schema::hasTable($table)) {
            return $fallback;
        }

        return "COALESCE((SELECT {$field} FROM {$table} WHERE {$foreignKey} = {$id} AND locale = 'fr' AND status IN ('translated', 'reviewed') AND TRIM({$field}) <> '' LIMIT 1), {$fallback})";
    }
}
