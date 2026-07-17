<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Services\Marketplace\AllegroCategoryResolver;
use App\Services\Marketplace\AllegroFunctionsBranchResolver;
use App\Services\Marketplace\AllegroFunctionsParameterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AllegroFunctionsParameterDiagnoseController extends Controller
{
    public function __invoke(Request $request, AllegroFunctionsBranchResolver $branchResolver, AllegroCategoryResolver $categoryResolver, AllegroFunctionsParameterService $parameterService): JsonResponse
    {
        $part = $request->integer('part_id') > 0 ? Part::query()->with(['category.parent', 'allegroParameterSelections'])->find($request->integer('part_id')) : null;
        $category = $part?->category;
        $categoryResolution = $categoryResolver->resolve($part, $request->query('category_id'));
        $parameterResult = filled($categoryResolution['id'] ?? null) ? $parameterService->definition((string) $categoryResolution['id'], $request->boolean('refresh')) : ['ok' => false, 'found' => false, 'definition' => null];
        $definition = $parameterResult['definition'] ?? null;
        $saved = $part ? $part->allegroParameterSelections->map(fn ($row): array => ['parameter_id' => $row->parameter_id, 'value_id' => $row->value_id, 'value_label' => $row->value_label])->values()->all() : [];
        $allowed = $definition ? $parameterService->allowedLabels($definition) : [];
        $valid = array_values(array_filter($saved, fn (array $row): bool => isset($allowed[(string) $row['value_id']])));
        $invalid = array_values(array_filter($saved, fn (array $row): bool => ! isset($allowed[(string) $row['value_id']])));
        $payload = $definition && $valid !== [] ? ['id' => $definition['id'], 'valuesIds' => array_values(array_unique(array_map(fn (array $row): string => (string) $row['value_id'], $valid)))] : null;

        return response()->json([
            'part_id' => $part?->getKey(),
            'local_category' => ['id' => $category?->getKey(), 'name' => $category?->name, 'path' => $branchResolver->path($category), 'in_functions_branch' => $branchResolver->matches($category)],
            'allegro_category' => ['id' => $categoryResolution['id'] ?? null, 'source' => $categoryResolution['source'] ?? null],
            'parameter' => [
                'found' => (bool) ($parameterResult['found'] ?? false), 'id' => $definition['id'] ?? null, 'name' => $definition['name'] ?? null, 'type' => $definition['type'] ?? null,
                'required' => $definition['required'] ?? null, 'required_for_product' => $definition['requiredForProduct'] ?? null, 'describes_product' => data_get($definition, 'options.describesProduct'),
                'multiple_choices' => data_get($definition, 'restrictions.multipleChoices'), 'dictionary' => $definition['dictionary'] ?? [],
            ],
            'saved_selections' => $saved,
            'valid_saved_selections' => $valid,
            'invalid_saved_selections' => $invalid,
            'current_payload_preview' => $payload,
            'blockers' => array_values(array_filter([! $part ? 'part_not_found' : null, blank($categoryResolution['id'] ?? null) ? 'allegro_category_mapping_missing' : null, ($parameterResult['ok'] ?? true) ? null : ($parameterResult['blocker'] ?? 'parameter_unavailable') ])),
            'warnings' => $invalid === [] ? [] : ['Niektóre zapisane valueId nie występują w aktualnym słowniku Allegro.'],
            'writes' => ['database' => false, 'allegro' => false],
        ]);
    }
}
