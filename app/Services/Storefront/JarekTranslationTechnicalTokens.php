<?php

namespace App\Services\Storefront;

/** Hide identifiers/numbers from the provider, and reject missing/duplicated/altered markers. */
class JarekTranslationTechnicalTokens
{
    public function mask(string $text, array $parameters = []): array
    {
        $technicalValues = [];
        foreach ($parameters as $parameter) {
            if (! preg_match('/kod|code|numer|number|vin|oem|symbol/iu', (string) ($parameter['name'] ?? ''))) {
                continue;
            }
            foreach ((array) ($parameter['values'] ?? []) as $value) {
                if (is_string($value)) {
                    $technicalValues = array_merge($technicalValues, preg_split('/\\s+/', trim($value)) ?: []);
                }
            }
        }
        $tokens = [];
        $prefix = '__JAREK_'.substr(hash('sha256', $text), 0, 12).'_';
        while (str_contains($text, $prefix)) {
            $prefix .= 'X';
        }
        $masked = preg_replace_callback('/(?<![\p{L}\p{N}])[\p{L}\p{N}]+(?:[.\/_-][\p{L}\p{N}]+)*(?![\p{L}\p{N}])/u', function ($match) use (&$tokens, $prefix, $technicalValues): string {
            $value = $match[0];
            if (! in_array($value, $technicalValues, true) && ! preg_match('/\p{N}/u', $value) && ! in_array($value, ['DSG', 'DCT', 'CVT', 'ZF', 'OEM', 'VIN', 'OE', 'BVA', 'BVM'], true)
                && ! (preg_match('/^[A-Z]{2,5}$/', $value) && ! in_array($value, ['DO', 'NA', 'OD', 'ZA', 'PO', 'BEZ', 'NOWA', 'NOWY', 'STARA', 'STARY', 'TYPU', 'BIEGI', 'TYLNY', 'PRZOD', 'TYL', 'DWA'], true))) {
                return $value;
            }
            $marker = $prefix.str_pad((string) count($tokens), 4, '0', STR_PAD_LEFT).'__';
            $tokens[$marker] = $value;

            return $marker;
        }, $text);

        return ['text' => $masked ?? $text, 'tokens' => $tokens];
    }

    public function restore(string $output, array $tokens): ?string
    {
        foreach ($tokens as $marker => $value) {
            if (substr_count($output, $marker) !== 1) {
                return null;
            }
        }

        return strtr($output, $tokens);
    }
}
