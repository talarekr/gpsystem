<?php

namespace App\Services\MigrationExport;

/** Keep parameter value types/leading zeroes; redact capabilities without mistaking OEM codes for card numbers. */
final class AllegroParameterDataPolicy
{
    public static function clean(mixed $value, ?string $field = null, bool $parameterValue = false): mixed
    {
        $parameterValue = $parameterValue || in_array($field, ['values', 'valuesIds', 'values_ids', 'rangeValue', 'range_value'], true);
        if (is_array($value)) {
            $kind = $value['type'] ?? $value['object'] ?? null;
            if (is_string($kind) && in_array(strtolower($kind), ['card', 'card_token', 'credit_card', 'debit_card'], true)) {
                return MigrationDataPolicy::clean($value);
            }
            $out = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && MigrationDataPolicy::sensitiveKey($key)) {
                    continue;
                }
                $context = is_string($key) ? $key : ($field !== null && MigrationDataPolicy::identifier($field) ? $field : null);
                $out[$key] = self::clean($item, $context, $parameterValue);
            }

            return $out;
        }
        if (is_string($value) && in_array(substr(ltrim($value), 0, 1), ['{', '['], true)) {
            $decoded = json_decode($value, true, 512, JSON_BIGINT_AS_STRING);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return self::clean($decoded, $field, $parameterValue);
            }
        }
        if ($parameterValue) {
            return is_string($value) ? MigrationDataPolicy::clean($value, 'part_number') : $value;
        }

        return MigrationDataPolicy::clean($value, $field);
    }
}
