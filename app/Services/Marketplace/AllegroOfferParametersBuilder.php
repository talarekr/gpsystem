<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceCategoryMapping;
use App\Models\Part;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AllegroOfferParametersBuilder
{
    private array $loggedCarTypeMappings = [];

    public function build(Part $part, ?MarketplaceCategoryMapping $mapping, array $definitionsResult): array
    {
        $this->loggedCarTypeMappings = [];
        $offer = []; $product = []; $missing = []; $optional = []; $unmapped = []; $diag = [];
        $definitions = $definitionsResult['parameters'] ?? [];
        if (! ($definitionsResult['ok'] ?? false)) return $this->result([], [], [], [], [], [['source' => 'not_resolved', 'blocker' => $definitionsResult['blocker'] ?? 'allegro_category_parameters_unavailable']], $definitionsResult);
        foreach ($definitions as $def) {
            $required = (bool) ($def['required'] ?? false);
            $resolved = $this->resolve($part, $mapping, $def);
            if ($resolved['value'] === null) {
                $row = $this->diagnosticRow($def, $resolved);
                if ($required) $missing[] = $row; else $unmapped[] = $row;
                $diag[] = $row;
                continue;
            }
            $payload = ['id' => (string) $def['id']];
            if (($resolved['type'] ?? '') === 'dictionary') $payload['valuesIds'] = (array) $resolved['value']; else $payload['values'] = (array) $resolved['value'];
            if (($def['options']['describesProduct'] ?? false) === true) $product[] = $payload; else $offer[] = $payload;
            if (! $required) $optional[] = ['id' => (string) $def['id'], 'name' => (string) ($def['name'] ?? '')];
            $diag[] = $this->diagnosticRow($def, $resolved) + ['resolved_value' => $resolved['label'] ?? $resolved['value']];
        }
        return $this->result($offer, $product, $missing, $optional, $unmapped, $diag, $definitionsResult);
    }

    private function resolve(Part $part, ?MarketplaceCategoryMapping $mapping, array $def): array
    {
        $m = $this->configuredMapping($part, $mapping, $def);
        if ($m) return $this->resolveValue($m['value'], $m['source'], $def);
        $name = $this->norm($def['name'] ?? '');
        if ($name === 'stan') return $this->resolveValue('Używany', 'fixed_business_rule', $def);
        if ($name === 'jakoscczescizgodniezgvo') return $this->resolveValue('O - oryginał z logo producenta pojazdu (OE)', 'fixed_business_rule', $def);
        if ($name === 'stronazabudowy') return $this->resolveValue($this->partPosition($part), 'part', $def);
        if ($name === 'typsamochodu') return $this->resolveCarType($part, $def);
        if ($this->isPartManufacturerParameter($def, $name)) {
            $manufacturer = $this->partManufacturer($part);
            return $this->resolveValue($manufacturer['value'], $manufacturer['source'], $def);
        }
        if ($this->isCatalogPartNumberParameter($def, $name) || (string) ($def['id'] ?? '') === '227345') {
            return $this->resolveValue($this->catalogPartNumber($part), 'part.part_number', $def);
        }
        return ['value' => null, 'source' => 'not_resolved', 'source_value' => null, 'reason' => 'no_source'];
    }


    private function resolveCarType(Part $part, array $def): array
    {
        $bodyType = $this->carBodyType($part);
        $sourceValue = $bodyType['value'] ?? null;
        $normalized = $this->norm($sourceValue);
        $allowed = array_map(fn ($allowed): array => ['id' => (string) ($allowed['id'] ?? ''), 'value' => (string) ($allowed['value'] ?? '')], $def['dictionary'] ?? []);
        $allowedById = collect($allowed)->keyBy('id');

        $mapping = $this->carTypeMapping($normalized);
        $mappedId = $mapping['id'] ?? null;
        $mappedLabel = $mappedId ? ($allowedById->get($mappedId)['value'] ?? ($mapping['label'] ?? null)) : null;

        $context = [
            'request' => ['category_id' => (string) ($def['category_id'] ?? ''), 'parameter_id' => (string) ($def['id'] ?? ''), 'parameter_name' => (string) ($def['name'] ?? ''), 'required' => (bool) ($def['required'] ?? false)],
            'response' => ['allowed_values' => array_column($allowed, 'value', 'id')],
            'meta' => [
                'mapping_source' => 'samochód → typ nadwozia',
                'source_value' => $sourceValue,
                'normalized_value' => $normalized,
                'mapped_value_id' => $mappedId,
                'mapped_label' => $mappedLabel,
            ],
        ];

        if (! $mappedId) {
            $reason = blank($sourceValue) ? 'missing_source_value' : 'no_car_type_mapping';
            $context['meta']['reason'] = $reason;
            $this->logCarTypeMapping($def, $sourceValue, $context);

            return ['value' => null, 'source' => $bodyType['source'] ?? 'car.body_type', 'source_value' => $sourceValue, 'normalized_value' => $normalized, 'reason' => $reason];
        }

        if (! $allowedById->has($mappedId)) {
            $context['meta']['reason'] = 'mapped_value_not_allowed_for_category';
            $this->logCarTypeMapping($def, $sourceValue, $context);

            return ['value' => null, 'source' => $bodyType['source'] ?? 'car.body_type', 'source_value' => $sourceValue, 'normalized_value' => $normalized, 'reason' => 'mapped_value_not_allowed_for_category', 'mapped_value_id' => $mappedId, 'mapped_label' => $mappedLabel, 'allowed_values_sample' => array_slice($allowed, 0, 20)];
        }

        $this->logCarTypeMapping($def, $sourceValue, $context);

        return ['type' => 'dictionary', 'value' => [$mappedId], 'label' => $mappedLabel, 'source' => $bodyType['source'] ?? 'car.body_type', 'source_value' => $sourceValue, 'normalized_value' => $normalized, 'mapped_value_id' => $mappedId, 'mapped_label' => $mappedLabel];
    }

    private function carTypeMapping(string $normalized): ?array
    {
        $map = [
            '129591_64' => ['label' => '4x4/SUV', 'terms' => ['suv', '4x4', 'terenowy', 'offroad']],
            '129591_1' => ['label' => 'Samochody osobowe', 'terms' => ['hatchback', 'sedan', 'kombi', 'coupe', 'cabrio', 'kabriolet', 'liftback', 'fastback', 'roadster', 'minivan', 'mpv', 'vanosobowy', 'samochodosobowy', 'osobowy', 'limuzyna']],
            '129591_2' => ['label' => 'Samochody dostawcze', 'terms' => ['dostawczy', 'vandostawczy', 'furgon']],
            '129591_4' => ['label' => 'Samochody ciężarowe', 'terms' => ['ciezarowy', 'truck']],
            '129591_8' => ['label' => 'Autobusy', 'terms' => ['autobus', 'bus']],
            '129591_32' => ['label' => 'Samochody kempingowe', 'terms' => ['kamper', 'kempingowy', 'camper']],
        ];

        foreach ($map as $id => $config) {
            foreach ($config['terms'] as $term) {
                $term = $this->norm($term);
                if ($normalized === $term || ($term !== '' && str_contains($normalized, $term))) {
                    return ['id' => $id, 'label' => $config['label']];
                }
            }
        }

        return null;
    }

    private function logCarTypeMapping(array $def, mixed $sourceValue, array $context): void
    {
        $key = implode(':', [(string) ($def['category_id'] ?? ''), (string) ($def['id'] ?? ''), (string) $sourceValue, $context['meta']['reason'] ?? $context['meta']['mapped_value_id'] ?? 'none']);
        if (isset($this->loggedCarTypeMappings[$key])) return;
        $this->loggedCarTypeMappings[$key] = true;

        app(ApiIntegrationLogger::class)->success('allegro', 'map_parameter:Typ samochodu', 'Allegro Typ samochodu mapping result.', $context);
    }

    private function carBodyType(Part $part): array
    {
        $part->loadMissing('car');
        foreach (['car.body_type', 'vehicle_snapshot.body_type', 'legacy_payload.body_type', 'review_metadata.body_type'] as $field) {
            $value = data_get($part, $field);
            if (filled($value)) return ['value' => $value, 'source' => 'part.'.$field];
        }
        return ['value' => null, 'source' => 'not_resolved'];
    }

    private function configuredMapping(Part $part, ?MarketplaceCategoryMapping $mapping, array $def): ?array
    {
        if (! Schema::hasTable('allegro_parameter_mappings') || ! $mapping) return null;
        $query = DB::table('allegro_parameter_mappings')->where('local_category_id', $part->category_id)->where('allegro_category_id', $mapping->external_category_id)->where('parameter_id', (string) ($def['id'] ?? ''));
        if (Schema::hasColumn('allegro_parameter_mappings', 'enabled')) $query->where('enabled', true);
        $row = $query->first();
        if (! $row) return null;
        if ($row->fixed_value_id || $row->fixed_value_label) return ['value' => $row->fixed_value_id ?: $row->fixed_value_label, 'source' => 'allegro_parameter_mappings'];
        $field = (string) $row->source_field;
        return ['value' => $this->fieldValue($part, $field), 'source' => 'allegro_parameter_mappings'];
    }

    private function fieldValue(Part $part, string $field): mixed
    {
        return data_get($part, $field) ?? data_get($part->vehicle_snapshot, Str::after($field, 'vehicle_snapshot.'));
    }

    private function partPosition(Part $part): mixed
    {
        foreach (['part_position', 'position', 'placement', 'side', 'legacy_payload.part_position', 'legacy_payload.position', 'review_metadata.part_position'] as $field) {
            $value = data_get($part, $field);
            if (filled($value)) return $value;
        }
        return null;
    }

    private function resolveValue(mixed $value, string $source, array $def): array
    {
        $sourceValue = $value; if (blank($value)) return ['value' => null, 'source' => $source, 'source_value' => $sourceValue];
        if (($def['type'] ?? '') !== 'dictionary') return ['value' => (string) $value, 'source' => $source, 'source_value' => $sourceValue];
        foreach (($def['dictionary'] ?? []) as $allowed) {
            if ((string) ($allowed['id'] ?? '') === (string) $value || $this->matchesDictionaryLabel($allowed['value'] ?? '', $value)) return ['type' => 'dictionary', 'value' => [(string) $allowed['id']], 'label' => $allowed['value'] ?? null, 'source' => $source, 'source_value' => $sourceValue];
        }
        return ['value' => null, 'source' => $source, 'source_value' => $sourceValue, 'reason' => 'no_allowed_value_match', 'allowed_values_sample' => array_slice(array_map(fn ($allowed): array => ['id' => (string) ($allowed['id'] ?? ''), 'value' => (string) ($allowed['value'] ?? '')], $def['dictionary'] ?? []), 0, 10)];
    }

    private function diagnosticRow(array $def, array $resolved): array
    {
        $row = [
            'id' => (string) ($def['id'] ?? ''),
            'name' => (string) ($def['name'] ?? ''),
            'source' => $resolved['source'] ?? 'not_resolved',
            'source_value' => $resolved['source_value'] ?? null,
            'reason' => $resolved['reason'] ?? null,
            'normalized_value' => $resolved['normalized_value'] ?? null,
            'mapped_value_id' => $resolved['mapped_value_id'] ?? null,
            'mapped_label' => $resolved['mapped_label'] ?? null,
            'allowed_values_sample' => $resolved['allowed_values_sample'] ?? null,
            'type' => (string) ($def['type'] ?? ''),
            'required' => (bool) ($def['required'] ?? false),
            'describesProduct' => (bool) ($def['options']['describesProduct'] ?? false),
            'allowed_values' => ($this->norm($def['name'] ?? '') === 'typsamochodu') ? array_column(array_map(fn ($allowed): array => ['id' => (string) ($allowed['id'] ?? ''), 'value' => (string) ($allowed['value'] ?? '')], $def['dictionary'] ?? []), 'value', 'id') : null,
        ];

        if ($row['reason'] === null) unset($row['reason']);
        if ($row['normalized_value'] === null) unset($row['normalized_value']);
        if ($row['mapped_value_id'] === null) unset($row['mapped_value_id']);
        if ($row['mapped_label'] === null) unset($row['mapped_label']);
        if ($row['allowed_values_sample'] === null) unset($row['allowed_values_sample']);
        if ($row['allowed_values'] === null) unset($row['allowed_values']);

        return $row;
    }

    private function isPartManufacturerParameter(array $def, string $normalizedName): bool
    {
        return (string) ($def['id'] ?? '') === '127415' || in_array($normalizedName, ['producentczesci', 'producent', 'marka', 'manufacturer', 'brand'], true);
    }

    private function isCatalogPartNumberParameter(array $def, string $normalizedName): bool
    {
        return (string) ($def['id'] ?? '') === '215858' || in_array($normalizedName, ['numerkatalogowyczesci', 'numerczesci', 'manufacturerpartnumber', 'mpn'], true);
    }

    private function partManufacturer(Part $part): array
    {
        foreach (['manufacturer', 'brand', 'manufacturer_name', 'part_manufacturer', 'legacy_payload.manufacturer', 'legacy_payload.brand', 'legacy_payload.manufacturer_name', 'review_metadata.manufacturer', 'review_metadata.brand'] as $field) {
            $value = data_get($part, $field);
            if (filled($value)) return ['value' => $value, 'source' => 'part.'.$field];
        }

        $vehicleMake = data_get($part->vehicle_snapshot, 'make');
        if (filled($vehicleMake)) return ['value' => $vehicleMake, 'source' => 'vehicle_snapshot.make'];

        return ['value' => null, 'source' => 'not_resolved'];
    }

    private function catalogPartNumber(Part $part): mixed
    {
        foreach (['part_number', 'manufacturer_code', 'oem_number', 'sku', 'legacy_payload.part_number', 'legacy_payload.manufacturer_code', 'legacy_payload.oem_number'] as $field) {
            $value = data_get($part, $field);
            if (filled($value)) return $value;
        }

        return null;
    }

    private function matchesDictionaryLabel(mixed $allowed, mixed $value): bool
    {
        $a = $this->norm($allowed); $v = $this->norm($value);
        return $a === $v || ($v !== '' && str_contains($a, $v)) || ($a !== '' && str_contains($v, $a));
    }

    private function norm(mixed $v): string { return Str::of((string) $v)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', '')->toString(); }
    private function result(array $offer, array $product, array $missing, array $optional, array $unmapped, array $diag, array $defs): array { return ['allegro_parameters'=>array_merge($product, $offer),'offer_parameters'=>$offer,'product_parameters'=>$product,'missing_required_parameters'=>$missing,'optional_parameters_present'=>$optional,'unmapped_parameters'=>$unmapped,'parameter_source_diagnostics'=>$diag,'parameter_definitions_source'=>$defs['source'] ?? 'none','will_make_marketplace_request'=>false]; }
}
