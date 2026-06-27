<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceCategoryMapping;
use App\Models\Part;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AllegroOfferParametersBuilder
{
    public function __construct(private readonly AllegroCategoryParametersService $definitions) {}

    public function build(Part $part, ?MarketplaceCategoryMapping $mapping = null): array
    {
        $mapping ??= $this->categoryMapping($part);
        if (! $mapping || blank($mapping->external_category_id)) return $this->empty(['missing_category_mapping']);
        $defs = $this->definitions->get((string) $mapping->external_category_id);
        if (! ($defs['ok'] ?? false)) return $this->empty($defs['blockers'] ?? ['allegro_category_parameters_unavailable'], $defs['source'] ?? 'none');

        $product = $offer = $missing = $unmapped = $diagnostics = [];
        $rows = $this->mappingRows($part, (string) $mapping->external_category_id);
        foreach (($defs['parameters'] ?? []) as $def) {
            $resolved = $this->resolve($def, $part, $rows[(string) ($def['id'] ?? '')] ?? null);
            $required = (bool) ($def['required'] ?? false);
            $isProduct = (bool) data_get($def, 'options.describesProduct');
            $diagnostics[] = ['parameter_id' => $def['id'] ?? null, 'parameter_name' => $def['name'] ?? null, 'source' => $resolved['source'], 'resolved' => $resolved['parameter'] !== null, 'message' => $resolved['message'] ?? null];
            if ($resolved['parameter']) { if ($isProduct) $product[] = $resolved['parameter']; else $offer[] = $resolved['parameter']; }
            elseif ($required) $missing[] = $this->missingRow($def, $resolved);
            else $unmapped[] = $this->missingRow($def, $resolved);
        }

        return [
            'allegro_parameters' => array_values(array_merge($product, $offer)),
            'allegro_product_parameters' => $product,
            'allegro_offer_parameters' => $offer,
            'missing_required_parameters' => $missing,
            'unmapped_parameters' => $unmapped,
            'parameter_definitions_source' => $defs['source'] ?? 'none',
            'parameter_source_diagnostics' => $diagnostics,
            'blockers' => [],
        ];
    }

    private function resolve(array $def, Part $part, ?object $row): array
    {
        $name = (string) ($def['name'] ?? '');
        [$value, $source] = match ($this->norm($name)) {
            $this->norm('Stan') => ['Używany', 'fixed_business_rule'],
            $this->norm('Jakość części (zgodnie z GVO)') => ['O - oryginał z logo producenta pojazdu (OE)', 'fixed_business_rule'],
            $this->norm('Strona zabudowy') => [$part->part_position ?? data_get($part->legacy_payload, 'part_position') ?? data_get($part->legacy_payload, 'position'), 'part'],
            default => $row ? [$row->fixed_value_label ?: null, 'allegro_parameter_mappings'] : [null, 'not_resolved'],
        };
        if (blank($value)) return ['parameter' => null, 'source' => $source, 'message' => 'not_resolved'];
        $param = ['id' => (string) $def['id'], 'name' => $name, 'value_source' => $source];
        $dictionary = (array) data_get($def, 'dictionary', []);
        $values = (array) ($dictionary['values'] ?? []);
        if ($values !== []) {
            $id = $row?->fixed_value_id ?: $this->matchDictionaryId((string) $value, $values);
            if (! $id) return ['parameter' => null, 'source' => $source, 'message' => 'dictionary_value_unmapped'];
            $param['valuesIds'] = [$id];
            $param['resolved_label'] = (string) $value;
        } else {
            $param['values'] = [(string) $value];
        }
        return ['parameter' => $param, 'source' => $source];
    }

    private function matchDictionaryId(string $label, array $values): ?string
    {
        $want = $this->norm($label);
        foreach ($values as $value) {
            $labels = array_filter([(string) ($value['value'] ?? ''), (string) ($value['name'] ?? '')]);
            foreach ($labels as $candidate) if ($this->norm($candidate) === $want || Str::contains($this->norm($candidate), $want) || Str::contains($want, $this->norm($candidate))) return (string) ($value['id'] ?? '');
        }
        return null;
    }

    private function norm(string $v): string { return Str::of($v)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', '')->toString(); }
    private function missingRow(array $def, array $resolved): array { return ['id' => $def['id'] ?? null, 'name' => $def['name'] ?? null, 'required' => (bool) ($def['required'] ?? false), 'describes_product' => (bool) data_get($def, 'options.describesProduct'), 'source' => $resolved['source'] ?? 'not_resolved', 'reason' => $resolved['message'] ?? 'not_resolved']; }
    private function empty(array $blockers = [], string $source = 'none'): array { return ['allegro_parameters'=>[],'allegro_product_parameters'=>[],'allegro_offer_parameters'=>[],'missing_required_parameters'=>[],'unmapped_parameters'=>[],'parameter_definitions_source'=>$source,'parameter_source_diagnostics'=>[],'blockers'=>$blockers]; }
    private function categoryMapping(Part $part): ?MarketplaceCategoryMapping { if (! Schema::hasTable('marketplace_category_mappings') || ! $part->category_id) return null; return MarketplaceCategoryMapping::query()->where('local_category_id', $part->category_id)->whereIn('channel', ['allegro_main','allegro'])->first(); }
    private function mappingRows(Part $part, string $categoryId): array { if (! Schema::hasTable('allegro_parameter_mappings')) return []; return DB::table('allegro_parameter_mappings')->where('local_category_id', $part->category_id)->where('allegro_category_id', $categoryId)->where('enabled', true)->get()->keyBy('parameter_id')->all(); }
}
