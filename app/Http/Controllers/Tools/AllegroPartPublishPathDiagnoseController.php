<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceCategoryMapping;
use App\Models\Part;
use App\Services\Marketplace\MarketplaceListingReadinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class AllegroPartPublishPathDiagnoseController extends Controller
{
    private const MARKER = 'allegro_installation_side_from_part_position_official_api_v5';

    public function __invoke(Request $request, MarketplaceListingReadinessService $readiness): JsonResponse
    {
        $partId = (int) $request->query('part_id');
        abort_if($partId <= 0, 422, 'Invalid part_id.');

        $part = Part::query()->with('category')->find($partId);
        if (! $part) {
            return response()->json(['found' => false, 'marker' => self::MARKER, 'part_id' => $partId, 'read_only' => true]);
        }

        $mapping = $this->allegroCategoryMapping($part);
        $preview = $readiness->checkPartReadiness($part, 'allegro_main');
        $allegroParameters = $preview['prepared_payload_preview_safe']['allegro_parameters'] ?? [];
        $row = $this->installationSideDiagnosticRow((array) ($allegroParameters['parameter_source_diagnostics'] ?? []));
        $blockers = (array) ($preview['blockers'] ?? []);

        if (($row['status'] ?? null) === 'missing' && filled($row['reason'] ?? null)) {
            $blockers[] = (string) $row['reason'];
        }

        return response()->json([
            'found' => true,
            'marker' => self::MARKER,
            'part_id' => $part->id,
            'read_only' => true,
            'no_publish' => true,
            'local_category_id' => $part->category_id,
            'local_category_name' => $part->category?->name,
            'allegro_category_id' => $mapping?->external_category_id,
            'allegro_category_name' => $mapping?->external_category_name,
            'required_parameter_detected' => ($row && ($row['required'] ?? false)) ? 'Strona zabudowy' : null,
            'local_installation_position_raw' => $row['local_installation_position_raw'] ?? $row['raw_local_value'] ?? null,
            'normalized_installation_position_intent' => $row['normalized_installation_position_intent'] ?? $row['local_category_installation_side_intent'] ?? null,
            'local_category_installation_side_intent' => $row['local_category_installation_side_intent'] ?? null,
            'parameter_id' => $row['id'] ?? null,
            'parameter_name' => $row['name'] ?? null,
            'parameter_type' => (($row['type'] ?? null) === 'dictionary') ? 'dictionary' : ($row ? 'non-dictionary' : null),
            'parameter_values_source' => $row['parameter_values_source'] ?? null,
            'available_values' => $row['allowed_values'] ?? $row['allowed_values_sample'] ?? [],
            'available_values_official' => $row['available_values_official'] ?? $row['allowed_values'] ?? $row['allowed_values_sample'] ?? [],
            'matched_official_value_id' => $row['matched_official_value_id'] ?? null,
            'matched_official_value_label' => $row['matched_official_value_label'] ?? null,
            'selected_value_label' => $row['selected_value_label'] ?? $row['mapped_label'] ?? $row['resolved_value'] ?? null,
            'selected_value_id' => $row['selected_value_id'] ?? $row['mapped_value_id'] ?? null,
            'mapping_source' => $row['mapping_source'] ?? null,
            'mapping_rule' => $row['mapping_rule'] ?? null,
            'valuesIds' => $row['valuesIds'] ?? [],
            'generated_parameter_payload' => $row ? array_filter(['id' => $row['id'] ?? null, 'valuesIds' => $row['valuesIds'] ?? null, 'values' => $row['values'] ?? null], fn ($value) => $value !== null && $value !== []) : null,
            'matcher_reason' => $row['matcher_reason'] ?? null,
            'auto_injected' => (bool) ($row['auto_injected'] ?? false),
            'blockers' => array_values(array_unique($blockers)),
            'installation_side_parameter_diagnostic' => $row,
            'allegro_parameters' => $allegroParameters,
            'safety_flags' => ['read_only' => true, 'no_mutation' => true, 'no_allegro_request' => true, 'no_publish' => true, 'single_part_only' => true],
        ]);
    }

    private function allegroCategoryMapping(Part $part): ?MarketplaceCategoryMapping
    {
        if (! Schema::hasTable('marketplace_category_mappings') || blank($part->category_id ?? null)) return null;

        return MarketplaceCategoryMapping::query()
            ->where('local_category_id', $part->category_id)
            ->whereIn('channel', ['allegro_main', 'allegro'])
            ->orderByRaw('case when channel = ? then 0 else 1 end', ['allegro_main'])
            ->first();
    }

    private function installationSideDiagnosticRow(array $rows): ?array
    {
        foreach ($rows as $row) {
            if (is_array($row) && $this->normalize($row['name'] ?? '') === 'stronazabudowy') {
                return $row;
            }
        }

        return null;
    }

    private function normalize(mixed $value): string
    {
        return str((string) $value)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', '')->toString();
    }
}
