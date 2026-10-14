<?php

namespace App\Services\Storefront;

/** Hide identifiers/numbers from the provider, and reject missing/duplicated/altered markers. */
class JarekTranslationTechnicalTokens
{
    public const VERSION = 2;

    public function mask(string $text, array $parameters = []): array
    {
        $technicalValues = [];
        foreach ($parameters as $parameter) {
            if (! preg_match('/kod|code|numer|number|vin|oem|symbol|marka|brand|make|model/iu', (string) ($parameter['name'] ?? ''))) {
                continue;
            }
            foreach ((array) ($parameter['values'] ?? []) as $value) {
                if (is_string($value)) {
                    $technicalValues = array_merge($technicalValues, preg_split('/\\s+/', trim($value)) ?: []);
                }
            }
        }
        $brands = ['VW', 'Volkswagen', 'Audi', 'BMW', 'Mini', 'Mercedes', 'Mercedes-Benz', 'Peugeot', 'Citroën', 'Citroen', 'Renault', 'Opel', 'Ford', 'Seat', 'Skoda', 'Škoda', 'Volvo', 'Toyota', 'Nissan', 'Fiat', 'Honda', 'Mazda', 'Hyundai', 'Kia', 'Dacia', 'Suzuki', 'Mitsubishi', 'Jeep', 'Porsche', 'Jaguar', 'Chevrolet', 'Iveco'];
        $technicalValues = array_merge($technicalValues, $brands);
        // Preserve the vehicle model following a recognized brand, never descriptive Polish words.
        $brandPattern = implode('|', array_map(fn ($value) => preg_quote($value, '/'), $brands));
        preg_match_all('/\b(?:'.$brandPattern.')\s+([\p{L}\p{N}]+(?:[-.][\p{L}\p{N}]+)*)/iu', $text, $models);
        foreach ($models[1] as $model) {
            if (! preg_match('/^(?:skrzynia|biegów|biegow|regenerowan[ay]|tylny|most|bliźniak|blizniak|silnik|now[ay]|używan[ay]|uzywan[ay])$/iu', $model)) {
                $technicalValues[] = $model;
            }
        }
        $tokens = [];
        // Opaque alphanumeric IDs do not expose the translatable word JAREK or separator underscores.
        $prefix = 'ZXQ'.strtoupper(substr(hash('sha256', $text), 0, 8));
        while (stripos($text, $prefix) !== false) {
            $prefix .= 'X';
        }
        $masked = preg_replace_callback('/(?<![\p{L}\p{N}])[\p{L}\p{N}]+(?:[.\/_-][\p{L}\p{N}]+)*(?![\p{L}\p{N}])/u', function ($match) use (&$tokens, $prefix, $technicalValues): string {
            $value = $match[0];
            if (! in_array(mb_strtolower($value), array_map('mb_strtolower', $technicalValues), true) && ! preg_match('/\p{N}/u', $value) && ! in_array($value, ['DSG', 'DCT', 'CVT', 'ZF', 'OEM', 'VIN', 'OE', 'BVA', 'BVM'], true)
                && ! (preg_match('/^[A-Z]{2,5}$/', $value) && ! in_array($value, ['DO', 'NA', 'OD', 'ZA', 'PO', 'BEZ', 'NOWA', 'NOWY', 'STARA', 'STARY', 'TYPU', 'BIEGI', 'TYLNY', 'PRZOD', 'TYL', 'DWA'], true))) {
                return $value;
            }
            $marker = $prefix.str_pad((string) count($tokens), 4, '0', STR_PAD_LEFT).'X';
            $tokens[$marker] = $value;

            return $marker;
        }, $text);

        return ['text' => $masked ?? $text, 'tokens' => $tokens];
    }

    public function restore(string $output, array $tokens): ?string
    {
        foreach ($tokens as $marker => $value) {
            $pattern = '/(?<![A-Za-z0-9])'.preg_quote($marker, '/').'(?![A-Za-z0-9])/i';
            if (preg_match_all($pattern, $output) !== 1) {
                return null;
            }
        }
        // Only letter case may differ; altered digits, missing, duplicate or unknown markers are rejected.
        $restored = $output;
        foreach ($tokens as $marker => $value) {
            $restored = preg_replace_callback('/(?<![A-Za-z0-9])'.preg_quote($marker, '/').'(?![A-Za-z0-9])/i', fn () => $value, $restored);
        }

        return preg_match('/ZXQ[A-Z0-9]{8,24}/i', str_ireplace(array_keys($tokens), '', $output)) ? null : $restored;
    }

    /** Only markers and technical code values; never a raw description/provider response/credentials. */
    public function diagnostic(string $output, array $tokens, string $field): array
    {
        preg_match_all('/ZXQ[A-Z0-9]{1,24}/i', $output, $matches);
        $observed = array_slice(array_values(array_unique($matches[0])), 0, 2);
        preg_match_all('/(?<![A-Za-z0-9])[A-Z][A-Z0-9]{1,15}(?![A-Za-z0-9])/', $output, $codes);
        $responseCodes = array_slice(array_values(array_filter(array_unique($codes[0]), fn ($code) => preg_match('/[0-9]/', $code) && ! str_starts_with($code, 'ZXQ'))), 0, 2);
        $issues = [];
        foreach ($tokens as $marker => $value) {
            $count = preg_match_all('/(?<![A-Za-z0-9])'.preg_quote($marker, '/').'(?![A-Za-z0-9])/i', $output);
            if ($count === 1) {
                continue;
            }
            // Long bare numbers may be contact data; do not expose them in diagnostics.
            $safe = preg_match('/^[\p{L}\p{N}.\/_-]{1,24}$/u', $value) && ! preg_match('/^[0-9]{7,}$/', $value) ? $value : '[identifier redacted]';
            $issues[] = ['expected_marker' => $marker, 'source_code' => $safe, 'occurrences' => $count];
            if (count($issues) === 1) {
                break;
            }
        }

        return ['version' => self::VERSION, 'field' => $field, 'issues' => $issues, 'response_markers' => $observed, 'response_codes' => $responseCodes];
    }

    public static function storedDiagnostic(?string $message): ?array
    {
        $diagnostic = json_decode((string) $message, true)['diagnostic'] ?? null;
        if (! is_array($diagnostic) || ($diagnostic['version'] ?? null) !== self::VERSION || ! in_array($diagnostic['field'] ?? null, ['title', 'description'], true)) {
            return null;
        }
        $issues = [];
        foreach (array_slice((array) ($diagnostic['issues'] ?? []), 0, 1) as $issue) {
            if (! is_array($issue) || ! preg_match('/^ZXQ[A-Z0-9]{8,24}$/', (string) ($issue['expected_marker'] ?? ''))
                || ! is_int($issue['occurrences'] ?? null) || $issue['occurrences'] < 0 || $issue['occurrences'] > 100) {
                continue;
            }
            $source = (string) ($issue['source_code'] ?? '');
            if (! preg_match('/^[\p{L}\p{N}.\/_-]{1,24}$/u', $source) || preg_match('/^[0-9]{7,}$/', $source)) {
                $source = '[identifier redacted]';
            }
            $issues[] = ['expected_marker' => $issue['expected_marker'], 'source_code' => $source, 'occurrences' => $issue['occurrences']];
        }

        return ['version' => self::VERSION, 'field' => $diagnostic['field'], 'issues' => $issues,
            'response_markers' => array_slice(array_values(array_filter((array) ($diagnostic['response_markers'] ?? []), fn ($v) => is_string($v) && preg_match('/^ZXQ[A-Z0-9]{1,24}$/i', $v))), 0, 2),
            'response_codes' => array_slice(array_values(array_filter((array) ($diagnostic['response_codes'] ?? []), fn ($v) => is_string($v) && preg_match('/^[A-Z][A-Z0-9]{1,15}$/', $v) && preg_match('/[0-9]/', $v))), 0, 2)];
    }
}
