<?php

namespace App\Services\Marketplace;

class AllegroFunctionsParameterService
{
    public function __construct(private readonly AllegroCategoryParametersService $parametersService) {}

    public function definition(string $allegroCategoryId, bool $refresh = false): array
    {
        $result = $this->parametersService->definitions($allegroCategoryId, $refresh);
        if (! ($result['ok'] ?? false)) {
            return ['ok' => false, 'found' => false, 'blocker' => $result['blocker'] ?? 'allegro_parameters_unavailable', 'source' => $result['source'] ?? null, 'definition' => null];
        }

        foreach (($result['parameters'] ?? []) as $parameter) {
            if ($this->normalize((string) ($parameter['name'] ?? '')) === 'funkcje') {
                return ['ok' => true, 'found' => true, 'source' => $result['source'] ?? null, 'definition' => $this->normalizeDefinition($parameter)];
            }
        }

        return ['ok' => true, 'found' => false, 'source' => $result['source'] ?? null, 'definition' => null];
    }

    public function normalizeDefinition(array $parameter): array
    {
        $dictionary = array_values(array_filter(array_map(fn (array $row): array => [
            'id' => (string) ($row['id'] ?? ''),
            'label' => (string) ($row['value'] ?? $row['label'] ?? ''),
        ], array_filter($parameter['dictionary'] ?? [], 'is_array')), fn (array $row): bool => $row['id'] !== ''));

        return [
            'id' => (string) ($parameter['id'] ?? ''),
            'name' => (string) ($parameter['name'] ?? ''),
            'type' => (string) ($parameter['type'] ?? ''),
            'required' => (bool) ($parameter['required'] ?? false),
            'requiredForProduct' => $parameter['requiredForProduct'] ?? null,
            'options' => ['describesProduct' => (bool) data_get($parameter, 'options.describesProduct', false)],
            'restrictions' => ['multipleChoices' => (bool) data_get($parameter, 'restrictions.multipleChoices', false)],
            'dictionary' => $dictionary,
            'raw' => $parameter,
        ];
    }

    public function allowedLabels(array $definition): array
    {
        return collect($definition['dictionary'] ?? [])->mapWithKeys(fn (array $row): array => [(string) $row['id'] => (string) $row['label']])->all();
    }

    public function isFunctionsDefinition(array $definition): bool
    {
        return $this->normalize((string) ($definition['name'] ?? '')) === 'funkcje';
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }
}
