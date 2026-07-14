<?php

namespace App\Support\Parts;

use Illuminate\Validation\ValidationException;

class AdditionalPartCodes
{
    public const MAX_CODES = 2;
    public const MAX_LENGTH = 255;

    /**
     * additional_part_codes_persistence_v1
     *
     * @param  mixed  $codes
     * @return array<int, string>|null
     */
    public static function normalize(mixed $codes, mixed $mainPartCode = null, bool $throwOnDuplicates = true): ?array
    {
        $main = trim((string) ($mainPartCode ?? ''));
        $items = self::normalizeInputItems($codes);
        $normalized = [];
        $seen = [];

        foreach (array_slice($items, 0, self::MAX_CODES + 1) as $code) {
            $value = trim((string) $code);

            if ($value === '') {
                continue;
            }

            if (mb_strlen($value) > self::MAX_LENGTH) {
                throw ValidationException::withMessages(['additional_part_codes' => 'Kod części może mieć maksymalnie 255 znaków.']);
            }

            $key = mb_strtolower($value);
            if ($main !== '' && $key === mb_strtolower($main)) {
                throw ValidationException::withMessages(['additional_part_codes' => 'Dodatkowy kod części nie może być taki sam jak główny kod części.']);
            }

            if (isset($seen[$key])) {
                if ($throwOnDuplicates) {
                    throw ValidationException::withMessages(['additional_part_codes' => 'Dodatkowe kody części nie mogą się powtarzać.']);
                }

                continue;
            }

            $seen[$key] = true;
            $normalized[] = $value;
        }

        if (count($normalized) > self::MAX_CODES || count($items) > self::MAX_CODES) {
            throw ValidationException::withMessages(['additional_part_codes' => 'Można dodać maksymalnie 2 dodatkowe kody części.']);
        }

        return $normalized === [] ? null : $normalized;
    }

    /**
     * Filament simple repeaters may dehydrate either as ['ABC'] or as
     * [['code' => 'ABC']] depending on hydration path/version. Convert both
     * shapes to a flat string list before validation to avoid Array to string
     * conversion errors during part edit saves.
     *
     * @return array<int, mixed>
     */
    private static function normalizeInputItems(mixed $codes): array
    {
        if ($codes === null || $codes === '') {
            return [];
        }

        if (! is_array($codes)) {
            return [$codes];
        }

        $items = [];

        foreach ($codes as $code) {
            if (is_array($code)) {
                $items[] = array_key_exists('code', $code) ? $code['code'] : (reset($code) ?: null);

                continue;
            }

            $items[] = $code;
        }

        return $items;
    }
}
