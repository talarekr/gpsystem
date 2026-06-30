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
        $offer = []; $product = []; $missing = []; $optional = []; $unmapped = []; $diag = []; $hasInvoiceParameter = false;
        $definitions = $definitionsResult['parameters'] ?? [];
        if (! ($definitionsResult['ok'] ?? false)) return $this->result([], [], [], [], [], [['source' => 'not_resolved', 'blocker' => $definitionsResult['blocker'] ?? 'allegro_category_parameters_unavailable']], $definitionsResult);
        foreach ($definitions as $def) {
            $hasInvoiceParameter = $hasInvoiceParameter || $this->norm($def['name'] ?? '') === 'faktura';
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
        $paymentDiagnostics = $hasInvoiceParameter ? [] : [$this->invoicePaymentDiagnosticRow()];
        $payments = $hasInvoiceParameter ? [] : ['invoice' => 'VAT'];
        return $this->result($offer, $product, $missing, $optional, $unmapped, $diag, $definitionsResult, $payments, $paymentDiagnostics);
    }

    private function resolve(Part $part, ?MarketplaceCategoryMapping $mapping, array $def): array
    {
        $name = $this->norm($def['name'] ?? '');
        if ($name === 'jakoscczescizgodniezgvo') return $this->resolveValue('O - oryginał z logo producenta pojazdu (OE)', 'fixed_business_rule', $def);
        if ($name === 'stan') return $this->resolveValue('Używany', 'fixed_business_rule', $def);
        if ($name === 'faktura') return $this->resolveValue('Wystawiam fakturę VAT', 'fixed_business_rule', $def);
        if ($name === 'wersja') return $this->resolveValue('Europejska', 'fixed_business_rule', $def);
        if ($this->isPartManufacturerParameter($def, $name)) {
            $manufacturer = $this->partManufacturer($part);
            return $this->resolvePartManufacturer($manufacturer['value'], $manufacturer['source'], $def, $manufacturer['source_field'] ?? $manufacturer['source']);
        }
        $m = $this->configuredMapping($part, $mapping, $def);
        if ($m) return $this->resolveValue($m['value'], $m['source'], $def);
        if ($name === 'stronazabudowy') return $this->resolveValue($this->partPosition($part), 'part', $def);
        if ($name === 'typsamochodu') return $this->resolveCarType($part, $def);
        if ($vehicleField = $this->vehicleFieldForParameter($name)) return $this->resolveVehicleParameter($part, $def, $vehicleField);
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


    private function vehicleFieldForParameter(string $normalizedName): ?string
    {
        $map = [
            'rodzajskrzyni' => 'gearbox_type', 'typskrzynibiegow' => 'gearbox_type', 'skrzyniabiegow' => 'gearbox_type',
            'rodzajpaliwa' => 'fuel_type', 'typpaliwa' => 'fuel_type', 'paliwo' => 'fuel_type',
            'typnadwozia' => 'body_type', 'nadwozie' => 'body_type',
            'naped' => 'drivetrain', 'rodzajnapedu' => 'drivetrain',
            'stronakierownicy' => 'steering_side', 'kierownica' => 'steering_side',
            'pojemnoscsilnika' => 'engine_capacity_cm3', 'pojemnoscsilnikacm3' => 'engine_capacity_cm3',
            'kodsilnika' => 'engine_code',
            'kodskrzynibiegow' => 'gearbox_code', 'kodskrzyni' => 'gearbox_code',
            'model' => 'model', 'modelsamochodu' => 'model',
            'marka' => 'make', 'markasamochodu' => 'make',
            'rokprodukcji' => 'production_year', 'roksamochodu' => 'production_year',
        ];

        return $map[$normalizedName] ?? null;
    }

    private function resolveVehicleParameter(Part $part, array $def, string $field): array
    {
        $vehicle = $this->vehicleValue($part, $field);
        $raw = $vehicle['value'] ?? null;
        $source = $vehicle['source'] ?? 'not_resolved';
        if (blank($raw)) return ['value' => null, 'source' => $source, 'source_field' => 'car.'.$field, 'source_value' => $raw, 'normalized_value' => null, 'reason' => 'missing_source_value'];
        if (($def['type'] ?? '') !== 'dictionary') {
            return ['value' => (string) $raw, 'source' => $source, 'source_field' => 'car.'.$field, 'source_value' => $raw, 'normalized_value' => $this->norm($raw)];
        }

        $normalized = $this->norm($raw);
        foreach (($def['dictionary'] ?? []) as $allowed) {
            if ($this->vehicleDictionaryMatches($field, $raw, $allowed['value'] ?? '') || (string) ($allowed['id'] ?? '') === (string) $raw) {
                return ['type' => 'dictionary', 'value' => [(string) $allowed['id']], 'label' => $allowed['value'] ?? null, 'source' => $source, 'source_field' => 'car.'.$field, 'source_value' => $raw, 'normalized_value' => $normalized, 'mapped_value_id' => (string) $allowed['id'], 'mapped_label' => $allowed['value'] ?? null];
            }
        }

        return ['value' => null, 'source' => $source, 'source_field' => 'car.'.$field, 'source_value' => $raw, 'normalized_value' => $normalized, 'reason' => 'no_allowed_value_match', 'allowed_values_sample' => array_slice(array_map(fn ($allowed): array => ['id' => (string) ($allowed['id'] ?? ''), 'value' => (string) ($allowed['value'] ?? '')], $def['dictionary'] ?? []), 0, 20)];
    }

    private function vehicleValue(Part $part, string $field): array
    {
        $part->loadMissing('car');
        foreach (["car.$field", "vehicle_snapshot.$field", "legacy_payload.$field", "review_metadata.$field"] as $path) {
            $value = data_get($part, $path);
            if (filled($value)) return ['value' => $value, 'source' => 'part.'.$path];
        }
        return ['value' => null, 'source' => 'not_resolved'];
    }

    private function vehicleDictionaryMatches(string $field, mixed $local, mixed $allowed): bool
    {
        if ($this->matchesDictionaryLabel($allowed, $local)) return true;
        $localNorm = $this->norm($local); $allowedNorm = $this->norm($allowed);
        foreach ($this->vehicleAliasGroups($field) as $group) {
            $norms = array_map(fn ($v) => $this->norm($v), $group);
            if (in_array($localNorm, $norms, true) && in_array($allowedNorm, $norms, true)) return true;
        }
        return false;
    }

    private function vehicleAliasGroups(string $field): array
    {
        return match ($field) {
            'gearbox_type' => [
                ['Automatyczny','Automatyczna','Automatik','automatic','automat','automatyczna skrzynia biegów','automatyczna'],
                ['Manualny','Manualna','manual','ręczna','reczna','manualna skrzynia biegów'],
                ['CVT','Multitronic','bezstopniowa'],
                ['DSG','S tronic','stronic','dwusprzęgłowa','dwusprzeglowa'],
            ],
            'fuel_type' => [
                ['Benzyna','petrol','gasoline'], ['Diesel','olej napędowy','olej napedowy'], ['Hybryda','hybrid'], ['Elektryczny','electric'], ['LPG','gaz'],
            ],
            'drivetrain' => [
                ['Przód','przedni','FWD','front wheel drive'], ['Tył','tylny','RWD','rear wheel drive'], ['AWD','4x4','quattro','napęd na cztery koła','naped na cztery kola'],
            ],
            'steering_side' => [
                ['Lewa strona','lewa','LHD','po lewej'], ['Prawa strona','prawa','RHD','po prawej'],
            ],
            default => [],
        };
    }

    private function configuredMapping(Part $part, ?MarketplaceCategoryMapping $mapping, array $def): ?array
    {
        if (! Schema::hasTable('allegro_parameter_mappings') || ! $mapping) return null;
        $query = DB::table('allegro_parameter_mappings')->where('local_category_id', $part->category_id)->where('allegro_category_id', $mapping->external_category_id)->where('parameter_id', (string) ($def['id'] ?? ''));
        if (Schema::hasColumn('allegro_parameter_mappings', 'enabled')) $query->where('enabled', true);
        $row = $query->first();
        if (! $row) return null;
        if ($row->fixed_value_id || $row->fixed_value_label) return ['value' => $row->fixed_value_id ?: $row->fixed_value_label, 'source' => 'category_mapping', 'source_field' => 'allegro_parameter_mappings.fixed_value'];
        $field = (string) $row->source_field;
        return ['value' => $this->fieldValue($part, $field), 'source' => 'category_mapping', 'source_field' => $field];
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


    private function resolvePartManufacturer(mixed $value, string $source, array $def, ?string $sourceField = null): array
    {
        $sourceValue = $value;
        if (blank($value)) return ['value' => null, 'source' => $source, 'source_field' => $sourceField ?? $source, 'source_value' => $sourceValue, 'reason' => 'missing_source_value'];

        $candidate = trim((string) $value).' OE';
        if (($def['type'] ?? '') !== 'dictionary') return ['value' => $candidate, 'source' => $source, 'source_field' => $sourceField ?? $source, 'source_value' => $sourceValue, 'normalized_value' => $candidate];

        foreach ($this->partManufacturerCandidates((string) $value) as $candidate) {
            foreach (($def['dictionary'] ?? []) as $allowed) {
                if ($this->matchesExactDictionaryLabel($allowed['value'] ?? '', $candidate)) {
                    return ['type' => 'dictionary', 'value' => [(string) $allowed['id']], 'label' => $allowed['value'] ?? null, 'source' => $source, 'source_field' => $sourceField ?? $source, 'source_value' => $sourceValue, 'normalized_value' => $candidate, 'mapped_value_id' => (string) $allowed['id'], 'mapped_label' => $allowed['value'] ?? null];
                }
            }
        }

        return ['value' => null, 'source' => $source, 'source_field' => $sourceField ?? $source, 'source_value' => $sourceValue, 'normalized_value' => trim((string) $value).' OE', 'reason' => 'no_allowed_value_match', 'allowed_values_sample' => array_slice(array_map(fn ($allowed): array => ['id' => (string) ($allowed['id'] ?? ''), 'value' => (string) ($allowed['value'] ?? '')], $def['dictionary'] ?? []), 0, 20), 'allowed_values' => $this->allowedValuesDiagnostics($def)];
    }

    private function diagnosticRow(array $def, array $resolved): array
    {
        $required = (bool) ($def['required'] ?? false);
        $value = $resolved['value'] ?? null;
        $valuesIds = (($resolved['type'] ?? '') === 'dictionary' && $value !== null) ? array_values((array) $value) : [];
        $values = (($resolved['type'] ?? '') !== 'dictionary' && $value !== null) ? array_values((array) $value) : [];
        $source = $resolved['source'] ?? 'not_resolved';
        $reason = $resolved['reason'] ?? null;
        $status = $this->diagnosticStatus($required, $source, $value, $reason);
        $blocker = ($required && $value === null) ? 'required_parameter_not_mapped' : null;

        $row = [
            'id' => (string) ($def['id'] ?? ''),
            'name' => (string) ($def['name'] ?? ''),
            'source' => $source,
            'source_field' => $resolved['source_field'] ?? $source,
            'raw_value' => $resolved['source_value'] ?? null,
            'raw_local_value' => $resolved['source_value'] ?? null,
            'source_value' => $resolved['source_value'] ?? null,
            'normalized_value' => $resolved['normalized_value'] ?? ($value !== null ? ($resolved['label'] ?? $value) : null),
            'resolved_value' => $resolved['label'] ?? $value,
            'values' => $values,
            'valuesIds' => $valuesIds,
            'required' => $required,
            'status' => $status,
            'blocker' => $blocker,
            'reason' => $reason,
            'mapped_value_id' => $resolved['mapped_value_id'] ?? ($valuesIds[0] ?? null),
            'mapped_label' => $resolved['mapped_label'] ?? ($resolved['label'] ?? null),
            'allowed_values_sample' => $resolved['allowed_values_sample'] ?? null,
            'type' => (string) ($def['type'] ?? ''),
            'describesProduct' => (bool) ($def['options']['describesProduct'] ?? false),
            'parameter_location' => ((bool) ($def['options']['describesProduct'] ?? false)) ? 'productSet[0].product.parameters' : 'parameters',
            'allowed_values' => $resolved['allowed_values'] ?? (($this->norm($def['name'] ?? '') === 'typsamochodu') ? $this->allowedValuesDiagnostics($def) : null),
        ];

        if ($row['blocker'] === null) unset($row['blocker']);
        if ($row['reason'] === null) unset($row['reason']);
        if ($row['normalized_value'] === null) unset($row['normalized_value']);
        if ($row['resolved_value'] === null) unset($row['resolved_value']);
        if ($row['mapped_value_id'] === null) unset($row['mapped_value_id']);
        if ($row['mapped_label'] === null) unset($row['mapped_label']);
        if ($row['allowed_values_sample'] === null) unset($row['allowed_values_sample']);
        if ($row['allowed_values'] === null) unset($row['allowed_values']);

        return $row;
    }

    private function diagnosticStatus(bool $required, string $source, mixed $value, ?string $reason): string
    {
        if ($value !== null && $source === 'fixed_business_rule') return 'fixed';
        if ($value !== null) return 'mapped';
        if ($required) return 'missing';
        return $reason === null || $reason === 'no_source' ? 'skipped' : 'missing';
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
        $part->loadMissing('car');
        foreach (['car.make', 'vehicle_snapshot.make', 'legacy_payload.make', 'legacy_payload.vehicle_make', 'review_metadata.make', 'review_metadata.vehicle_make', 'manufacturer', 'brand', 'manufacturer_name', 'part_manufacturer', 'legacy_payload.manufacturer', 'legacy_payload.brand', 'legacy_payload.manufacturer_name', 'review_metadata.manufacturer', 'review_metadata.brand'] as $field) {
            $value = data_get($part, $field);
            if (filled($value)) {
                $source = str_starts_with($field, 'car.') ? 'part.'.$field : (str_starts_with($field, 'vehicle_snapshot.') ? $field : 'part.'.$field);
                $sourceField = str_starts_with($field, 'car.') ? $field : $field;
                return ['value' => $value, 'source' => $source, 'source_field' => $sourceField];
            }
        }

        return ['value' => null, 'source' => 'not_resolved', 'source_field' => 'not_resolved'];
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


    private function matchesExactDictionaryLabel(mixed $allowed, mixed $value): bool
    {
        return $this->norm($allowed) === $this->norm($value);
    }

    private function partManufacturerCandidates(string $make): array
    {
        $base = trim($make).' OE';
        $aliases = [
            'mercedes' => 'Mercedes-Benz OE',
            'mercedesbenz' => 'Mercedes-Benz OE',
            'vw' => 'Volkswagen OE',
            'volkswagen' => 'Volkswagen OE',
            'bmw' => 'BMW OE',
            'audi' => 'Audi OE',
        ];
        $alias = $aliases[$this->norm($make)] ?? null;

        return array_values(array_unique(array_filter([$base, $alias])));
    }

    private function partManufacturerAliasMatches(mixed $allowed, mixed $value): bool
    {
        $allowedNorm = $this->norm($allowed);
        $valueNorm = $this->norm($value);
        foreach ($this->partManufacturerAliasGroups() as $group) {
            $norms = array_map(fn ($alias) => $this->norm($alias), $group);
            if (in_array($allowedNorm, $norms, true) && in_array($valueNorm, $norms, true)) return true;
        }

        return false;
    }

    private function partManufacturerAliasGroups(): array
    {
        return [
            ['OE', 'OEM', 'O.E.', 'O.E.M.', 'oryginał', 'oryginal', 'oryginalny', 'oryginał z logo producenta pojazdu'],
            ['Volkswagen', 'VW'],
            ['Mercedes-Benz', 'Mercedes Benz', 'Mercedes'],
        ];
    }

    private function invoicePaymentDiagnosticRow(): array
    {
        return [
            'name' => 'Faktura',
            'source' => 'fixed_business_rule',
            'source_field' => 'payments.invoice',
            'raw_value' => 'Wystawiam fakturę VAT',
            'raw_local_value' => 'Wystawiam fakturę VAT',
            'source_value' => 'Wystawiam fakturę VAT',
            'normalized_value' => 'Wystawiam fakturę VAT',
            'resolved_value' => 'Wystawiam fakturę VAT',
            'payments' => ['invoice' => 'VAT'],
            'required' => false,
            'status' => 'fixed',
            'type' => 'payment_setting',
            'parameter_location' => 'payments.invoice',
        ];
    }

    private function allowedValuesDiagnostics(array $def): array
    {
        return array_column(array_map(fn ($allowed): array => ['id' => (string) ($allowed['id'] ?? ''), 'value' => (string) ($allowed['value'] ?? '')], $def['dictionary'] ?? []), 'value', 'id');
    }

    private function norm(mixed $v): string { return Str::of((string) $v)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', '')->toString(); }
    private function result(array $offer, array $product, array $missing, array $optional, array $unmapped, array $diag, array $defs, array $payments = [], array $paymentDiagnostics = []): array
    {
        $requiredProductParameterIds = collect($diag)
            ->filter(fn ($row) => ($row['parameter_location'] ?? null) === 'productSet[0].product.parameters' && (bool) ($row['required'] ?? false) && in_array(($row['status'] ?? null), ['fixed', 'mapped'], true))
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
        $payloadParameters = $this->mergeParameters($offer, array_values(array_filter($product, fn ($row) => in_array((string) ($row['id'] ?? ''), $requiredProductParameterIds, true))));

        return ['allegro_parameters'=>array_merge($product, $offer),'offer_parameters'=>$offer,'payload_parameters'=>$payloadParameters,'product_parameters'=>$product,'payments'=>$payments,'missing_required_parameters'=>$missing,'optional_parameters_present'=>$optional,'unmapped_parameters'=>$unmapped,'parameter_source_diagnostics'=>array_merge($diag, $paymentDiagnostics),'product_parameter_diagnostics'=>array_values(array_filter($diag, fn ($row) => ($row['parameter_location'] ?? null) === 'productSet[0].product.parameters')),'offer_parameter_diagnostics'=>array_values(array_filter($diag, fn ($row) => ($row['parameter_location'] ?? null) === 'parameters')),'payment_diagnostics'=>$paymentDiagnostics,'parameter_definitions_source'=>$defs['source'] ?? 'none','will_make_marketplace_request'=>false];
    }

    private function mergeParameters(array ...$groups): array
    {
        $merged = [];
        foreach ($groups as $group) {
            foreach ($group as $parameter) {
                if (! is_array($parameter) || blank($parameter['id'] ?? null)) continue;
                $merged[(string) $parameter['id']] = $parameter;
            }
        }
        return array_values($merged);
    }
}
