<?php

namespace App\Services\Marketplace;

use App\Models\Part;
use Illuminate\Support\Facades\URL;
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
            'specificationRows' => new HtmlString($this->specificationRows($channel, $part, $data)),
            'sameVehicleCta' => new HtmlString($this->sameVehicleCta($data)),
        ])->render();
    }

    /** @return array<string, string> */
    public function labelsForChannel(string $channel): array
    {
        return match ($channel) {
            'ebay_fr' => [
                'shipping' => 'Livraison rapide dans le monde entier', 'returns' => 'Retour sous 30 jours', 'packaging' => 'Emballage sécurisé', 'original' => 'Pièce d’origine 100 %', 'description' => 'Description', 'specifications' => 'Spécifications', 'europe_delivery_title' => 'Nous livrons dans toute l’Europe', 'europe_delivery_text' => 'Nous expédions vers tous les pays européens – rapidement, de manière fiable et sécurisée.', 'delivery_time' => 'Délai de livraison 2–5 jours', 'trust_title' => 'Achetez en toute confiance', 'trust_subtitle' => 'Pièces d’occasion vérifiées | Contrôlées avec soin | Emballées professionnellement', 'not_specified' => 'Non indiqué',
                'part_number' => 'Numéro de pièce', 'oem_code' => 'Code fabricant / OEM', 'manufacturer' => 'Fabricant / marque', 'vehicle_model' => 'Modèle du véhicule', 'year' => 'Année', 'engine' => 'Moteur', 'steering_side' => 'Côté conducteur', 'condition' => 'État / qualité',
            ],
            default => [
                'shipping' => 'Schneller weltweiter Versand', 'returns' => '30 Tage Rückgabe', 'packaging' => 'Sichere Verpackung', 'original' => '100% Originalteil', 'description' => 'Beschreibung', 'specifications' => 'Spezifikationen', 'europe_delivery_title' => 'Wir liefern in ganz Europa', 'europe_delivery_text' => 'Wir versenden in alle europäischen Länder – schnell, zuverlässig und sicher.', 'delivery_time' => 'Lieferzeit 2–5 Tage', 'trust_title' => 'Kaufen Sie mit Vertrauen', 'trust_subtitle' => 'Geprüfte gebrauchte Teile | Sorgfältig kontrolliert | Professionell verpackt', 'not_specified' => 'Nicht angegeben',
                'part_number' => 'Teilenummer', 'oem_code' => 'Hersteller-/OEM-Code', 'manufacturer' => 'Hersteller / Marke', 'vehicle_model' => 'Fahrzeugmodell', 'year' => 'Baujahr', 'engine' => 'Motor', 'steering_side' => 'Lenkradseite', 'condition' => 'Zustand / Qualität',
            ],
        };
    }

    public function assetUrl(string $filename): string
    {
        return URL::route('ebay-template.asset', ['filename' => $filename]);
    }

    /** @return array<string, string> */
    public function assetUrls(): array
    {
        return collect(self::ASSETS)->mapWithKeys(fn (string $filename, string $key): array => [$key => $this->assetUrl($filename)])->all();
    }

    /** @param array<string, mixed> $data */
    private function descriptionBlock(Part $part, array $data): string
    {
        $value = (string) ($data['description_block'] ?? $part->description ?? $part->short_description ?? '');
        $plain = trim(strip_tags($value));

        return $plain === '' ? '' : '<p style="margin:0;color:#1f2937;font-size:16px;line-height:1.7;text-align:center;">'.e($plain).'</p>';
    }

    /** @param array<string, mixed> $data */
    private function specificationRows(string $channel, Part $part, array $data): string
    {
        $labels = $this->labelsForChannel($channel);
        $vehicle = is_array($part->vehicle_snapshot) ? $part->vehicle_snapshot : [];
        $specs = [
            $labels['part_number'] => $data['part_number'] ?? $part->part_number ?? null,
            $labels['oem_code'] => $data['oem_code'] ?? $part->oem_number ?? $part->manufacturer_code ?? null,
            $labels['manufacturer'] => $data['manufacturer'] ?? $vehicle['make'] ?? null,
            $labels['vehicle_model'] => $data['vehicle_model'] ?? $vehicle['model'] ?? null,
            $labels['year'] => $data['year'] ?? $vehicle['production_year'] ?? null,
            $labels['engine'] => $data['engine'] ?? $vehicle['engine_code'] ?? $vehicle['engine_capacity_cm3'] ?? null,
            $labels['steering_side'] => $data['steering_side'] ?? $vehicle['steering_side'] ?? null,
            $labels['condition'] => $data['condition'] ?? $part->condition_notes ?? null,
        ];

        $rows = '';
        foreach ($specs as $label => $value) {
            if (blank($value)) continue;
            $rows .= $this->specificationRow($label, (string) $value);
        }

        return $rows !== '' ? $rows : $this->specificationRow($labels['not_specified'], $labels['not_specified']);
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
