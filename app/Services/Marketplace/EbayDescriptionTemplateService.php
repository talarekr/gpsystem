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
        $publicDir = public_path('ebay-template');
        $missingImport = [];
        $missingPublic = [];
        $assetUrls = [];

        foreach (self::ASSETS as $asset) {
            $assetUrls[$asset] = self::PUBLIC_BASE_URL.'/'.$asset;
            if (! is_file($importDir.DIRECTORY_SEPARATOR.$asset)) $missingImport[] = $asset;
            if (! is_file($publicDir.DIRECTORY_SEPARATOR.$asset)) $missingPublic[] = $asset;
        }

        return [
            'ok' => $missingImport === [] && $missingPublic === [],
            'import_path' => $importDir,
            'public_path' => $publicDir,
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
                'dry_run' => 'https://gpsystem.thecamels.pl/tools/sync-gpswiss-public-html?token='.self::TOKEN.'&dry_run=1',
                'live' => 'https://gpsystem.thecamels.pl/tools/sync-gpswiss-public-html?token='.self::TOKEN.'&dry_run=0',
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
        if ($channel === 'ebay_fr') {
            foreach (['title', 'short_inventory_description', 'description', 'fits_to', 'part_type', 'version', 'placement'] as $key) {
                if (filled($fields[$key] ?? null)) $translationNeeded[] = $key;
            }
            $translated = $this->translatePreviewFields($fields, $translationNeeded, $warnings, $blockers);
            foreach ($translated as $key => $value) if (filled($value)) $fields[$key] = $value;
        }

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
            'missing_assets' => $assetCheck['missing_public_assets'],
            'used_fields' => array_keys(array_filter($fields, fn ($v) => filled($v))),
            'translation_needed_fields' => $translationNeeded,
            'translated_preview_fields' => $translated,
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
            'required_fields_present' => $required,
            'used_fields' => $preview['used_fields'] ?? [],
            'translation_needed_fields' => $preview['translation_needed_fields'] ?? [],
            'blockers' => $preview['blockers'] ?? [],
            'warnings' => array_values(array_unique(array_merge($preview['warnings'] ?? [], $external ? ['HTML references external assets outside https://gpswiss.pl/ebay-template/.'] : []))),
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
            'description' => $this->clean($part->description ?: $part->short_description ?: $part->condition_notes) ?: 'Original used car part, checked before shipment.',
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
<div style="max-width:980px;margin:0 auto;background:#ffffff;color:#1f2937;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.55;border:1px solid #dbe3ef;border-radius:10px;overflow:hidden;">

  <!-- Benefit bar -->
  <div style="background:#f8fbff;border-bottom:1px solid #dbe3ef;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">
      <tr>
        <td width="25%" align="center" style="padding:16px 12px;color:#06275d;font-weight:800;font-size:15px;border-right:1px solid #dbe3ef;">
          <img src="https://gpswiss.pl/ebay-template/icon-shipping.png" alt="{{BENEFIT_SHIPPING_ALT}}" style="width:36px;height:auto;border:0;display:inline-block;vertical-align:middle;margin-right:8px;" />
          {{BENEFIT_SHIPPING}}
        </td>
        <td width="25%" align="center" style="padding:16px 12px;color:#06275d;font-weight:800;font-size:15px;border-right:1px solid #dbe3ef;">
          <img src="https://gpswiss.pl/ebay-template/icon-returns.png" alt="{{BENEFIT_RETURNS_ALT}}" style="width:36px;height:auto;border:0;display:inline-block;vertical-align:middle;margin-right:8px;" />
          {{BENEFIT_RETURNS}}
        </td>
        <td width="25%" align="center" style="padding:16px 12px;color:#06275d;font-weight:800;font-size:15px;border-right:1px solid #dbe3ef;">
          <img src="https://gpswiss.pl/ebay-template/icon-packaging.png" alt="{{BENEFIT_PACKAGING_ALT}}" style="width:36px;height:auto;border:0;display:inline-block;vertical-align:middle;margin-right:8px;" />
          {{BENEFIT_PACKAGING}}
        </td>
        <td width="25%" align="center" style="padding:16px 12px;color:#06275d;font-weight:800;font-size:15px;">
          <img src="https://gpswiss.pl/ebay-template/icon-original.png" alt="{{BENEFIT_ORIGINAL_ALT}}" style="width:36px;height:auto;border:0;display:inline-block;vertical-align:middle;margin-right:8px;" />
          {{BENEFIT_ORIGINAL}}
        </td>
      </tr>
    </table>
  </div>

  <!-- Main content -->
  <div style="padding:26px 28px 10px;">

    <!-- Title -->
    <h1 style="margin:0 0 8px;color:#06275d;font-size:28px;line-height:1.25;font-weight:900;">
      {{TITLE}}
    </h1>

    <div style="margin:0 0 22px;color:#4b5563;font-size:15px;">
      <strong style="color:#06275d;">{{CONDITION_LABEL}}:</strong> {{CONDITION_VALUE}}
    </div>

    <!-- Product details row -->
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:0 0 20px;">
      <tr>
        <td width="50%" valign="top" style="padding:0 10px 0 0;">
          <div style="border:1px solid #dbe3ef;border-radius:8px;background:#ffffff;overflow:hidden;">
            <div style="background:#06275d;color:#ffffff;padding:12px 16px;font-size:17px;font-weight:900;">
              {{PRODUCT_DETAILS_HEADING}}
            </div>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">
              <tr>
                <td style="padding:10px 14px;border-bottom:1px solid #e5edf7;color:#64748b;font-weight:700;width:42%;">{{PART_NUMBER_LABEL}}</td>
                <td style="padding:10px 14px;border-bottom:1px solid #e5edf7;color:#111827;">{{PART_NUMBER}}</td>
              </tr>
              <tr>
                <td style="padding:10px 14px;border-bottom:1px solid #e5edf7;color:#64748b;font-weight:700;">{{OEM_NUMBERS_LABEL}}</td>
                <td style="padding:10px 14px;border-bottom:1px solid #e5edf7;color:#111827;">{{OEM_NUMBERS}}</td>
              </tr>
              <tr>
                <td style="padding:10px 14px;border-bottom:1px solid #e5edf7;color:#64748b;font-weight:700;">{{MANUFACTURER_LABEL}}</td>
                <td style="padding:10px 14px;border-bottom:1px solid #e5edf7;color:#111827;">{{MANUFACTURER}}</td>
              </tr>
              <tr>
                <td style="padding:10px 14px;color:#64748b;font-weight:700;">{{FITS_TO_LABEL}}</td>
                <td style="padding:10px 14px;color:#111827;">{{FITS_TO}}</td>
              </tr>
            </table>
          </div>
        </td>

        <td width="50%" valign="top" style="padding:0 0 0 10px;">
          <div style="border:1px solid #dbe3ef;border-radius:8px;background:#ffffff;overflow:hidden;">
            <div style="background:#f97316;color:#ffffff;padding:12px 16px;font-size:17px;font-weight:900;">
              {{SPECIFICATIONS_HEADING}}
            </div>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">
              <tr>
                <td style="padding:10px 14px;border-bottom:1px solid #e5edf7;color:#64748b;font-weight:700;width:42%;">{{PART_TYPE_LABEL}}</td>
                <td style="padding:10px 14px;border-bottom:1px solid #e5edf7;color:#111827;">{{PART_TYPE}}</td>
              </tr>
              <tr>
                <td style="padding:10px 14px;border-bottom:1px solid #e5edf7;color:#64748b;font-weight:700;">{{VERSION_LABEL}}</td>
                <td style="padding:10px 14px;border-bottom:1px solid #e5edf7;color:#111827;">{{VERSION}}</td>
              </tr>
              <tr>
                <td style="padding:10px 14px;border-bottom:1px solid #e5edf7;color:#64748b;font-weight:700;">{{CONDITION_SHORT_LABEL}}</td>
                <td style="padding:10px 14px;border-bottom:1px solid #e5edf7;color:#111827;">{{CONDITION_VALUE}}</td>
              </tr>
              <tr>
                <td style="padding:10px 14px;color:#64748b;font-weight:700;">{{PLACEMENT_LABEL}}</td>
                <td style="padding:10px 14px;color:#111827;">{{PLACEMENT}}</td>
              </tr>
            </table>
          </div>
        </td>
      </tr>
    </table>

    <!-- Vehicle and description row -->
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:0 0 20px;">
      <tr>
        <td width="50%" valign="top" style="padding:0 10px 0 0;">
          <div style="border:1px solid #dbe3ef;border-radius:8px;background:#ffffff;overflow:hidden;">
            <div style="background:#06275d;color:#ffffff;padding:12px 16px;font-size:17px;font-weight:900;">
              {{VEHICLE_HEADING}}
            </div>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">
              <tr>
                <td style="padding:10px 14px;border-bottom:1px solid #e5edf7;color:#64748b;font-weight:700;width:42%;">{{VEHICLE_MAKE_LABEL}}</td>
                <td style="padding:10px 14px;border-bottom:1px solid #e5edf7;color:#111827;">{{VEHICLE_MAKE}}</td>
              </tr>
              <tr>
                <td style="padding:10px 14px;border-bottom:1px solid #e5edf7;color:#64748b;font-weight:700;">{{VEHICLE_MODEL_LABEL}}</td>
                <td style="padding:10px 14px;border-bottom:1px solid #e5edf7;color:#111827;">{{VEHICLE_MODEL}}</td>
              </tr>
              <tr>
                <td style="padding:10px 14px;border-bottom:1px solid #e5edf7;color:#64748b;font-weight:700;">{{VEHICLE_YEAR_LABEL}}</td>
                <td style="padding:10px 14px;border-bottom:1px solid #e5edf7;color:#111827;">{{VEHICLE_YEAR}}</td>
              </tr>
              <tr>
                <td style="padding:10px 14px;border-bottom:1px solid #e5edf7;color:#64748b;font-weight:700;">{{ENGINE_LABEL}}</td>
                <td style="padding:10px 14px;border-bottom:1px solid #e5edf7;color:#111827;">{{ENGINE}}</td>
              </tr>
              <tr>
                <td style="padding:10px 14px;color:#64748b;font-weight:700;">{{TRANSMISSION_LABEL}}</td>
                <td style="padding:10px 14px;color:#111827;">{{TRANSMISSION}}</td>
              </tr>
            </table>
          </div>
        </td>

        <td width="50%" valign="top" style="padding:0 0 0 10px;">
          <div style="border:1px solid #dbe3ef;border-radius:8px;background:#ffffff;overflow:hidden;">
            <div style="background:#06275d;color:#ffffff;padding:12px 16px;font-size:17px;font-weight:900;">
              {{DESCRIPTION_HEADING}}
            </div>
            <div style="padding:16px;color:#1f2937;font-size:15px;line-height:1.7;">
              {{DESCRIPTION}}
            </div>
          </div>
        </td>
      </tr>
    </table>

    <!-- Compatibility -->
    <div style="border:1px solid #dbe3ef;background:#ffffff;margin:0 0 20px;border-radius:8px;overflow:hidden;">
      <div style="background:#f8fbff;border-bottom:1px solid #dbe3ef;padding:14px 16px;color:#06275d;font-size:18px;font-weight:900;">
        {{COMPATIBILITY_HEADING}}
      </div>
      <div style="padding:16px 18px;color:#1f2937;font-size:15px;line-height:1.7;">
        {{COMPATIBILITY_LIST}}
        <div style="margin-top:12px;background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;padding:12px 14px;color:#7c2d12;">
          {{COMPATIBILITY_NOTE}}
        </div>
      </div>
    </div>

    <!-- Same vehicle CTA -->
    <div style="text-align:center;margin:0 0 24px;">
      <a href="{{SAME_VEHICLE_URL}}" style="display:inline-block;background:#f97316;color:#ffffff;text-decoration:none;font-weight:900;font-size:16px;padding:13px 24px;border-radius:6px;">
        {{SAME_VEHICLE_TEXT}}
      </a>
    </div>

    <!-- Delivery Europe -->
    <div style="border:1px solid #dbe3ef;background:#ffffff;margin:0 0 20px;border-radius:8px;overflow:hidden;">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">
        <tr>
          <td width="58%" valign="middle" align="center" style="padding:26px 28px;text-align:center;">
            <h2 style="margin:0 0 10px;color:#06275d;font-size:26px;line-height:1.2;font-weight:900;text-align:center;">
              {{DELIVERY_HEADING}}
            </h2>
            <p style="margin:0 0 16px;color:#1f2937;font-size:16px;line-height:1.7;text-align:center;">
              {{DELIVERY_TEXT}}
            </p>
            <div style="display:inline-block;background:#eaf2ff;border:1px solid #c9dcf8;color:#06275d;border-radius:6px;padding:12px 16px;font-size:16px;font-weight:900;">
              {{DELIVERY_TIME}}
            </div>
          </td>
          <td width="42%" valign="middle" align="center" style="padding:22px;background:#f4f8fe;border-left:1px solid #dbe3ef;">
            <div style="border:2px dashed #b9c9df;border-radius:12px;padding:28px 18px;">
              <img src="https://gpswiss.pl/ebay-template/europe-map.png" alt="{{EUROPE_MAP_ALT}}" style="max-width:100%;height:auto;border:0;display:block;margin:0 auto;" />
            </div>
          </td>
        </tr>
      </table>
    </div>

    <!-- DHL / DPD -->
    <div style="border:1px solid #dbe3ef;background:#f8fbff;margin:0 0 22px;border-radius:8px;padding:18px 20px;">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">
        <tr>
          <td width="50%" align="center" style="padding:0 10px 0 0;">
            <div style="background:#ffffff;border:1px solid #dbe3ef;border-radius:8px;padding:18px 12px;">
              <img src="https://gpswiss.pl/ebay-template/dhl-logo.png" alt="DHL" style="max-width:160px;height:auto;border:0;display:block;margin:0 auto;" />
            </div>
          </td>
          <td width="50%" align="center" style="padding:0 0 0 10px;">
            <div style="background:#ffffff;border:1px solid #dbe3ef;border-radius:8px;padding:18px 12px;">
              <img src="https://gpswiss.pl/ebay-template/dpd-logo.png" alt="DPD" style="max-width:160px;height:auto;border:0;display:block;margin:0 auto;" />
            </div>
          </td>
        </tr>
      </table>
    </div>

  </div>

  <!-- Footer -->
  <div style="background:#06275d;color:#ffffff;text-align:center;padding:18px 22px;">
    <div style="font-size:18px;font-weight:900;margin-bottom:4px;">
      {{FOOTER_HEADING}}
    </div>
    <div style="font-size:14px;color:#dbeafe;">
      {{FOOTER_TEXT}}
    </div>
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
            'same_vehicle_text' => 'Mehr Teile von diesem Fahrzeug ansehen',
            'delivery_heading' => 'Wir liefern in ganz Europa',
            'delivery_text' => 'Wir versenden in alle europäischen Länder – schnell, zuverlässig und sicher.',
            'delivery_time' => 'Lieferzeit 2–5 Tage',
            'europe_map_alt' => 'Lieferung in Europa',
            'footer_heading' => 'Kaufen Sie mit Vertrauen',
            'footer_text' => 'Hochwertige gebrauchte Teile | Sorgfältig geprüft | Professionell verpackt',
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

    private function placeholderMap(array $values): array { $map = []; foreach ($values as $key => $value) $map['{{'.Str::upper($key).'}}'] = $this->e((string) $value); return $map; }
    private function compatibilityList(Part $part): string { return trim(collect([$part->storefrontDetailValue('make'), $part->storefrontDetailValue('model'), $part->storefrontDetailValue('production_year')])->filter()->implode(' ')); }
    private function clean(mixed $value): ?string { $value = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)) ?: ''); return $value === '' ? null : $value; }
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
        return ['ok' => false, 'part_id' => $partId, 'channel' => $channel, 'marketplace_id' => null, 'title' => null, 'short_inventory_description' => null, 'listing_description_html' => '', 'html_length' => 0, 'asset_urls' => $this->checkAssets()['asset_urls'], 'missing_assets' => $this->checkAssets()['missing_public_assets'], 'used_fields' => [], 'translation_needed_fields' => [], 'translated_preview_fields' => [], 'would_save' => false, 'blockers' => $blockers, 'warnings' => []];
    }
}
