<?php

namespace App\Services\Marketplace;

use App\Models\AllegroParameterSelection;
use App\Models\Part;
use Illuminate\Validation\ValidationException;

class AllegroFunctionsSelectionService
{
    public function __construct(private readonly AllegroFunctionsParameterService $parameterService) {}

    public function selectedValueIds(Part $part, string $categoryId, string $parameterId): array
    {
        return $part->allegroParameterSelections()->where('allegro_category_id', $categoryId)->where('parameter_id', $parameterId)->pluck('value_id')->map(fn ($id): string => (string) $id)->unique()->values()->all();
    }

    public function sync(Part $part, string $categoryId, array $definition, mixed $input): array
    {
        $valueIds = $this->normalizeInput($input);
        $labels = $this->parameterService->allowedLabels($definition);
        $invalid = array_values(array_diff($valueIds, array_keys($labels)));
        if ($invalid !== []) {
            throw ValidationException::withMessages(['allegro_functions_value_ids' => 'Wybrane funkcje Allegro nie występują w aktualnym słowniku kategorii.']);
        }

        $parameterId = (string) $definition['id'];
        AllegroParameterSelection::query()->where('part_id', $part->getKey())->where(function ($query) use ($categoryId, $parameterId): void {
            $query->where('allegro_category_id', '!=', $categoryId)->orWhere('parameter_id', $parameterId);
        })->delete();

        foreach ($valueIds as $valueId) {
            AllegroParameterSelection::query()->updateOrCreate([
                'part_id' => $part->getKey(),
                'allegro_category_id' => $categoryId,
                'parameter_id' => $parameterId,
                'value_id' => $valueId,
            ], ['parameter_name' => (string) $definition['name'], 'value_label' => $labels[$valueId] ?? null]);
        }

        return $valueIds;
    }

    public function normalizeInput(mixed $input): array
    {
        if ($input === null || $input === '') return [];
        if (! is_array($input)) throw ValidationException::withMessages(['allegro_functions_value_ids' => 'Wartość musi być tablicą identyfikatorów.']);
        $out = [];
        foreach ($input as $value) {
            if (is_array($value) || is_object($value)) throw ValidationException::withMessages(['allegro_functions_value_ids' => 'Zagnieżdżone wartości nie są dozwolone.']);
            $value = trim((string) $value);
            if ($value !== '') $out[] = $value;
        }
        return array_values(array_unique($out));
    }
}
