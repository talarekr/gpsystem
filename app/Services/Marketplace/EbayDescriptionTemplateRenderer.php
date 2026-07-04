<?php

namespace App\Services\Marketplace;

use App\Models\Part;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;

class EbayDescriptionTemplateRenderer
{
    private const CHANNELS = ['ebay_de', 'ebay_fr'];

    private const ASSETS = [
        'icon_shipping' => 'icon-shipping.png',
        'icon_returns' => 'icon-returns.png',
        'icon_packaging' => 'icon-packaging.png',
        'icon_original' => 'icon-original.png',
        'europe_map' => 'europe-map.png',
        'dhl_logo' => 'dhl-logo.png',
        'dpd_logo' => 'dpd-logo.png',
    ];

    public function isAvailable(string $channel): bool
    {
        return in_array($channel, self::CHANNELS, true) && view()->exists('marketplace.ebay.description-template');
    }

    /** @param array<string, mixed> $data */
    public function render(string $channel, Part $part, array $data = []): string
    {
        $labels = $this->labelsForChannel($channel);

        return view('marketplace.ebay.description-template', [
            'labels' => $labels,
            'assets' => $this->assetUrls(),
            'title' => (string) ($data['title'] ?? $part->name ?? ''),
            'descriptionBlock' => new HtmlString($this->descriptionBlock($part, $data)),
            'showSpecifications' => (bool) ($data['show_specifications'] ?? true),
            'specificationRows' => new HtmlString($this->specificationRows($channel, $part, $data)),
            'sameVehicleCta' => new HtmlString($this->sameVehicleCta($data)),
            'translationFallbackNotice' => (string) ($data['translation_fallback_notice'] ?? ''),
        ])->render();
    }

    /** @return array<string, string> */
    public function labelsForChannel(string $channel): array
    {
        return match ($channel) {
            'ebay_fr' => [
                'shipping' => 'Livraison rapide dans le monde entier', 'returns' => 'Retour sous 30 jours', 'packaging' => 'Emballage sécurisé', 'original' => 'Pièce d’origine 100 %', 'description' => 'Description', 'specifications' => 'Informations détaillées', 'europe_delivery_title' => 'Nous livrons dans toute l’Europe', 'europe_delivery_text' => 'Nous expédions vers tous les pays européens – rapidement, de manière fiable et sécurisée.', 'delivery_time' => 'Délai de livraison 2–5 jours', 'trust_title' => 'Achetez en toute confiance', 'trust_subtitle' => 'Pièces d’occasion vérifiées | Contrôlées avec soin | Emballées professionnellement', 'not_specified' => 'Non indiqué',
                'part_number' => 'Numéro de pièce', 'oem_code' => 'Code fabricant / OEM', 'manufacturer' => 'Marque', 'vehicle_model' => 'Modèle du véhicule', 'year' => 'Année de production', 'engine' => 'Moteur', 'steering_side' => 'Côté du volant', 'condition' => 'État / Qualité', 'model_variant' => 'Variante du modèle', 'first_registration_year' => 'Première immatriculation', 'mileage_km' => 'Kilométrage', 'fuel_type' => 'Type de carburant', 'engine_power_kw' => 'Puissance moteur', 'engine_capacity_cm3' => 'Cylindrée', 'engine_code' => 'Code moteur', 'drivetrain' => 'Transmission', 'gearbox_type' => 'Type de boîte de vitesses', 'gearbox_code' => 'Code de boîte de vitesses', 'body_type' => 'Type de carrosserie', 'color_code' => 'Code couleur', 'color' => 'Couleur', 'interior' => 'Intérieur',
            ],
            default => [
                'shipping' => 'Schneller weltweiter Versand', 'returns' => '30 Tage Rückgabe', 'packaging' => 'Sichere Verpackung', 'original' => '100% Originalteil', 'description' => 'Beschreibung', 'specifications' => 'Spezifikationen', 'europe_delivery_title' => 'Wir liefern in ganz Europa', 'europe_delivery_text' => 'Wir versenden in alle europäischen Länder – schnell, zuverlässig und sicher.', 'delivery_time' => 'Lieferzeit 2–5 Tage', 'trust_title' => 'Kaufen Sie mit Vertrauen', 'trust_subtitle' => 'Geprüfte gebrauchte Teile | Sorgfältig kontrolliert | Professionell verpackt', 'not_specified' => 'Nicht angegeben',
                'part_number' => 'Teilenummer', 'oem_code' => 'Hersteller-/OEM-Code', 'manufacturer' => 'Hersteller / Marke', 'vehicle_model' => 'Fahrzeugmodell', 'year' => 'Baujahr', 'engine' => 'Motor', 'steering_side' => 'Lenkradseite', 'condition' => 'Zustand / Qualität', 'model_variant' => 'Modellvariante', 'first_registration_year' => 'Erstzulassung', 'mileage_km' => 'Kilometerstand', 'fuel_type' => 'Kraftstoffart', 'engine_power_kw' => 'Motorleistung', 'engine_capacity_cm3' => 'Hubraum', 'engine_code' => 'Motorcode', 'drivetrain' => 'Antrieb', 'gearbox_type' => 'Getriebeart', 'gearbox_code' => 'Getriebecode', 'body_type' => 'Karosserietyp', 'color_code' => 'Farbcode', 'color' => 'Farbe', 'interior' => 'Innenraum',
            ],
        };
    }

    public function assetUrl(string $filename): string
    {
        $baseUrl = $this->assetBaseUrl();

        return rtrim($baseUrl, '/').'/'.ltrim($filename, '/');
    }

    private function assetBaseUrl(): string
    {
        $configured = trim((string) config('product-hub.ebay.template_asset_base_url', ''));
        $scheme = strtolower((string) parse_url($configured, PHP_URL_SCHEME));
        $host = (string) parse_url($configured, PHP_URL_HOST);

        return $configured !== '' && $scheme === 'https' && $host !== ''
            ? rtrim($configured, '/')
            : 'https://gpswiss.pl/ebay-template/assets';
    }

    /** @return array<string, string> */
    public function assetUrls(): array
    {
        return collect(self::ASSETS)->mapWithKeys(function (string $filename, string $key): array {
            $url = $this->assetUrl($filename);
            $sourcePath = is_file(storage_path('app/imports/ebay-template/'.$filename))
                ? storage_path('app/imports/ebay-template/'.$filename)
                : storage_path('app/imports/'.$filename);
            Log::info('ebay_template_asset_url_generated', [
                'asset_key' => $key,
                'filename' => $filename,
                'source_path' => $sourcePath,
                'generated_url' => $url,
                'absolute_https' => strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' && filled(parse_url($url, PHP_URL_HOST)),
                'source_exists' => is_file($sourcePath),
            ]);

            return [$key => $url];
        })->all();
    }

    /** @param array<string, mixed> $data */
    private function descriptionBlock(Part $part, array $data): string
    {
        $value = (string) ($data['description'] ?? $data['description_block'] ?? $part->description ?? $part->short_description ?? '');
        $plain = trim(strip_tags($value));
        $coreReturnNotice = trim(strip_tags((string) ($data['core_return_notice'] ?? '')));

        $description = $plain === ''
            ? ''
            : '<p style="margin:0;color:#1f2937;font-size:16px;line-height:1.7;text-align:center;">'.e($plain).'</p>';

        if ($coreReturnNotice !== '') {
            $description .= '<p style="margin:14px 0 0;color:#111827;font-size:16px;line-height:1.7;text-align:center;font-weight:700;">'.e($coreReturnNotice).'</p>';
        }

        return $description;
    }

    private function specificationRows(string $channel, Part $part, array $data): string
    {
        $labels = $this->labelsForChannel($channel);
        $vehicle = $this->vehicleAttributes($part, $data);
        $specs = [
            $labels['part_number'] => $data['part_number'] ?? $part->part_number ?? null,
            $labels['oem_code'] => $data['oem_code'] ?? $part->oem_number ?? $part->manufacturer_code ?? null,
            $labels['manufacturer'] => $data['make'] ?? $data['manufacturer'] ?? $vehicle['make'] ?? null,
            $labels['vehicle_model'] => $data['model'] ?? $data['vehicle_model'] ?? $vehicle['model'] ?? null,
            $labels['model_variant'] => $data['model_variant'] ?? $vehicle['model_variant'] ?? null,
            $labels['year'] => $data['production_year'] ?? $data['year'] ?? $vehicle['production_year'] ?? null,
            $labels['first_registration_year'] => $data['first_registration_year'] ?? $vehicle['first_registration_year'] ?? null,
            $labels['steering_side'] => $data['steering_side'] ?? $vehicle['steering_side'] ?? null,
            $labels['mileage_km'] => $this->withUnit($data['mileage_km'] ?? $vehicle['mileage_km'] ?? null, 'km'),
            $labels['fuel_type'] => $data['fuel_type'] ?? $vehicle['fuel_type'] ?? null,
            $labels['engine_power_kw'] => $this->withUnit($data['engine_power_kw'] ?? $vehicle['engine_power_kw'] ?? null, 'kW'),
            $labels['engine_capacity_cm3'] => $this->withUnit($data['engine_capacity_cm3'] ?? $vehicle['engine_capacity_cm3'] ?? null, 'cm³'),
            $labels['engine_code'] => $data['engine_code'] ?? $vehicle['engine_code'] ?? null,
            $labels['drivetrain'] => $data['drivetrain'] ?? $vehicle['drivetrain'] ?? null,
            $labels['gearbox_type'] => $data['gearbox_type'] ?? $vehicle['gearbox_type'] ?? null,
            $labels['gearbox_code'] => $data['gearbox_code'] ?? $vehicle['gearbox_code'] ?? null,
            $labels['body_type'] => $data['body_type'] ?? $vehicle['body_type'] ?? null,
            $labels['color_code'] => $data['color_code'] ?? $vehicle['color_code'] ?? null,
            $labels['color'] => $data['color'] ?? $vehicle['color'] ?? null,
            $labels['interior'] => $data['interior'] ?? $vehicle['interior'] ?? null,
            $labels['condition'] => $data['condition'] ?? $part->condition_notes ?? null,
        ];

        $rows = '';
        foreach ($specs as $label => $value) {
            if (blank($value)) continue;
            $rows .= $this->specificationRow($label, $this->localizedValue($channel, (string) $value));
        }

        return $rows !== '' ? $rows : $this->specificationRow($labels['not_specified'], $labels['not_specified']);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function vehicleAttributes(Part $part, array $data): array
    {
        $vehicle = $part->car_id && $part->car
            ? $part->car->only(['make','model','model_variant','production_year','first_registration_year','steering_side','mileage_km','fuel_type','engine_power_kw','engine_capacity_cm3','engine_code','drivetrain','gearbox_type','gearbox_code','body_type','color_code','color','interior'])
            : (is_array($part->vehicle_snapshot ?? null) ? $part->vehicle_snapshot : []);

        foreach ($data as $key => $value) {
            if (array_key_exists($key, $vehicle)) {
                $vehicle[$key] = $value;
            }
        }

        return $vehicle;
    }

    private function withUnit(mixed $value, string $unit): ?string
    {
        if (blank($value)) return null;
        $text = trim((string) $value);
        return str_contains($text, $unit) ? $text : $text.' '.$unit;
    }

    private function localizedValue(string $channel, string $value): string
    {
        $map = [
            'ebay_de' => ['Benzyna' => 'Benzin', 'Szary' => 'Grau', 'Lewa strona' => 'Linkslenker', 'po lewej' => 'Linkslenker', 'Automatyczny' => 'Automatik', 'Używany' => 'Gebraucht'],
            'ebay_fr' => ['Benzyna' => 'Essence', 'Szary' => 'Gris', 'Lewa strona' => 'Volant à gauche', 'po lewej' => 'Volant à gauche', 'Automatyczny' => 'Automatique', 'Używany' => 'Occasion'],
        ];

        return $map[$channel][$value] ?? $value;
    }

    private function specificationRow(string $label, string $value): string
    {
        return '<tr><td align="center" style="width:42%;padding:13px 15px;border-top:1px solid #e5e7eb;background:#f8fafc;color:#06275d;font-weight:800;line-height:1.45;text-align:center;">'.e($label).'</td><td align="center" style="padding:13px 15px;border-top:1px solid #e5e7eb;color:#111827;line-height:1.45;text-align:center;">'.e($value).'</td></tr>';
    }

    /** @param array<string, mixed> $data */
    private function sameVehicleCta(array $data): string
    {
        return filled($data['same_vehicle_cta_html'] ?? null) ? (string) $data['same_vehicle_cta_html'] : '';
    }
}
