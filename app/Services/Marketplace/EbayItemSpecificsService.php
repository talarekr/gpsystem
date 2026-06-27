<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceCategoryMapping;
use App\Models\Part;

class EbayItemSpecificsService
{
    /** @return array<string, mixed> */
    public function build(Part $part, string $channel, ?MarketplaceCategoryMapping $mapping = null, ?array $prepared = null): array
    {
        $channel = $channel === 'ebay' ? 'ebay_de' : $channel;
        $translationStatus = is_array($prepared) ? (string) ($prepared['status'] ?? 'not_prepared') : 'not_prepared';
        $preparedSpecifics = is_array($prepared['item_specifics'] ?? null) ? $prepared['item_specifics'] : [];
        $source = $part->car_id && $part->car ? 'car' : (is_array($part->vehicle_snapshot ?? null) ? 'vehicle_snapshot' : 'part_only');

        $specifics = $preparedSpecifics !== [] ? $preparedSpecifics : $this->fallbackSpecifics($part, $channel, $mapping);
        $specifics = array_filter($specifics, fn ($value): bool => filled($value));

        return [
            'item_specifics_present' => $specifics !== [],
            'item_specifics_count' => count($specifics),
            'item_specifics' => $specifics,
            'aspects' => $specifics,
            'item_specifics_source' => $preparedSpecifics !== [] ? 'review_metadata.marketplace_prepared_translations.'.$channel.'.item_specifics' : $source.' + parts + marketplace_category_mappings',
            'item_specifics_missing_required' => $this->missingRequired($specifics, $mapping),
            'item_specifics_unmapped_fields' => [],
            'item_specifics_translation_status' => $translationStatus,
            'item_specifics_translated_fields' => array_keys($specifics),
            'warnings' => [],
        ];
    }

    /** @return array<string, string> */
    public function fallbackSpecifics(Part $part, string $channel, ?MarketplaceCategoryMapping $mapping = null): array
    {
        $vehicle = $this->vehicle($part);
        $labels = $this->labels($channel);
        $partNumber = $this->clean($part->part_number ?: $part->manufacturer_code ?: $part->sku);
        $oem = $this->clean($part->oem_number ?: $part->manufacturer_code ?: $partNumber);
        $condition = $this->localized($part->condition_notes ?: 'Używany', $channel);

        return array_filter([
            $labels['condition_item'] => $condition,
            $labels['part_number'] => $partNumber,
            'Manufacturer Part Number' => $partNumber,
            $labels['mpn'] => $partNumber,
            $labels['manufacturer'] => $this->clean($vehicle['make'] ?? null),
            $labels['model'] => $this->clean($vehicle['model'] ?? null),
            $labels['variant'] => $this->clean($vehicle['model_variant'] ?? null),
            $labels['vehicle_year'] => $this->clean($vehicle['production_year'] ?? null),
            $labels['fuel_type'] => $this->localized($vehicle['fuel_type'] ?? null, $channel),
            $labels['engine_power'] => $this->unit($vehicle['engine_power_kw'] ?? null, 'kW'),
            $labels['gearbox_type'] => $this->localized($vehicle['gearbox_type'] ?? null, $channel),
            $labels['steering'] => $this->localized($vehicle['steering_side'] ?? null, $channel),
            $labels['mileage'] => $this->unit($vehicle['mileage_km'] ?? null, 'km'),
            $labels['category'] => $this->clean($mapping?->external_category_name ?? $mapping?->local_category_name ?? $part->category?->name ?? null),
            $labels['condition'] => $condition,
            $labels['engine_capacity'] => $this->unit($vehicle['engine_capacity_cm3'] ?? null, 'cm³'),
            $labels['engine_code'] => $this->clean($vehicle['engine_code'] ?? null),
            $labels['drivetrain'] => $this->localized($vehicle['drivetrain'] ?? null, $channel),
            $labels['color'] => $this->localized($vehicle['color'] ?? null, $channel),
            $labels['manufacturer_number'] => $partNumber,
            $labels['oem'] => $oem,
            $labels['body_type'] => $this->localized($vehicle['body_type'] ?? null, $channel),
            $labels['gearbox_code'] => $this->clean($vehicle['gearbox_code'] ?? null),
            $labels['color_code'] => $this->clean($vehicle['color_code'] ?? null),
        ], fn ($value): bool => filled($value));
    }

    /** @return array<string, mixed> */
    private function vehicle(Part $part): array
    {
        $allowed = ['make','model','model_variant','production_year','first_registration_year','steering_side','mileage_km','fuel_type','engine_power_kw','engine_capacity_cm3','engine_code','drivetrain','gearbox_type','gearbox_code','body_type','color_code','color','interior'];

        if ($part->car_id && $part->car) {
            return $part->car->only($allowed);
        }

        return is_array($part->vehicle_snapshot ?? null) ? array_intersect_key($part->vehicle_snapshot, array_flip($allowed)) : [];
    }

    /** @return array<string, string> */
    private function labels(string $channel): array
    {
        if ($channel === 'ebay_fr') {
            return ['condition_item'=>'État de l’objet','part_number'=>'Numéro de pièce','mpn'=>'MPN','manufacturer'=>'Marque','model'=>'Modèle','variant'=>'Variante / finition','vehicle_year'=>'Année du véhicule','fuel_type'=>'Type de carburant','engine_power'=>'Puissance moteur','gearbox_type'=>'Type de boîte de vitesses','steering'=>'Position du volant','mileage'=>'Kilométrage','category'=>'Catégorie','condition'=>'État','engine_capacity'=>'Cylindrée','engine_code'=>'Code moteur','drivetrain'=>'Transmission','color'=>'Couleur','manufacturer_number'=>'Numéro fabricant','oem'=>'Numéro de référence OE/OEM','body_type'=>'Type de carrosserie','gearbox_code'=>'Code de boîte de vitesses','color_code'=>'Code couleur'];
        }

        return ['condition_item'=>'Artikelzustand','part_number'=>'Teilenummer','mpn'=>'MPN','manufacturer'=>'Hersteller','model'=>'Modell','variant'=>'Variante / Ausführung','vehicle_year'=>'Baujahr des Fahrzeugs','fuel_type'=>'Kraftstoffart','engine_power'=>'Motorleistung','gearbox_type'=>'Getriebeart','steering'=>'Lenkradposition','mileage'=>'Laufleistung','category'=>'Kategorie','condition'=>'Zustand','engine_capacity'=>'Hubraum','engine_code'=>'Motorcode','drivetrain'=>'Antrieb','color'=>'Farbe','manufacturer_number'=>'Herstellernummer','oem'=>'OE/OEM Referenznummer','body_type'=>'Karosserietyp','gearbox_code'=>'Getriebecode','color_code'=>'Farbcode'];
    }

    private function localized(mixed $value, string $channel): ?string
    {
        $value = $this->clean($value);
        if ($value === null) return null;
        $n = mb_strtolower($value);
        $map = [
            'ebay_de' => ['benzyna'=>'Benzin','szary'=>'Grau','lewa strona'=>'Linkslenker','po lewej'=>'Linkslenker','automatyczny'=>'Automatik','automatyczna'=>'Automatik','używany'=>'Gebraucht','używana'=>'Gebraucht'],
            'ebay_fr' => ['benzyna'=>'Essence','szary'=>'Gris','lewa strona'=>'Volant à gauche','po lewej'=>'Volant à gauche','automatyczny'=>'Automatique','automatyczna'=>'Automatique','używany'=>'Occasion','używana'=>'Occasion'],
        ];

        return $map[$channel][$n] ?? $value;
    }

    private function unit(mixed $value, string $unit): ?string
    {
        $value = $this->clean($value);
        if ($value === null) return null;
        return str_contains($value, $unit) ? $value : $value.' '.$unit;
    }

    private function clean(mixed $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)) ?: '');
        return $value === '' ? null : $value;
    }

    /** @param array<string, string> $specifics @return array<int, string> */
    private function missingRequired(array $specifics, ?MarketplaceCategoryMapping $mapping): array
    {
        $metadata = is_array($mapping?->metadata) ? $mapping->metadata : [];
        $required = data_get($metadata, 'required_aspects', data_get($metadata, 'aspect_requirements.required', []));
        if (! is_array($required)) return [];

        return array_values(array_filter($required, fn ($name): bool => is_string($name) && filled($name) && ! array_key_exists($name, $specifics)));
    }
}
