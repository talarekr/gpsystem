<?php

namespace App\Services\Storefront;

use App\Models\JarekFrProduct;
use App\Models\JarekGearbox;
use App\Services\Marketplace\NbpExchangeRateService;
use Illuminate\Support\Facades\Schema;

class JarekFrPresentation
{
    public const DELIVERY_CENTS = 13000;

    public function __construct(private NbpExchangeRateService $rates) {}

    public function rate(): array
    {
        $request = request();
        $key = 'storefront.fr.jarek.eur_rate';
        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, $this->rates->currentEurPln());
        }

        return $request->attributes->get($key);
    }

    public function forProduct(JarekFrProduct $product): array
    {
        $data = $this->forGearbox($product->gearbox, $this->rate());
        $data['can_buy'] = $data['can_buy'] && $product->is_active;

        return $data + [
            'id' => $product->id, 'jarek_fr_product_id' => $product->id, 'is_active' => $product->is_active,
            'url' => 'https://gpswiss.fr'.route('storefront.jarek.show', $product->id, false),
        ];
    }

    /** Shared pricing/readiness used by dry-run, public UI, cart and checkout. */
    public function forGearbox(?JarekGearbox $gearbox, array $rate): array
    {
        $source = $gearbox?->translationSource() ?? ['title' => '', 'description' => ''];
        $translation = $gearbox && Schema::hasTable('jarek_gearbox_translations') ? $gearbox->translationForLocale('fr') : null;
        $ready = $translation?->isReady();
        $frTitle = $ready && trim((string) $translation->title) !== '';
        $frDescription = $ready && trim((string) $translation->description) !== '';
        $images = $gearbox ? $this->images($gearbox) : [];
        $blockers = [];
        $base = is_numeric($gearbox?->price) && is_finite((float) $gearbox->price) && (float) $gearbox->price > 0 ? (float) $gearbox->price : null;
        if (! $base) {
            $blockers[] = 'no_price';
        }
        if ($gearbox?->currency !== 'PLN') {
            $blockers[] = 'non_pln_price';
        }
        if (trim($source['title']) === '') {
            $blockers[] = 'no_title';
        }
        if ($images === []) {
            $blockers[] = 'no_images';
        }
        if (! ($rate['ok'] ?? false) || ! is_numeric($rate['rate'] ?? null) || ! is_finite((float) $rate['rate']) || $rate['rate'] <= 0) {
            $blockers[] = 'no_nbp_rate';
        }
        $marked = $base !== null && $gearbox?->currency === 'PLN' ? round($base * 1.25, 2, PHP_ROUND_HALF_UP) : null;
        $eur = $marked !== null && ! in_array('no_nbp_rate', $blockers, true) ? round($marked / (float) $rate['rate'], 2, PHP_ROUND_HALF_UP) : null;
        $cents = $eur !== null ? (int) round($eur * 100) : null;
        if ($cents !== null && ($cents < 1 || $cents + self::DELIVERY_CENTS > 99999999)) {
            $blockers[] = 'price_out_of_range';
            $cents = null;
        }

        return [
            'source_type' => 'jarek_gearbox', 'source_id' => $gearbox?->id, 'sku' => 'JAREK-'.($gearbox?->allegro_offer_id ?: $gearbox?->id),
            'title' => $frTitle ? $translation->title : $source['title'], 'description' => $frDescription ? $translation->description : $source['description'],
            'uses_fr_translation' => $frTitle && ($frDescription || $source['description'] === ''),
            'uses_fallback_translation' => ! $frTitle || (! $frDescription && $source['description'] !== ''),
            'images' => $images, 'cents' => $cents, 'currency' => 'EUR', 'quantity' => 1,
            'base_price_pln' => $base, 'price_after_markup_pln' => $marked, 'nbp_rate' => $rate,
            'delivery' => ['available' => true, 'amount_cents' => self::DELIVERY_CENTS, 'currency' => 'EUR', 'source' => 'approved_jarek_fr_fixed_130'],
            'can_buy' => $blockers === [], 'blockers' => $blockers,
            'price_source' => 'jarek_gearboxes.price(PLN)*1.25->NBP_TABLE_A(EUR)',
        ];
    }

    private function images(JarekGearbox $gearbox): array
    {
        return array_values(array_unique(array_filter($gearbox->displayImageUrls(),
            fn ($url) => is_string($url) && filter_var($url, FILTER_VALIDATE_URL)
                && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true))));
    }
}
