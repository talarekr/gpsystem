<?php

namespace App\Services\Marketplace;

use App\Models\Part;

class EbayTitleSanitizer
{
    public const LIMIT = 80;
    private const LOW_VALUE_PATTERNS = [
        '/\bZustand\s+perfekt\b/iu', '/\bperfekter\s+Zustand\b/iu', '/\bsehr\s+guter\s+Zustand\b/iu',
        '/\bperfekt\b/iu', '/\bkompletter?\b/iu', '/\bkomplett\b/iu',
    ];

    private const DESCRIPTIVE_POLISH_TOKENS = [
        'KOMPLETNY', 'SPRAWNY', 'STAN', 'IDEALNY', 'UŻYWANY', 'UZYWANY',
        'LEWY', 'PRAWY', 'PRZÓD', 'PRZOD', 'TYŁ', 'TYL',
    ];

    public function sanitizeForEbayDe(Part $part, ?string $translatedTitle, ?string $originalPlTitle = null): array
    {
        $original = (string) ($originalPlTitle ?? $part->name ?? '');
        $before = trim((string) ($translatedTitle ?: $original));
        $protected = $this->protectedTokens($part, $original.' '.$before);
        $removed = [];
        $cleaned = $this->normalize($before);

        if (mb_strlen($cleaned) <= self::LIMIT) {
            $preserved = $this->tokensPreserved($protected, $cleaned);

            return [
                'final_title' => $cleaned,
                'cleaned_title' => $cleaned,
                'ok' => true,
                'blocker' => null,
                'diagnostics' => [
                    'original_pl_title' => $original,
                    'translated_title_before_cleanup' => $before,
                    'cleaned_title' => $cleaned,
                    'final_title' => $cleaned,
                    'original_length' => mb_strlen($original),
                    'translated_length' => mb_strlen($before),
                    'final_length' => mb_strlen($cleaned),
                    'title_limit' => self::LIMIT,
                    'removed_tokens' => [],
                    'protected_tokens' => $protected,
                    'protected_tokens_preserved' => $preserved,
                    'cleanup_applied' => $cleaned !== $before,
                    'minimal_cleanup_only' => true,
                ],
            ];
        }

        $allowsYearLabel = (bool) preg_match('/\b(rok|rocznik|rok\s+produkcji|produkcji|prod\.)\b/iu', $original);

        if (! $allowsYearLabel) {
            $cleaned = preg_replace_callback('/\b(Baujahr|Bauj\.|Modelljahr|Erstzulassung)\s*:?\s*(?=(?:19|20)\d{2}\b)/iu', function (array $m) use (&$removed): string {
                $removed[] = trim($m[0]);
                return '';
            }, $cleaned) ?? $cleaned;
        }

        $cleaned = $this->normalize($cleaned);
        $final = $cleaned;
        if (mb_strlen($final) > self::LIMIT) {
            foreach (self::LOW_VALUE_PATTERNS as $pattern) {
                $next = preg_replace_callback($pattern, function (array $m) use (&$removed): string { $removed[] = $m[0]; return ''; }, $final) ?? $final;
                $final = $this->normalize($next);
                if (mb_strlen($final) <= self::LIMIT) break;
            }
        }
        if (mb_strlen($final) > self::LIMIT) $final = $this->safeTrim($final, $protected);

        $preserved = $this->tokensPreserved($protected, $final);
        return [
            'final_title' => $final,
            'cleaned_title' => $cleaned,
            'ok' => mb_strlen($final) <= self::LIMIT && $preserved,
            'blocker' => mb_strlen($final) > self::LIMIT || ! $preserved ? 'ebay_title_too_long_after_cleanup' : null,
            'diagnostics' => [
                'original_pl_title' => $original,
                'translated_title_before_cleanup' => $before,
                'cleaned_title' => $cleaned,
                'final_title' => $final,
                'original_length' => mb_strlen($original),
                'translated_length' => mb_strlen($before),
                'final_length' => mb_strlen($final),
                'title_limit' => self::LIMIT,
                'removed_tokens' => array_values(array_unique(array_filter($removed))),
                'protected_tokens' => $protected,
                'protected_tokens_preserved' => $preserved,
                'cleanup_applied' => $final !== $before || $cleaned !== $before || $removed !== [],
            ],
        ];
    }

    private function normalize(string $title): string
    {
        $title = preg_replace('/\s+,/u', ',', $title) ?? $title;
        $title = preg_replace('/,\s*,+/u', ',', $title) ?? $title;
        $title = preg_replace('/\s*([-–—])\s*(?=,|$)/u', ' ', $title) ?? $title;
        $title = preg_replace('/(?:^|\s)[,;:-]+(?:\s|$)/u', ' ', $title) ?? $title;
        $title = preg_replace('/\s+/u', ' ', $title) ?? $title;
        $title = preg_replace('/\s*,\s*/u', ', ', $title) ?? $title;
        return trim($title, " \t\n\r\0\x0B,;-–—");
    }

    private function safeTrim(string $title, array $protected): string
    {
        $cut = rtrim(mb_substr($title, 0, self::LIMIT));
        $cut = preg_replace('/\s+\S*$/u', '', $cut) ?: $cut;
        $cut = $this->normalize($cut);
        foreach ($protected as $token) {
            if (stripos($title, $token) !== false && stripos($cut, $token) === false && mb_strlen($cut.' '.$token) <= self::LIMIT) {
                $cut = $this->normalize($cut.' '.$token);
            }
        }
        return $cut;
    }

    private function protectedTokens(Part $part, string $text): array
    {
        $tokens = array_filter([(string) ($part->part_number ?? ''), (string) data_get($part->vehicle_snapshot, 'engine_code', ''), (string) data_get($part->vehicle_snapshot, 'gearbox_code', '')]);
        preg_match_all('/\b[A-Z0-9]{3,20}\b/u', $text, $matches);
        foreach ($matches[0] ?? [] as $token) {
            $token = trim($token);
            if ($this->isDescriptivePolishToken($token)) continue;
            if (preg_match('/^(?=.*[A-Z])(?=.*\d)|[A-Z]{3,5}$/u', $token)) $tokens[] = $token;
        }

        return array_values(array_unique(array_map('trim', array_filter($tokens, fn (string $token): bool => ! $this->isDescriptivePolishToken($token)))));
    }

    private function isDescriptivePolishToken(string $token): bool
    {
        return in_array(mb_strtoupper(trim($token), 'UTF-8'), self::DESCRIPTIVE_POLISH_TOKENS, true);
    }

    private function tokensPreserved(array $tokens, string $title): bool
    {
        foreach ($tokens as $token) if (stripos($title, $token) === false) return false;
        return true;
    }
}
