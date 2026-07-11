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
     * @param  array<int, mixed>|null  $codes
     * @return array<int, string>|null
     */
    public static function normalize(?array $codes, mixed $mainPartCode = null, bool $throwOnDuplicates = true): ?array
    {
        $main = trim((string) ($mainPartCode ?? ''));
        $normalized = [];
        $seen = [];

        foreach (array_slice($codes ?? [], 0, self::MAX_CODES + 1) as $code) {
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

        if (count($normalized) > self::MAX_CODES || count($codes ?? []) > self::MAX_CODES) {
            throw ValidationException::withMessages(['additional_part_codes' => 'Można dodać maksymalnie 2 dodatkowe kody części.']);
        }

        return $normalized === [] ? null : $normalized;
    }
}
