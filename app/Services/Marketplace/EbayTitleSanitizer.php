<?php

namespace App\Services\Marketplace;

use App\Models\Part;

class EbayTitleSanitizer
{
    public const LIMIT = 80;

    public function sanitizeForEbayDe(Part $part, ?string $translatedTitle, ?string $originalPlTitle = null): array
    {
        $original = (string) ($originalPlTitle ?? $part->name ?? '');
        $translated = trim((string) ($translatedTitle ?: $original));
        $cleaned = $this->normalize($translated);
        $translatedLength = mb_strlen($cleaned);
        $suggested = $translatedLength > self::LIMIT ? $this->safeTrim($cleaned) : null;
        $tokensLost = $suggested !== null ? $this->lostWords($cleaned, $suggested) : [];

        return [
            'final_title' => $cleaned,
            'cleaned_title' => $cleaned,
            'translated_title' => $cleaned,
            'suggested_short_title' => $suggested,
            'ok' => $translatedLength <= self::LIMIT,
            'blocker' => $translatedLength > self::LIMIT ? 'ebay_title_needs_review' : null,
            'diagnostics' => [
                'original_source_title' => $original,
                'original_pl_title' => $original,
                'translated_title' => $cleaned,
                'translated_title_before_cleanup' => $translated,
                'cleaned_title' => $cleaned,
                'final_title' => $cleaned,
                'suggested_short_title' => $suggested,
                'original_length' => mb_strlen($original),
                'translated_length' => $translatedLength,
                'final_length' => $translatedLength,
                'suggested_short_title_length' => $suggested !== null ? mb_strlen($suggested) : null,
                'title_limit' => self::LIMIT,
                'exceeds_limit' => $translatedLength > self::LIMIT,
                'title_was_shortened' => $suggested !== null,
                'requires_manual_review' => $translatedLength > self::LIMIT,
                'removed_tokens' => [],
                'tokens_lost' => $tokensLost,
                'potentially_lost_words' => $tokensLost,
                'protected_tokens' => [],
                'protected_tokens_preserved' => true,
                'cleanup_applied' => $cleaned !== $translated,
                'minimal_cleanup_only' => true,
            ],
        ];
    }

    private function normalize(string $title): string
    {
        $title = preg_replace('/\s+/u', ' ', $title) ?? $title;
        return trim($title);
    }

    private function safeTrim(string $title): string
    {
        $cut = rtrim(mb_substr($title, 0, self::LIMIT));
        $wordCut = preg_replace('/\s+\S*$/u', '', $cut);
        return $this->normalize($wordCut ?: $cut);
    }

    /** @return array<int, string> */
    private function lostWords(string $original, string $suggested): array
    {
        $originalWords = preg_split('/\s+/u', $original) ?: [];
        $suggestedWords = preg_split('/\s+/u', $suggested) ?: [];
        $lost = array_slice($originalWords, count($suggestedWords));

        return array_values(array_filter(array_map('trim', $lost), fn (string $word): bool => $word !== ''));
    }
}
