<?php

namespace App\Services\Marketplace\Ebay;

use App\Models\Part;

class EbayConditionMapper
{
    /** @var array<string, string> */
    private const CONDITION_MAP = [
        'używany' => 'USED_EXCELLENT',
        'uzywany' => 'USED_EXCELLENT',
        'używana' => 'USED_EXCELLENT',
        'uzywana' => 'USED_EXCELLENT',
        'used' => 'USED_EXCELLENT',
        'nowy' => 'NEW',
        'nowa' => 'NEW',
        'new' => 'NEW',
        'regenerowany' => 'SELLER_REFURBISHED',
        'regenerowana' => 'SELLER_REFURBISHED',
        'refurbished' => 'SELLER_REFURBISHED',
        'remanufactured' => 'SELLER_REFURBISHED',
    ];

    /** @var array<int, string> */
    private const INVENTORY_API_CONDITIONS = [
        'NEW',
        'LIKE_NEW',
        'NEW_OTHER',
        'NEW_WITH_DEFECTS',
        'MANUFACTURER_REFURBISHED',
        'CERTIFIED_REFURBISHED',
        'EXCELLENT_REFURBISHED',
        'VERY_GOOD_REFURBISHED',
        'GOOD_REFURBISHED',
        'SELLER_REFURBISHED',
        'USED_EXCELLENT',
        'USED_VERY_GOOD',
        'USED_GOOD',
        'USED_ACCEPTABLE',
        'FOR_PARTS_OR_NOT_WORKING',
    ];

    public function usedPartCondition(array $settings = []): array
    {
        return $this->map('Używany', $settings, 'jarek_gearboxes.default_used_part_condition');
    }

    public function jarekCondition(string $sourceValue, string $source = 'jarek_gearboxes.parameters'): array
    {
        return $this->map($sourceValue, [], $source, false);
    }

    public function partCondition(Part $part, array $payload = [], array $settings = []): array
    {
        if (filled($payload['condition'] ?? null)) {
            return $this->map((string) $payload['condition'], $settings, 'payload.condition');
        }

        return $this->map((string) ($part->condition_notes ?? ''), $settings, 'parts.condition_notes');
    }

    public function map(string $sourceValue, array $settings = [], string $source = 'unknown', bool $allowFallback = true): array
    {
        $normalized = mb_strtolower(trim($sourceValue));
        $mapped = self::CONDITION_MAP[$normalized] ?? null;
        $fallback = $allowFallback ? (filled($settings['condition'] ?? null) ? strtoupper((string) $settings['condition']) : 'USED_EXCELLENT') : null;
        $value = $mapped ?? $fallback;
        $valid = is_string($value) && $this->isValidInventoryApiCondition($value);

        return [
            'condition_source' => $source,
            'condition_source_value' => $sourceValue,
            'condition_mapped_value' => $value,
            'condition_mapping_used' => $mapped !== null ? 'localized_condition_map' : ($allowFallback ? 'account_settings_or_default' : 'no_mapping'),
            'condition_mapping_valid' => $valid,
            'condition_inventory_api_format' => 'string_enum',
            'condition_allowed_values' => self::INVENTORY_API_CONDITIONS,
            'condition' => $valid ? $value : null,
        ];
    }

    private function isValidInventoryApiCondition(string $value): bool
    {
        return in_array($value, self::INVENTORY_API_CONDITIONS, true);
    }
}
