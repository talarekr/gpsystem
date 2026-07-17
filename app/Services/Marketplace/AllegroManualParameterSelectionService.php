<?php

namespace App\Services\Marketplace;

use App\Models\AllegroParameterSelection;
use App\Models\Part;
use Illuminate\Validation\ValidationException;

class AllegroManualParameterSelectionService
{
    public function selectedValueIds(Part $part, string $categoryId, string $parameterId): array
    {
        return $part->allegroParameterSelections()
            ->where('allegro_category_id', $categoryId)
            ->where('parameter_id', $parameterId)
            ->pluck('value_id')
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function sync(Part $part, string $categoryId, array $definition, mixed $input, string $errorKey = 'allegro_manual_parameter_value_ids'): array
    {
        $valueIds = $this->normalizeInput($input, $errorKey);
        $labels = $this->allowedLabels($definition);
        $invalid = array_values(array_diff($valueIds, array_keys($labels)));

        if ($invalid !== []) {
            throw ValidationException::withMessages([$errorKey => 'Wybrane wartości Allegro nie występują w aktualnym słowniku kategorii.']);
        }

        $parameterId = (string) ($definition['id'] ?? '');
        $categoryId = trim($categoryId);

        if ($categoryId === '' || $parameterId === '') {
            throw ValidationException::withMessages([$errorKey => 'Brak aktualnej kategorii lub parametru Allegro.']);
        }

        AllegroParameterSelection::query()
            ->where('part_id', $part->getKey())
            ->where('allegro_category_id', $categoryId)
            ->where('parameter_id', $parameterId)
            ->delete();

        foreach ($valueIds as $valueId) {
            AllegroParameterSelection::query()->updateOrCreate([
                'part_id' => $part->getKey(),
                'allegro_category_id' => $categoryId,
                'parameter_id' => $parameterId,
                'value_id' => $valueId,
            ], [
                'parameter_name' => (string) ($definition['name'] ?? ''),
                'value_label' => $labels[$valueId] ?? null,
            ]);
        }

        return $valueIds;
    }

    public function normalizeInput(mixed $input, string $errorKey = 'allegro_manual_parameter_value_ids'): array
    {
        if ($input === null || $input === '') return [];
        if (! is_array($input)) throw ValidationException::withMessages([$errorKey => 'Wartość musi być tablicą identyfikatorów.']);

        $out = [];
        foreach ($input as $value) {
            if (is_array($value) || is_object($value)) throw ValidationException::withMessages([$errorKey => 'Zagnieżdżone wartości nie są dozwolone.']);
            $value = trim((string) $value);
            if ($value !== '') $out[] = $value;
        }

        return array_values(array_unique($out));
    }

    public function allowedLabels(array $definition): array
    {
        $labels = [];
        foreach (($definition['dictionary'] ?? []) as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id !== '') $labels[$id] = (string) ($row['value'] ?? $row['label'] ?? $id);
        }

        return $labels;
    }

    public function savedSelectionsForCategory(Part $part, string $categoryId): array
    {
        return $part->allegroParameterSelections()
            ->where('allegro_category_id', $categoryId)
            ->get()
            ->groupBy('parameter_id')
            ->map(fn ($rows): array => $rows->pluck('value_id')->map(fn ($id): string => (string) $id)->unique()->values()->all())
            ->all();
    }
}
