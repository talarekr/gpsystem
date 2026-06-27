<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceCategoryMapping;
use App\Models\Part;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AllegroOfferParametersBuilder
{
    public function build(Part $part, ?MarketplaceCategoryMapping $mapping, array $definitionsResult): array
    {
        $offer = []; $product = []; $missing = []; $optional = []; $unmapped = []; $diag = [];
        $definitions = $definitionsResult['parameters'] ?? [];
        if (! ($definitionsResult['ok'] ?? false)) return $this->result([], [], [], [], [], [['source' => 'not_resolved', 'blocker' => $definitionsResult['blocker'] ?? 'allegro_category_parameters_unavailable']], $definitionsResult);
        foreach ($definitions as $def) {
            $required = (bool) ($def['required'] ?? false);
            $resolved = $this->resolve($part, $mapping, $def);
            if ($resolved['value'] === null) {
                $row = ['id' => (string) ($def['id'] ?? ''), 'name' => (string) ($def['name'] ?? ''), 'source' => $resolved['source'], 'source_value' => $resolved['source_value']];
                if ($required) $missing[] = $row; else $unmapped[] = $row;
                $diag[] = $row;
                continue;
            }
            $payload = ['id' => (string) $def['id']];
            if (($resolved['type'] ?? '') === 'dictionary') $payload['valuesIds'] = (array) $resolved['value']; else $payload['values'] = (array) $resolved['value'];
            if (($def['options']['describesProduct'] ?? false) === true) $product[] = $payload; else $offer[] = $payload;
            if (! $required) $optional[] = ['id' => (string) $def['id'], 'name' => (string) ($def['name'] ?? '')];
            $diag[] = ['id' => (string) $def['id'], 'name' => (string) ($def['name'] ?? ''), 'source' => $resolved['source'], 'source_value' => $resolved['source_value'], 'resolved_value' => $resolved['label'] ?? $resolved['value']];
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
        return ['value' => null, 'source' => 'not_resolved', 'source_value' => null];
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
        return ['value' => null, 'source' => $source, 'source_value' => $sourceValue];
    }

    private function matchesDictionaryLabel(mixed $allowed, mixed $value): bool
    {
        $a = $this->norm($allowed); $v = $this->norm($value);
        return $a === $v || ($v !== '' && str_contains($a, $v)) || ($a !== '' && str_contains($v, $a));
    }

    private function norm(mixed $v): string { return Str::of((string) $v)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', '')->toString(); }
    private function result(array $offer, array $product, array $missing, array $optional, array $unmapped, array $diag, array $defs): array { return ['allegro_parameters'=>array_merge($product, $offer),'offer_parameters'=>$offer,'product_parameters'=>$product,'missing_required_parameters'=>$missing,'optional_parameters_present'=>$optional,'unmapped_parameters'=>$unmapped,'parameter_source_diagnostics'=>$diag,'parameter_definitions_source'=>$defs['source'] ?? 'none','will_make_marketplace_request'=>false]; }
}
