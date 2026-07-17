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

    public function __construct(private readonly AllegroManualParameterSelectionService $manualSelectionService) {}

    public function build(Part $part, ?MarketplaceCategoryMapping $mapping, array $definitionsResult): array
    {
        $this->loggedCarTypeMappings = [];
        $offer = []; $product = []; $missing = []; $optional = []; $unmapped = []; $diag = []; $hasInvoiceParameter = false;
        $definitions = $definitionsResult['parameters'] ?? [];
        if (! ($definitionsResult['ok'] ?? false)) return $this->result([], [], [], [], [], [['source' => 'not_resolved', 'blocker' => $definitionsResult['blocker'] ?? 'allegro_category_parameters_unavailable']], $definitionsResult);
        foreach ($definitions as $def) {
            $hasInvoiceParameter = $hasInvoiceParameter || $this->norm($def['name'] ?? '') === 'faktura';
            $required = (bool) ($def['required'] ?? false);
            $resolved = $this->resolveManualDictionarySelection($part, $mapping, $def) ?? $this->resolve($part, $mapping, $def);
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

    private function resolveManualDictionarySelection(Part $part, ?MarketplaceCategoryMapping $mapping, array $def): ?array
    {
        if (($def['type'] ?? '') !== 'dictionary') return null;

        $categoryId = (string) ($mapping?->external_category_id ?? $def['category_id'] ?? '');
        $parameterId = (string) ($def['id'] ?? '');
        if ($categoryId === '' || $parameterId === '') return null;

        $selected = $this->manualSelectionService->selectedValueIds($part, $categoryId, $parameterId);
        if ($selected === []) return null;

        $allowed = array_keys($this->manualSelectionService->allowedLabels($def));
        $valid = array_values(array_intersect($selected, $allowed));

        if ($valid === []) {
            return ['value' => null, 'source' => 'allegro_parameter_selections.value_id', 'source_value' => $selected, 'reason' => 'manual_selection_values_not_in_current_dictionary', 'invalid_saved_value_ids' => $selected];
        }

        return ['type' => 'dictionary', 'value' => $valid, 'source' => 'allegro_parameter_selections.value_id', 'label' => $valid, 'manual_override_used' => true, 'valid_saved_value_ids' => $valid, 'invalid_saved_value_ids' => array_values(array_diff($selected, $valid))];
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
        if ($name === 'stronazabudowy') return $this->resolveInstallationSide($part, $def);
        if ($name === 'typsamochodu') return $this->resolveCarType($part, $def);
        if ($name === 'typsilnika') return $this->resolveEngineTypeFromCarFuel($part, $def);
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



    private function resolveEngineTypeFromCarFuel(Part $part, array $def): array
    {
        $part->loadMissing('car');
        $car = $part->car;
        $base = [
            'source' => 'part.car.fuel_type',
            'source_field' => 'car.fuel_type',
            'part_id' => $part->id,
            'car_id' => $part->car_id,
            'matched_parameter_name' => (string) ($def['name'] ?? ''),
            'matched_parameter_id' => (string) ($def['id'] ?? ''),
        ];

        if (! $car) {
            return $base + ['value' => null, 'source_value' => null, 'reason' => 'Brak samochodu przypisanego do części — nie można ustalić parametru Typ silnika.'];
        }

        $fuelType = $car->fuel_type;
        if (blank($fuelType)) {
            return $base + ['value' => null, 'source_value' => $fuelType, 'reason' => 'Brak rodzaju paliwa w samochodzie — nie można ustalić parametru Typ silnika.'];
        }

        $candidates = $this->engineTypeCandidatesFromFuelType((string) $fuelType);
        $normalized = $this->norm($fuelType);
        if (($def['type'] ?? '') !== 'dictionary') {
            $value = $candidates[0] ?? (string) $fuelType;
            return $base + ['value' => $value, 'source_value' => $fuelType, 'normalized_value' => $normalized, 'matched_value_label' => $value];
        }

        foreach (($def['dictionary'] ?? []) as $allowed) {
            foreach ($candidates as $candidate) {
                if ((string) ($allowed['id'] ?? '') === $candidate || $this->matchesDictionaryLabel($allowed['value'] ?? '', $candidate)) {
                    return $base + ['type' => 'dictionary', 'value' => [(string) $allowed['id']], 'label' => $allowed['value'] ?? null, 'source_value' => $fuelType, 'normalized_value' => $normalized, 'mapped_value_id' => (string) $allowed['id'], 'mapped_label' => $allowed['value'] ?? null, 'matched_value_label' => $allowed['value'] ?? null, 'matched_value_id' => (string) $allowed['id']];
                }
            }
        }

        return $base + ['value' => null, 'source_value' => $fuelType, 'normalized_value' => $normalized, 'reason' => "Nie udało się dopasować rodzaju paliwa {$fuelType} do wartości Allegro parametru Typ silnika.", 'allowed_values_sample' => array_slice(array_map(fn ($allowed): array => ['id' => (string) ($allowed['id'] ?? ''), 'value' => (string) ($allowed['value'] ?? '')], $def['dictionary'] ?? []), 0, 20), 'allowed_values' => $this->allowedValuesDiagnostics($def)];
    }

    private function engineTypeCandidatesFromFuelType(string $fuelType): array
    {
        $normalized = $this->norm($fuelType);
        $map = [
            'benzyna' => ['benzyna', 'benzynowy'],
            'benzynowy' => ['benzyna', 'benzynowy'],
            'petrol' => ['benzyna', 'benzynowy'],
            'gasoline' => ['benzyna', 'benzynowy'],
            'diesel' => ['diesel'],
            'olejnapedowy' => ['diesel'],
            'hybryda' => ['hybryda'],
            'hybrid' => ['hybryda'],
            'elektryczny' => ['elektryczny'],
            'electric' => ['elektryczny'],
            'lpg' => ['benzyna + LPG', 'benzyna lpg', 'lpg', 'gaz'],
            'gaz' => ['benzyna + LPG', 'benzyna lpg', 'lpg', 'gaz'],
            'benzynalpg' => ['benzyna + LPG', 'benzyna lpg', 'lpg', 'gaz'],
            'benzynagaz' => ['benzyna + LPG', 'benzyna lpg', 'lpg', 'gaz'],
        ];

        return $map[$normalized] ?? [];
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


    private function resolveInstallationSide(Part $part, array $def): array
    {
        if ((bool) ($def['required'] ?? false)) {
            $localDefault = $this->localCategoryInstallationSideDefault($part);
            if ($localDefault !== null) {
                return $this->resolveInstallationSideDefault($part, $def, $localDefault);
            }
        }

        return $this->resolvePartPosition($part, $def);
    }

    private function localCategoryInstallationSideDefault(Part $part): ?array
    {
        $part->loadMissing('category');
        $categoryName = (string) ($part->category?->name ?? '');
        $normalizedName = $this->norm($categoryName);

        $rules = [
            'zestawtarczizaciskowhamulcowych' => 'front_and_rear',
            'zaciskhamulcowyprzedni' => 'front',
            'tylnyzaciskhamulcowy' => 'rear',
            'tarczahamulcaprzedniego' => 'front',
        ];

        if (! isset($rules[$normalizedName])) return null;

        return [
            'intent' => $rules[$normalizedName],
            'local_category_id' => $part->category_id,
            'local_category_name' => $categoryName,
            'mapping_source' => 'local_shop_category_default_installation_side',
            'mapping_rule' => [
                'local_category_id' => $part->category_id,
                'local_category_name' => $categoryName,
            ],
        ];
    }

    private function resolveInstallationSideDefault(Part $part, array $def, array $default): array
    {
        $intent = (string) $default['intent'];
        $target = $this->installationSideIntentFallbackLabel($intent);
        $base = [
            'source' => 'local_shop_category_default_installation_side',
            'source_field' => 'part.category.name',
            'source_value' => $default['local_category_name'] ?? null,
            'normalized_value' => $intent,
            'local_category_installation_side_intent' => $intent,
            'parameter_values_source' => 'allegro_category_parameters_api_or_cache',
            'available_values_official' => $this->allowedValuesDiagnostics($def),
            'mapping_source' => $default['mapping_source'],
            'mapping_rule' => $default['mapping_rule'],
            'selected_value_label' => $target,
            'local_category_id' => $default['local_category_id'] ?? $part->category_id,
            'local_category_name' => $default['local_category_name'] ?? null,
        ];

        if (($def['type'] ?? '') !== 'dictionary') {
            return $base + ['value' => $target, 'label' => $target];
        }

        foreach (($def['dictionary'] ?? []) as $allowed) {
            foreach ($this->installationSideCandidates($target) as $candidate) {
                if ((string) ($allowed['id'] ?? '') === $candidate || $this->installationSideLabelMatchesIntent($allowed['value'] ?? '', $intent)) {
                    return $base + ['type' => 'dictionary', 'value' => [(string) $allowed['id']], 'label' => $allowed['value'] ?? null, 'mapped_value_id' => (string) $allowed['id'], 'mapped_label' => $allowed['value'] ?? null, 'matched_official_value_id' => (string) $allowed['id'], 'matched_official_value_label' => $allowed['value'] ?? null, 'selected_value_label' => $allowed['value'] ?? $target, 'selected_value_id' => (string) $allowed['id'], 'matcher_reason' => 'official_label_matches_'.$intent.'_intent'];
                }
            }
        }

        $message = 'Nie znaleziono oficjalnej wartości Allegro dla parametru Strona zabudowy i intencji '.$intent.'.';

        return $base + ['value' => null, 'reason' => $message, 'allowed_values_sample' => array_slice(array_map(fn ($allowed): array => ['id' => (string) ($allowed['id'] ?? ''), 'value' => (string) ($allowed['value'] ?? '')], $def['dictionary'] ?? []), 0, 20), 'allowed_values' => $this->allowedValuesDiagnostics($def), 'matcher_reason' => 'no_official_label_matches_'.$intent.'_intent'];
    }

    private function installationSideIntentFallbackLabel(string $intent): string
    {
        return match ($intent) {
            'front' => 'przód',
            'rear' => 'tył',
            'front_and_rear' => 'przód + tył',
            default => $intent,
        };
    }

    private function installationSideCandidates(string $value): array
    {
        $aliases = [
            'przod' => ['przód', 'przod', 'przednia', 'przedni', 'oś przednia', 'os przednia'],
            'tyl' => ['tył', 'tyl', 'tylna', 'tylny', 'oś tylna', 'os tylna'],
            'przodityl' => ['przód i tył', 'przod i tyl', 'przednia i tylna', 'przedni i tylny', 'oś przednia i tylna', 'os przednia i tylna'],
        ];

        return array_values(array_unique(array_filter(array_merge([$value], $aliases[$this->norm($value)] ?? []))));
    }

    private function installationSideLabelMatchesIntent(mixed $allowed, string $intent): bool
    {
        $label = $this->norm($allowed);
        $hasFront = str_contains($label, 'przod') || str_contains($label, 'przedni') || str_contains($label, 'przednia');
        $hasRear = str_contains($label, 'tyl') || str_contains($label, 'tylny') || str_contains($label, 'tylna');

        return match ($intent) {
            'front' => $hasFront && ! $hasRear,
            'rear' => $hasRear && ! $hasFront,
            'front_and_rear' => $hasFront && $hasRear,
            default => false,
        };
    }

    private function partPosition(Part $part): array
    {
        foreach (['review_metadata.part_position', 'part_position', 'position', 'placement', 'side', 'legacy_payload.part_position', 'legacy_payload.position'] as $field) {
            $value = data_get($part, $field);
            if (filled($value)) return ['value' => $value, 'source_field' => $field];
        }
        return ['value' => null, 'source_field' => 'review_metadata.part_position'];
    }

    private function resolvePartPosition(Part $part, array $def): array
    {
        $position = $this->partPosition($part);
        $sourceValue = $position['value'] ?? null;
        $sourceField = $position['source_field'] ?? 'review_metadata.part_position';

        if (blank($sourceValue)) {
            return ['value' => null, 'source' => 'part_position', 'source_field' => $sourceField, 'source_value' => $sourceValue, 'reason' => 'Brak lub nieobsługiwana Pozycja części dla parametru Allegro: Strona zabudowy'];
        }

        if (($def['type'] ?? '') !== 'dictionary') {
            return ['value' => (string) $sourceValue, 'source' => 'part_position', 'source_field' => $sourceField, 'source_value' => $sourceValue, 'normalized_value' => $this->norm($sourceValue)];
        }

        $candidates = $this->partPositionCandidates((string) $sourceValue);
        foreach (($def['dictionary'] ?? []) as $allowed) {
            foreach ($candidates as $candidate) {
                if ((string) ($allowed['id'] ?? '') === $candidate || $this->matchesDictionaryLabel($allowed['value'] ?? '', $candidate)) {
                    return ['type' => 'dictionary', 'value' => [(string) $allowed['id']], 'label' => $allowed['value'] ?? null, 'source' => 'part_position', 'source_field' => $sourceField, 'source_value' => $sourceValue, 'normalized_value' => $candidate, 'mapped_value_id' => (string) $allowed['id'], 'mapped_label' => $allowed['value'] ?? null];
                }
            }
        }

        return ['value' => null, 'source' => 'part_position', 'source_field' => $sourceField, 'source_value' => $sourceValue, 'normalized_value' => $this->norm($sourceValue), 'reason' => 'Brak lub nieobsługiwana Pozycja części dla parametru Allegro: Strona zabudowy', 'allowed_values_sample' => array_slice(array_map(fn ($allowed): array => ['id' => (string) ($allowed['id'] ?? ''), 'value' => (string) ($allowed['value'] ?? '')], $def['dictionary'] ?? []), 0, 20), 'allowed_values' => $this->allowedValuesDiagnostics($def)];
    }

    private function partPositionCandidates(string $value): array
    {
        $normalized = $this->norm($value);
        $aliases = [
            'lewyprzod' => ['lewy przód', 'przód lewa', 'przód strona lewa', 'lewa przednia', 'przedni lewy'],
            'przodstronalewa' => ['lewy przód', 'przód lewa', 'przód strona lewa', 'lewa przednia', 'przedni lewy'],
            'prawyprzod' => ['prawy przód', 'przód prawa', 'przód strona prawa', 'prawa przednia', 'przedni prawy'],
            'przodstronaprawa' => ['prawy przód', 'przód prawa', 'przód strona prawa', 'prawa przednia', 'przedni prawy'],
            'lewytyl' => ['lewy tył', 'tył lewa', 'tył strona lewa', 'lewa tylna', 'tylny lewy'],
            'tylstronalewa' => ['lewy tył', 'tył lewa', 'tył strona lewa', 'lewa tylna', 'tylny lewy'],
            'prawytyl' => ['prawy tył', 'tył prawa', 'tył strona prawa', 'prawa tylna', 'tylny prawy'],
            'tylstronaprawa' => ['prawy tył', 'tył prawa', 'tył strona prawa', 'prawa tylna', 'tylny prawy'],
            'lewastrona' => ['lewa', 'lewa strona'],
            'lewa' => ['lewa', 'lewa strona'],
            'prawastrona' => ['prawa', 'prawa strona'],
            'prawa' => ['prawa', 'prawa strona'],
            'przod' => ['przód', 'przod', 'przednia'],
            'tyl' => ['tył', 'tyl', 'tylna'],
        ];

        return array_values(array_unique(array_filter(array_merge([$value], $aliases[$normalized] ?? []))));
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
            'part_id' => $resolved['part_id'] ?? null,
            'car_id' => $resolved['car_id'] ?? null,
            'matched_parameter_name' => $resolved['matched_parameter_name'] ?? null,
            'matched_parameter_id' => $resolved['matched_parameter_id'] ?? null,
            'matched_value_label' => $resolved['matched_value_label'] ?? ($resolved['mapped_label'] ?? ($resolved['label'] ?? null)),
            'matched_value_id' => $resolved['matched_value_id'] ?? ($resolved['mapped_value_id'] ?? ($valuesIds[0] ?? null)),
            'allowed_values_sample' => $resolved['allowed_values_sample'] ?? null,
            'type' => (string) ($def['type'] ?? ''),
            'requiredForProduct' => (bool) ($def['options']['requiredForProduct'] ?? $def['requiredForProduct'] ?? false),
            'describesProduct' => (bool) ($def['options']['describesProduct'] ?? false),
            'parameter_location' => ((bool) ($def['options']['describesProduct'] ?? false)) ? 'productSet[0].product.parameters' : 'parameters',
            'allowed_values' => $resolved['allowed_values'] ?? (($def['type'] ?? '') === 'dictionary' ? $this->allowedValuesDiagnostics($def) : null),
            'multiple_choices' => (bool) ($def['restrictions']['multipleChoices'] ?? $def['multipleChoices'] ?? false),
            'selected_value_label' => $resolved['selected_value_label'] ?? ($resolved['label'] ?? null),
            'selected_value_id' => $resolved['selected_value_id'] ?? ($resolved['mapped_value_id'] ?? ($valuesIds[0] ?? null)),
            'local_category_installation_side_intent' => $resolved['local_category_installation_side_intent'] ?? null,
            'parameter_values_source' => $resolved['parameter_values_source'] ?? null,
            'available_values_official' => $resolved['available_values_official'] ?? null,
            'matched_official_value_id' => $resolved['matched_official_value_id'] ?? null,
            'matched_official_value_label' => $resolved['matched_official_value_label'] ?? null,
            'matcher_reason' => $resolved['matcher_reason'] ?? null,
            'mapping_source' => $resolved['mapping_source'] ?? null,
            'mapping_rule' => $resolved['mapping_rule'] ?? null,
            'local_category_id' => $resolved['local_category_id'] ?? null,
            'local_category_name' => $resolved['local_category_name'] ?? null,
            'auto_injected' => ($resolved['mapping_source'] ?? null) === 'local_shop_category_default_installation_side',
            'manual_override_used' => (bool) ($resolved['manual_override_used'] ?? false),
            'valid_saved_value_ids' => $resolved['valid_saved_value_ids'] ?? null,
            'invalid_saved_value_ids' => $resolved['invalid_saved_value_ids'] ?? null,
        ];

        if ($row['blocker'] === null) unset($row['blocker']);
        if ($row['reason'] === null) unset($row['reason']);
        if ($row['normalized_value'] === null) unset($row['normalized_value']);
        if ($row['resolved_value'] === null) unset($row['resolved_value']);
        if ($row['mapped_value_id'] === null) unset($row['mapped_value_id']);
        if ($row['mapped_label'] === null) unset($row['mapped_label']);
        foreach (['part_id', 'car_id', 'matched_parameter_name', 'matched_parameter_id', 'matched_value_label', 'matched_value_id', 'selected_value_label', 'selected_value_id', 'local_category_installation_side_intent', 'parameter_values_source', 'available_values_official', 'matched_official_value_id', 'matched_official_value_label', 'matcher_reason', 'mapping_source', 'mapping_rule', 'local_category_id', 'local_category_name'] as $key) { if ($row[$key] === null) unset($row[$key]); }
        if ($row['auto_injected'] === false) unset($row['auto_injected']);
        if ($row['manual_override_used'] === false) unset($row['manual_override_used']);
        if ($row['valid_saved_value_ids'] === null) unset($row['valid_saved_value_ids']);
        if ($row['invalid_saved_value_ids'] === null) unset($row['invalid_saved_value_ids']);
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
        $payloadParameters = $this->mergeParameters($offer);

        return ['allegro_parameters'=>array_merge($product, $offer),'missing_required_allegro_parameters'=>$this->missingRequiredAllegroParameters($missing),'offer_parameters'=>$offer,'payload_parameters'=>$payloadParameters,'product_parameters'=>$product,'payments'=>$payments,'missing_required_parameters'=>$missing,'optional_parameters_present'=>$optional,'unmapped_parameters'=>$unmapped,'parameter_source_diagnostics'=>array_merge($diag, $paymentDiagnostics),'product_parameter_diagnostics'=>array_values(array_filter($diag, fn ($row) => ($row['parameter_location'] ?? null) === 'productSet[0].product.parameters')),'offer_parameter_diagnostics'=>array_values(array_filter($diag, fn ($row) => ($row['parameter_location'] ?? null) === 'parameters')),'payment_diagnostics'=>$paymentDiagnostics,'parameter_definitions_source'=>$defs['source'] ?? 'none','will_make_marketplace_request'=>false];
    }

    private function missingRequiredAllegroParameters(array $missing): array
    {
        return array_values(array_map(function (array $row): array {
            $dictionary = [];
            foreach (($row['allowed_values'] ?? []) as $id => $label) {
                $dictionary[] = ['id' => (string) $id, 'label' => (string) $label];
            }
            $isDictionary = ($row['type'] ?? '') === 'dictionary';
            $multiple = (bool) ($row['multiple_choices'] ?? true);
            $uiSupported = $isDictionary && $dictionary !== [];

            return [
                'id' => (string) ($row['id'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'type' => (string) ($row['type'] ?? ''),
                'required' => (bool) ($row['required'] ?? false),
                'required_for_product' => (bool) ($row['requiredForProduct'] ?? $row['required_for_product'] ?? $row['required'] ?? false),
                'describes_product' => (bool) ($row['describesProduct'] ?? false),
                'multiple_choices' => $multiple,
                'dictionary' => $dictionary,
                'reason' => $row['reason'] ?? null,
                'source' => 'official_allegro_category_parameters',
                'ui_supported' => $uiSupported,
                'ui_component' => $uiSupported ? ($multiple ? 'multi_select' : 'select') : null,
                'blocker' => $uiSupported ? null : ($isDictionary ? 'allegro_parameter_dictionary_empty' : 'allegro_parameter_type_not_supported'),
            ];
        }, $missing));
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
