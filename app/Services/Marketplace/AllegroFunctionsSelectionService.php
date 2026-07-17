<?php

namespace App\Services\Marketplace;

use App\Models\Part;

class AllegroFunctionsSelectionService
{
    public function __construct(private readonly AllegroFunctionsParameterService $parameterService, private readonly AllegroManualParameterSelectionService $manualSelectionService) {}

    public function selectedValueIds(Part $part, string $categoryId, string $parameterId): array
    {
        return $this->manualSelectionService->selectedValueIds($part, $categoryId, $parameterId);
    }

    public function sync(Part $part, string $categoryId, array $definition, mixed $input): array
    {
        return $this->manualSelectionService->sync($part, $categoryId, $definition, $input, 'allegro_functions_value_ids');
    }

    public function normalizeInput(mixed $input): array
    {
        return $this->manualSelectionService->normalizeInput($input, 'allegro_functions_value_ids');
    }
}
