<?php

namespace App\Services\Marketplace;

use App\Models\Part;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class EbayDescriptionTemplateService
{
    private const CHANNELS = ['ebay_de', 'ebay_fr'];
    private const TOKEN = 'gps_images_import_2026';
    private const PUBLIC_BASE_URL = 'https://gpswiss.pl/ebay-template';
    private const ASSETS = [
        'icon-shipping.png', 'icon-returns.png', 'icon-packaging.png', 'icon-original.png',
        'europe-map.png', 'dhl-logo.png', 'dpd-logo.png',
    ];

    public function __construct(private readonly GoogleTranslateService $translateService) {}

    public function token(): string { return self::TOKEN; }

    public function checkAssets(): array
    {
        $importDir = storage_path('app/imports/ebay-template');
        $assetCheckPaths = $this->assetCheckPaths();
        $publicDir = $assetCheckPaths[2];
        $missingImport = [];
        $missingPublic = [];
        $assetUrls = [];
        $assetFoundLocations = [];

        foreach (self::ASSETS as $asset) {
            $assetUrls[$asset] = self::PUBLIC_BASE_URL.'/'.$asset;
            $assetFoundLocations[$asset] = null;
            if (! is_file($importDir.DIRECTORY_SEPARATOR.$asset)) $missingImport[] = $asset;

            foreach ($assetCheckPaths as $assetCheckPath) {
                if (is_file($assetCheckPath.DIRECTORY_SEPARATOR.$asset)) {
                    $assetFoundLocations[$asset] = $assetCheckPath;
                    break;
                }
            }

            if ($assetFoundLocations[$asset] === null) $missingPublic[] = $asset;
        }

        return [
            'ok' => $missingImport === [] && $missingPublic === [],
            'import_path' => $importDir,
            'public_path' => $publicDir,
            'asset_check_paths' => $assetCheckPaths,
            'asset_found_locations' => $assetFoundLocations,
            'asset_public_urls' => $assetUrls,
            'missing_import_assets' => $missingImport,
            'missing_public_assets' => $missingPublic,
            'asset_urls' => $assetUrls,
            'blockers' => $missingPublic === [] ? [] : ['Some template assets are not publicly available yet. Run the sync endpoint after placing files in storage/app/imports/ebay-template.'],
            'warnings' => $missingImport === [] ? [] : ['Some source import assets are missing; sync cannot copy them until they are uploaded.'],
        ];
    }

    public function syncAssets(bool $live): array
    {
        $check = $this->checkAssets();
        $importDir = storage_path('app/imports/ebay-template');
        $publicDir = public_path('ebay-template');
        $wouldCopy = [];
        $copied = [];
        $warnings = $check['warnings'];
        $blockers = [];

        foreach (self::ASSETS as $asset) {
            $source = $importDir.DIRECTORY_SEPARATOR.$asset;
            $target = $publicDir.DIRECTORY_SEPARATOR.$asset;
            if (! is_file($source)) continue;
            $needsCopy = ! is_file($target) || filesize($source) !== filesize($target) || md5_file($source) !== md5_file($target);
            if ($needsCopy) {
                $row = ['asset' => $asset, 'from' => $source, 'to' => $target];
                $wouldCopy[] = $row;
                if ($live) {
                    File::ensureDirectoryExists($publicDir);
                    File::copy($source, $target);
                    $copied[] = $row;
                }
            }
        }

        return [
            'ok' => $blockers === [],
            'dry_run' => ! $live,
            'would_copy' => $wouldCopy,
            'copied' => $copied,
            'asset_check_after' => $live ? $this->checkAssets() : $check,
            'gpswiss_public_html_sync_reminder' => [
                'dry_run' => 'https://gpswiss.pl/tools/sync-gpswiss-public-html?token='.self::TOKEN.'&dry_run=1',
                'live' => 'https://gpswiss.pl/tools/sync-gpswiss-public-html?token='.self::TOKEN.'&dry_run=0',
            ],
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    public function preview(int $partId, string $channel): array
    {
        $part = Part::query()->with(['car', 'images'])->find($partId);
        $blockers = [];
        $warnings = [];
        if (! in_array($channel, self::CHANNELS, true)) $blockers[] = 'Unsupported channel. Allowed: ebay_de, ebay_fr.';
        if (! $part) $blockers[] = 'Part not found.';
        if ($blockers !== []) return $this->emptyPreview($partId, $channel, $blockers);

        $fields = $this->fields($part);
        $translated = [];
        $translationNeeded = [];
        $translatedSpecificationValues = [];
        $untranslatedTechnicalValues = [];
        if ($channel === 'ebay_fr') {
            foreach (['title', 'short_inventory_description', 'description', 'fits_to', 'part_type', 'version', 'placement'] as $key) {
                if (filled($fields[$key] ?? null)) $translationNeeded[] = $key;
            }
            $translated = $this->translatePreviewFields($fields, $translationNeeded, $warnings, $blockers);
            foreach ($translated as $key => $value) if (filled($value)) $fields[$key] = $value;
        }
        $fields['specification_rows'] = $this->specificationRows($fields, $channel, $translatedSpecificationValues, $untranslatedTechnicalValues, $warnings);

        $html = $this->renderHtml($fields, $channel);
        $assetCheck = $this->checkAssets();
        $warnings = array_values(array_unique(array_merge($warnings, $assetCheck['warnings'])));

        return [
            'ok' => $blockers === [],
            'part_id' => $partId,
            'channel' => $channel,
            'marketplace_id' => $channel === 'ebay_de' ? 'EBAY_DE' : 'EBAY_FR',
            'title' => $fields['title'],
            'short_inventory_description' => $fields['short_inventory_description'],
            'listing_description_html' => $html,
            'html_length' => Str::length($html),
            'asset_urls' => $assetCheck['asset_urls'],
            'asset_check_paths' => $assetCheck['asset_check_paths'],
            'asset_found_locations' => $assetCheck['asset_found_locations'],
            'asset_public_urls' => $assetCheck['asset_public_urls'],
            'missing_assets' => $assetCheck['missing_public_assets'],
            'used_fields' => array_keys(array_filter($fields, fn ($v) => filled($v))),
            'translation_needed_fields' => $translationNeeded,
            'translated_preview_fields' => $translated,
            'translated_specification_values' => $translatedSpecificationValues,
            'untranslated_technical_values' => $untranslatedTechnicalValues,
            'would_save' => false,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => $warnings,
        ];
    }

    public function validate(int $partId, string $channel): array
    {
        $preview = $this->preview($partId, $channel);
        $html = (string) ($preview['listing_description_html'] ?? '');
        preg_match_all('/<(script|iframe)\b/i', $html, $matches);
        preg_match_all('/src="(https?:\/\/[^"]+)"/i', $html, $assetMatches);
        $external = array_values(array_filter($assetMatches[1] ?? [], fn ($url) => ! str_starts_with($url, self::PUBLIC_BASE_URL)));
        $required = ['title' => filled($preview['title'] ?? null), 'description' => filled(strip_tags($html)), 'condition' => str_contains($html, 'Original') || str_contains($html, 'Originale') || str_contains($html, 'Originalteil') || str_contains($html, 'Gebrauchtes') || str_contains($html, 'occasion')];

        return [
            'ok' => ($preview['blockers'] ?? []) === [] && $matches[0] === [] && $external === [],
            'part_id' => $partId,
            'channel' => $channel,
            'html_length' => $preview['html_length'] ?? 0,
            'has_disallowed_scripts' => str_contains(mb_strtolower($html), '<script'),
            'has_iframe' => str_contains(mb_strtolower($html), '<iframe'),
            'has_external_assets' => $external !== [],
            'missing_assets' => $preview['missing_assets'] ?? [],
            'asset_check_paths' => $preview['asset_check_paths'] ?? [],
            'asset_found_locations' => $preview['asset_found_locations'] ?? [],
            'asset_public_urls' => $preview['asset_public_urls'] ?? [],
            'required_fields_present' => $required,
            'used_fields' => $preview['used_fields'] ?? [],
            'translation_needed_fields' => $preview['translation_needed_fields'] ?? [],
            'translated_specification_values' => $preview['translated_specification_values'] ?? [],
            'untranslated_technical_values' => $preview['untranslated_technical_values'] ?? [],
            'blockers' => $preview['blockers'] ?? [],
            'warnings' => array_values(array_unique(array_merge($preview['warnings'] ?? [], $external ? ['HTML references external assets outside https://gpswiss.pl/ebay-template/.'] : []))),
        ];
    }

    private function assetCheckPaths(): array
    {
        return [
            '/home/gpsystem/domains/gpswiss.pl/public_html/ebay-template',
            '/home/gpsystem/domains/gpsystem.thecamels.pl/public_html/ebay-template',
            public_path('ebay-template'),
        ];
    }

    private function fields(Part $part): array
    {
        $details = collect($part->storefrontDetails())->pluck('value', 'label');
        return [
            'title' => $this->clean($part->name) ?: 'Original used car part',
            'short_inventory_description' => Str::limit($this->clean($part->short_description ?: $part->description ?: $part->name) ?: 'Original used car part', 400, ''),
            'part_number' => $this->clean($part->part_number ?: $part->sku),
            'oem_numbers' => $this->clean($part->oem_number),
            'manufacturer' => $this->clean($part->manufacturer_code ?: $details->get('Producent / marka')),
            'fits_to' => trim(collect([$part->storefrontDetailValue('make'), $part->storefrontDetailValue('model'), $part->storefrontDetailValue('model_variant')])->filter()->implode(' ')),
            'part_type' => $this->clean($part->category?->name ?? null),
            'version' => $this->clean($part->storefrontDetailValue('model_variant')),
            'placement' => $this->clean(data_get($part->legacy_payload, 'attributes.placement') ?? data_get($part->legacy_payload, 'meta.placement')),
            'vehicle_make' => $this->clean($part->storefrontDetailValue('make')),
            'vehicle_model' => $this->clean($part->storefrontDetailValue('model')),
            'vehicle_year' => $this->clean($part->storefrontDetailValue('production_year')),
            'engine' => trim(collect([$part->storefrontDetailValue('engine_capacity_cm3'), $part->storefrontDetailValue('engine_code'), $part->storefrontDetailValue('fuel_type')])->filter()->implode(' / ')),
            'transmission' => $this->clean($part->storefrontDetailValue('gearbox_type')),
            'color_code' => $this->clean($part->storefrontDetailValue('color_code')),
            'engine_code' => $this->clean($part->storefrontDetailValue('engine_code')),
            'color' => $this->clean($part->storefrontDetailValue('color')),
            'drivetrain' => $this->clean($part->storefrontDetailValue('drivetrain')),
            'engine_power' => $this->clean($part->storefrontDetailValue('engine_power_kw')),
            'production_period' => $this->clean($part->storefrontDetailValue('production_period')),
            'engine_capacity' => $this->clean($part->storefrontDetailValue('engine_capacity_cm3')),
            'steering_side' => $this->clean($part->storefrontDetailValue('steering_side')),
            'mileage' => $this->clean($part->storefrontDetailValue('mileage_km')),
            'fuel_type' => $this->clean($part->storefrontDetailValue('fuel_type')),
            'condition_value_dynamic' => $this->clean($details->get('Stan')) ?: 'Original',
            'description' => $this->cleanMultiline($part->description ?: $part->short_description ?: $part->condition_notes) ?: 'Original used car part, checked before shipment.',
            'compatibility_list' => $this->compatibilityList($part),
            'same_vehicle_url' => 'https://gpswiss.pl/szukaj?car_id='.urlencode((string) ($part->car_id ?? '')),
        ];
    }

    private function renderHtml(array $f, string $channel): string
    {
        $texts = $channel === 'ebay_fr' ? $this->frTexts() : $this->deTexts();
        $template = $this->baseTemplateHtml();

        return strtr($template, $this->placeholderMap(array_merge($f, $texts)));
    }

    private function baseTemplateHtml(): string
    {
        return <<<'HTML'
<div style="max-width:980px;margin:0 auto;background:#ffffff;color:#1f2937;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.45;border:1px solid #d6e0ee;overflow:hidden;">
  <div style="background:#ffffff;border-bottom:1px solid #d6e0ee;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;table-layout:fixed;">
      <tr>
        <td width="25%" valign="middle" style="padding:12px 10px;color:#06275d;font-weight:700;font-size:12px;border-right:1px solid #d6e0ee;text-align:left;">
          <img src="https://gpswiss.pl/ebay-template/icon-shipping.png" alt="{{BENEFIT_SHIPPING_ALT}}" style="width:28px;height:auto;border:0;display:inline-block;vertical-align:middle;margin-right:6px;" />{{BENEFIT_SHIPPING}}
        </td>
        <td width="25%" valign="middle" style="padding:12px 10px;color:#06275d;font-weight:700;font-size:12px;border-right:1px solid #d6e0ee;text-align:left;">
          <img src="https://gpswiss.pl/ebay-template/icon-returns.png" alt="{{BENEFIT_RETURNS_ALT}}" style="width:28px;height:auto;border:0;display:inline-block;vertical-align:middle;margin-right:6px;" />{{BENEFIT_RETURNS}}
        </td>
        <td width="25%" valign="middle" style="padding:12px 10px;color:#06275d;font-weight:700;font-size:12px;border-right:1px solid #d6e0ee;text-align:left;">
          <img src="https://gpswiss.pl/ebay-template/icon-packaging.png" alt="{{BENEFIT_PACKAGING_ALT}}" style="width:28px;height:auto;border:0;display:inline-block;vertical-align:middle;margin-right:6px;" />{{BENEFIT_PACKAGING}}
        </td>
        <td width="25%" valign="middle" style="padding:12px 10px;color:#06275d;font-weight:700;font-size:12px;text-align:left;">
          <img src="https://gpswiss.pl/ebay-template/icon-original.png" alt="{{BENEFIT_ORIGINAL_ALT}}" style="width:28px;height:auto;border:0;display:inline-block;vertical-align:middle;margin-right:6px;" />{{BENEFIT_ORIGINAL}}
        </td>
      </tr>
    </table>
  </div>

  <div style="padding:22px 28px 28px;">
    <h1 style="margin:0 0 18px;color:#06275d;font-size:28px;line-height:1.1;font-weight:900;text-transform:uppercase;">{{TITLE}}</h1>

    <div style="border:1px solid #d6e0ee;background:#ffffff;margin:0 0 22px;overflow:hidden;">
      <div style="background:#06275d;color:#ffffff;text-align:center;padding:8px 12px;font-size:13px;font-weight:900;">{{DESCRIPTION_HEADING}}</div>
      <div style="background:#fbfdff;min-height:68px;padding:22px 28px;color:#1f2937;font-size:13px;line-height:1.7;text-align:center;">{{DESCRIPTION}}</div>
    </div>

    <div style="border:1px solid #d6e0ee;background:#ffffff;margin:0 0 20px;overflow:hidden;">
      <div style="background:#06275d;color:#ffffff;text-align:center;padding:8px 12px;font-size:13px;font-weight:900;">{{SPECIFICATIONS_HEADING}}</div>
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;table-layout:fixed;background:#ffffff;">
        {{SPECIFICATION_ROWS}}
      </table>
    </div>

    <div style="text-align:center;margin:0 0 24px;">
      <a href="{{SAME_VEHICLE_URL}}" style="display:inline-block;background:#005eea;color:#ffffff;text-decoration:none;font-weight:900;font-size:12px;padding:11px 22px;border-radius:3px;text-transform:uppercase;">{{SAME_VEHICLE_TEXT}}</a>
    </div>

    <div style="border:1px solid #d6e0ee;background:#ffffff;margin:0 0 18px;overflow:hidden;text-align:center;">
      <div style="padding:22px 28px 8px;">
        <h2 style="margin:0 0 8px;color:#06275d;font-size:24px;line-height:1.15;font-weight:900;">{{DELIVERY_HEADING}}</h2>
        <p style="margin:0 0 14px;color:#1f2937;font-size:13px;line-height:1.6;">{{DELIVERY_TEXT}}</p>
        <div style="display:inline-block;background:#eef6ff;border:1px solid #bcd3f0;color:#06275d;padding:9px 16px;font-size:12px;font-weight:900;">{{DELIVERY_TIME}}</div>
      </div>
      <div style="padding:0 18px 18px;text-align:left;">
        <img src="https://gpswiss.pl/ebay-template/europe-map.png" alt="{{EUROPE_MAP_ALT}}" style="max-width:100%;height:auto;border:0;display:block;margin:0;" />
      </div>
    </div>

    <div style="border:1px solid #d6e0ee;background:#ffffff;margin:0 0 28px;padding:14px 18px;">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;table-layout:fixed;">
        <tr>
          <td width="50%" align="center" style="padding:0 8px 0 0;"><div style="border:1px solid #d6e0ee;background:#ffffff;padding:13px 12px;"><img src="https://gpswiss.pl/ebay-template/dhl-logo.png" alt="DHL" style="max-width:130px;height:auto;border:0;display:block;margin:0 auto;" /></div></td>
          <td width="50%" align="center" style="padding:0 0 0 8px;"><div style="border:1px solid #d6e0ee;background:#ffffff;padding:13px 12px;"><img src="https://gpswiss.pl/ebay-template/dpd-logo.png" alt="DPD" style="max-width:130px;height:auto;border:0;display:block;margin:0 auto;" /></div></td>
        </tr>
      </table>
    </div>
  </div>

  <div style="background:#06275d;color:#ffffff;text-align:center;padding:18px 22px;">
    <div style="font-size:18px;font-weight:900;margin-bottom:4px;">{{FOOTER_HEADING}}</div>
    <div style="font-size:12px;color:#ffffff;font-weight:700;">{{FOOTER_TEXT}}</div>
  </div>
</div>
HTML;
    }

    /**
     * @return array<string, string>
     */
    private function deTexts(): array
    {
        return [
            'benefit_shipping_alt' => 'Schneller Versand',
            'benefit_shipping' => 'Schneller weltweiter Versand',
            'benefit_returns_alt' => '30 Tage Rückgabe',
            'benefit_returns' => '30 Tage Rückgabe',
            'benefit_packaging_alt' => 'Sichere Verpackung',
            'benefit_packaging' => 'Sichere Verpackung',
            'benefit_original_alt' => '100% Originalteil',
            'benefit_original' => '100% Originalteil',
            'condition_label' => 'Artikelzustand',
            'condition_value' => 'Gebraucht',
            'product_details_heading' => 'Produktdetails',
            'part_number_label' => 'Teilenummer',
            'oem_numbers_label' => 'OE/OEM Referenznummer',
            'manufacturer_label' => 'Hersteller',
            'fits_to_label' => 'Passt zu',
            'specifications_heading' => 'Spezifikationen',
            'part_type_label' => 'Teileart',
            'version_label' => 'Version',
            'condition_short_label' => 'Zustand',
            'placement_label' => 'Einbauposition',
            'vehicle_heading' => 'Fahrzeug / Spenderfahrzeug',
            'vehicle_make_label' => 'Marke',
            'vehicle_model_label' => 'Modell',
            'vehicle_year_label' => 'Baujahr',
            'engine_label' => 'Motor',
            'transmission_label' => 'Getriebe',
            'description_heading' => 'Beschreibung',
            'compatibility_heading' => 'Kompatibilität / Passgenauigkeit',
            'compatibility_note' => 'Bitte vergleichen Sie vor dem Kauf die Teilenummer und die Fotos. Das Teil passt möglicherweise nicht zu Fahrzeugen ohne passende Ausstattung oder Paketversion.',
            'same_vehicle_text' => 'MEHR TEILE VON DIESEM FAHRZEUG ANSEHEN',
            'delivery_heading' => 'Wir liefern in ganz Europa',
            'delivery_text' => 'Wir versenden in alle europäischen Länder – schnell, zuverlässig und sicher.',
            'delivery_time' => 'Lieferzeit 2–5 Tage',
            'europe_map_alt' => 'Lieferung in Europa',
            'footer_heading' => 'Kaufen Sie mit Vertrauen',
            'footer_text' => 'Geprüfte gebrauchte Teile | Sorgfältig kontrolliert | Professionell verpackt',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function frTexts(): array
    {
        return [
            'benefit_shipping_alt' => 'Expédition rapide',
            'benefit_shipping' => 'Expédition rapide dans le monde entier',
            'benefit_returns_alt' => 'Retour sous 30 jours',
            'benefit_returns' => 'Retour sous 30 jours',
            'benefit_packaging_alt' => 'Emballage sécurisé',
            'benefit_packaging' => 'Emballage sécurisé',
            'benefit_original_alt' => 'Pièce 100% d’origine',
            'benefit_original' => 'Pièce 100% d’origine',
            'condition_label' => 'État de l’article',
            'condition_value' => 'Occasion',
            'product_details_heading' => 'Détails du produit',
            'part_number_label' => 'Référence',
            'oem_numbers_label' => 'Référence OE/OEM',
            'manufacturer_label' => 'Fabricant',
            'fits_to_label' => 'Compatible avec',
            'specifications_heading' => 'Spécifications',
            'part_type_label' => 'Type de pièce',
            'version_label' => 'Version',
            'condition_short_label' => 'État',
            'placement_label' => 'Emplacement de montage',
            'vehicle_heading' => 'Véhicule / véhicule donneur',
            'vehicle_make_label' => 'Marque',
            'vehicle_model_label' => 'Modèle',
            'vehicle_year_label' => 'Année',
            'engine_label' => 'Moteur',
            'transmission_label' => 'Boîte de vitesses',
            'description_heading' => 'Description',
            'compatibility_heading' => 'Compatibilité / ajustement',
            'compatibility_note' => 'Veuillez comparer la référence et les photos avant l’achat. La pièce peut ne pas convenir aux véhicules sans équipement ou version de pack compatible.',
            'same_vehicle_text' => 'Voir plus de pièces de ce véhicule',
            'delivery_heading' => 'Nous livrons dans toute l’Europe',
            'delivery_text' => 'Nous expédions vers tous les pays européens – rapidement, de manière fiable et en toute sécurité.',
            'delivery_time' => 'Délai de livraison 2–5 jours',
            'europe_map_alt' => 'Livraison en Europe',
            'footer_heading' => 'Achetez en toute confiance',
            'footer_text' => 'Pièces d’occasion de qualité | Soigneusement vérifiées | Emballage professionnel',
        ];
    }

    private function specificationRows(array $fields, string $channel, array &$translatedSpecificationValues = [], array &$untranslatedTechnicalValues = [], array &$warnings = []): string
    {
        $labels = $channel === 'ebay_fr' ? [
            'color_code' => 'Code couleur', 'engine_code' => 'Code moteur', 'color' => 'Couleur', 'drivetrain' => 'Transmission',
            'engine_power' => 'Puissance moteur', 'vehicle_model' => 'Modèle', 'version' => 'Variante / version', 'part_number' => 'Référence',
            'production_period' => 'Période de production', 'engine_capacity' => 'Cylindrée', 'steering_side' => 'Position du volant',
            'manufacturer' => 'Fabricant', 'mileage' => 'Kilométrage', 'fuel_type' => 'Carburant', 'vehicle_year' => 'Année du véhicule',
            'condition_value_dynamic' => 'État de l’emballage', 'transmission' => 'Type de boîte de vitesses',
        ] : [
            'color_code' => 'Farbcode', 'engine_code' => 'Motorcode', 'color' => 'Farbe', 'drivetrain' => 'Antrieb',
            'engine_power' => 'Motorleistung', 'vehicle_model' => 'Modell', 'version' => 'Variante / Ausführung', 'part_number' => 'Teilenummer',
            'production_period' => 'Bauzeitraum', 'engine_capacity' => 'Hubraum', 'steering_side' => 'Lenkradposition',
            'manufacturer' => 'Hersteller', 'mileage' => 'Laufleistung', 'fuel_type' => 'Kraftstoffart', 'vehicle_year' => 'Baujahr des Fahrzeugs',
            'condition_value_dynamic' => 'Verpackungszustand', 'transmission' => 'Getriebeart',
        ];

        $rows = '';
        foreach ($labels as $key => $label) {
            $sourceValue = filled($fields[$key] ?? null) ? (string) $fields[$key] : '—';
            $value = $this->localizedSpecificationValue($key, $sourceValue, $channel, $translatedSpecificationValues, $untranslatedTechnicalValues, $warnings);
            $rows .= '<tr><td width="42%" style="padding:9px 14px;border-bottom:1px solid #d6e0ee;background:#f4f7fb;color:#06275d;font-weight:900;text-align:center;font-size:12px;">'.$this->e($label).'</td><td width="58%" style="padding:9px 14px;border-bottom:1px solid #d6e0ee;background:#ffffff;color:#1f2937;text-align:center;font-size:12px;">'.$this->e($value).'</td></tr>';
        }

        return $rows;
    }


    private function localizedSpecificationValue(string $key, string $value, string $channel, array &$translated, array &$technical, array &$warnings): string
    {
        if ($value === '—' || ! in_array($channel, self::CHANNELS, true) || $this->isTechnicalSpecificationValue($key, $value)) {
            if ($value !== '—') $technical[$key] = $value;
            return $value;
        }

        $dictionary = $this->specificationValueDictionary($key, $channel);
        $normalized = mb_strtolower(trim($value));
        if (isset($dictionary[$normalized])) {
            $translated[$key] = ['source' => $value, 'translated' => $dictionary[$normalized], 'provider' => 'local_dictionary'];
            return $dictionary[$normalized];
        }

        $translation = $this->translateService->translate($value, $channel === 'ebay_fr' ? 'fr' : 'de', 'pl');
        $warnings = array_merge($warnings, $translation['warnings'] ?? []);
        if (($translation['ok'] ?? false) && filled($translation['translated_text'] ?? null)) {
            $translated[$key] = ['source' => $value, 'translated' => $translation['translated_text'], 'provider' => 'google_translate'];
            return (string) $translation['translated_text'];
        }

        $warnings[] = "Specification value translation skipped for {$key}; source value shown without saving.";
        return $value;
    }

    private function specificationValueDictionary(string $key, string $channel): array
    {
        $common = [
            'diesel' => 'Diesel',
            'awd' => 'AWD',
        ];
        $de = $common + [
            'biały' => 'Weiß', 'czarny' => 'Schwarz', 'szary' => 'Grau', 'srebrny' => 'Silber',
            'używany / sprawdzony' => 'Gebraucht / geprüft', 'automatyczny' => 'Automatik', 'manualny' => 'Schaltgetriebe', 'benzyna' => 'Benzin',
            'lewa strona' => $key === 'steering_side' ? 'Linkslenker' : 'Linke Seite', 'prawa strona' => $key === 'steering_side' ? 'Rechtslenker' : 'Rechte Seite',
        ];
        $fr = $common + [
            'biały' => 'Blanc', 'czarny' => 'Noir', 'szary' => 'Gris', 'srebrny' => 'Argent',
            'używany / sprawdzony' => 'Occasion / vérifié', 'automatyczny' => 'Automatique', 'manualny' => 'Manuelle', 'benzyna' => 'Essence',
            'lewa strona' => $key === 'steering_side' ? 'Volant à gauche' : 'Côté gauche', 'prawa strona' => $key === 'steering_side' ? 'Volant à droite' : 'Côté droit',
        ];

        return $channel === 'ebay_fr' ? $fr : $de;
    }

    private function isTechnicalSpecificationValue(string $key, string $value): bool
    {
        if (in_array($key, ['part_number', 'oem_numbers', 'color_code', 'engine_code', 'engine_power', 'production_period', 'engine_capacity', 'vehicle_year', 'mileage'], true)) return true;
        if (preg_match('/\d/u', $value) === 1) return true;
        if (preg_match('/^[A-Z0-9][A-Z0-9 .\/-]{1,18}$/u', $value) === 1 && preg_match('/[a-ząćęłńóśźż]/u', $value) !== 1) return true;
        return false;
    }

    private function placeholderMap(array $values): array
    {
        $map = [];
        foreach ($values as $key => $value) {
            $placeholder = '{{'.Str::upper($key).'}}';
            $map[$placeholder] = match ($key) {
                'description' => $this->descriptionHtml((string) $value),
                'specification_rows' => $this->htmlValue((string) $value),
                default => $this->e((string) $value),
            };
        }

        return $map;
    }

    private function descriptionHtml(string $value): string
    {
        $lines = collect(preg_split('/\R/u', strip_tags($value)) ?: [])
            ->map(fn ($line) => trim(preg_replace('/\s+/u', ' ', $line) ?: ''))
            ->filter(fn ($line) => $line !== '')
            ->values();

        if ($lines->isEmpty()) return $this->e('—');

        $hasListMarkers = $lines->contains(fn ($line) => preg_match('/^(?:[-*•]|\d+[.)])\s+/u', $line) === 1);
        $shouldPreserveLines = $hasListMarkers || $lines->count() > 3;
        $description = $shouldPreserveLines ? $lines->implode("\n") : $lines->implode(' ');

        return nl2br($this->e($description), false);
    }

    private function compatibilityList(Part $part): string { return trim(collect([$part->storefrontDetailValue('make'), $part->storefrontDetailValue('model'), $part->storefrontDetailValue('production_year')])->filter()->implode(' ')); }
    private function clean(mixed $value): ?string { $value = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)) ?: ''); return $value === '' ? null : $value; }
    private function cleanMultiline(mixed $value): ?string { $value = trim(strip_tags((string) $value)); return $value === '' ? null : preg_replace('/[ \t]+/u', ' ', $value); }
    private function htmlValue(string $value): string { return $value; }
    private function e(mixed $value): string { return e((string) ($value ?: '—'), false); }

    private function translatePreviewFields(array $fields, array $keys, array &$warnings, array &$blockers): array
    {
        $out = [];
        foreach ($keys as $key) {
            $translation = $this->translateService->translate((string) $fields[$key], 'fr', 'pl');
            $warnings = array_merge($warnings, $translation['warnings'] ?? []);
            if (($translation['ok'] ?? false) && filled($translation['translated_text'] ?? null)) $out[$key] = $translation['translated_text'];
        }
        if ($out === [] && $keys !== []) $warnings[] = 'FR dynamic-field translation preview was skipped or unavailable; source values are shown without saving.';
        return $out;
    }

    private function emptyPreview(int $partId, string $channel, array $blockers): array
    {
        $assetCheck = $this->checkAssets();

        return ['ok' => false, 'part_id' => $partId, 'channel' => $channel, 'marketplace_id' => null, 'title' => null, 'short_inventory_description' => null, 'listing_description_html' => '', 'html_length' => 0, 'asset_urls' => $assetCheck['asset_urls'], 'asset_check_paths' => $assetCheck['asset_check_paths'], 'asset_found_locations' => $assetCheck['asset_found_locations'], 'asset_public_urls' => $assetCheck['asset_public_urls'], 'missing_assets' => $assetCheck['missing_public_assets'], 'used_fields' => [], 'translation_needed_fields' => [], 'translated_preview_fields' => [], 'translated_specification_values' => [], 'untranslated_technical_values' => [], 'would_save' => false, 'blockers' => $blockers, 'warnings' => []];
    }
}
