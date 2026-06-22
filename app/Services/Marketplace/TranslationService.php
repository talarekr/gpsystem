<?php

namespace App\Services\Marketplace;

class TranslationService
{
    public function isGoogleTranslateConfigured(): bool
    {
        return filled(config('services.google_translate.key'))
            || filled(config('services.google_translate.credentials_path'))
            || filled(env('GOOGLE_APPLICATION_CREDENTIALS'));
    }

    public function targetLanguageForChannel(string $channel): ?string
    {
        return match ($channel) {
            'ebay_de' => 'DE',
            'ebay_fr' => 'FR',
            default => null,
        };
    }

    /**
     * Placeholder contract for a later explicit dry-run translation step.
     *
     * @param array<string, string|null> $fields
     * @return array<string, string|null>
     */
    public function translatePreview(array $fields, string $sourceLanguage, string $targetLanguage): array
    {
        return $fields;
    }
}
