<?php

namespace App\Services\Marketplace;

use App\Models\Part;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class GoogleTranslateService
{
    private const PROVIDER = 'google_translate';
    private const SUPPORTED_TARGETS = ['fr', 'de', 'en'];
    private const DEFAULT_SOURCE = 'pl';

    /**
     * @return array<string, mixed>
     */
    public function readiness(bool $probeApi = true): array
    {
        $blockers = [];
        $warnings = [];
        $enabled = (bool) config('services.google_translate.enabled', false);
        $mode = (string) config('services.google_translate.mode', 'dry_run');
        $projectId = (string) config('services.google_translate.project_id', '');
        $apiKey = (string) config('services.google_translate.key', '');
        $credentialsPath = $this->credentialsPath();
        $credentialsPathConfigured = $credentialsPath !== '';
        $credentialsPathExists = $credentialsPathConfigured && is_file($credentialsPath);
        $apiKeyConfigured = $apiKey !== '';
        $apiTestOk = null;

        if (! $enabled) {
            $blockers[] = 'Google Translate API is disabled. Set GOOGLE_TRANSLATE_ENABLED=true to enable diagnostics calls.';
        }

        if ($mode !== 'dry_run') {
            $blockers[] = 'Google Translate mode must remain dry_run for this safe readiness stage.';
        }

        if (! $apiKeyConfigured) {
            $blockers[] = 'Google Translate API key is missing. Configure GOOGLE_TRANSLATE_API_KEY in the server .env file.';
        }

        if ($credentialsPathConfigured) {
            $warnings[] = 'GOOGLE_TRANSLATE_CREDENTIALS/GOOGLE_APPLICATION_CREDENTIALS is configured but this stage uses GOOGLE_TRANSLATE_API_KEY only.';
        }

        if ($projectId === '') {
            $warnings[] = 'GOOGLE_TRANSLATE_PROJECT_ID is not configured; API-key translation does not require it for this dry-run stage.';
        }

        if ($probeApi && $enabled && $mode === 'dry_run' && $apiKeyConfigured) {
            $probe = $this->sendApiKeyTranslateRequest('test', 'en', 'pl');
            $apiTestOk = $probe['ok'];

            if (! $apiTestOk) {
                $blockers[] = 'Cloud Translation API test request failed: '.$probe['error'];
            }
        }

        return [
            'ok' => $enabled && $mode === 'dry_run' && $apiKeyConfigured && ($probeApi ? $apiTestOk === true : true) && $blockers === [],
            'provider' => self::PROVIDER,
            'api_enabled' => $enabled,
            'api_mode' => $mode,
            'api_key_configured' => $apiKeyConfigured,
            'api_test_ok' => $apiTestOk,
            'project_id_configured' => $projectId !== '',
            'credentials_configured' => $apiKeyConfigured,
            'credentials_path_configured' => $credentialsPathConfigured,
            'credentials_path_exists' => $credentialsPathExists,
            'target_languages_supported' => self::SUPPORTED_TARGETS,
            'source_language_default' => self::DEFAULT_SOURCE,
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function translate(string $text, string $target, ?string $source = null): array
    {
        $target = strtolower($target);
        $source = strtolower($source ?: self::DEFAULT_SOURCE);
        $text = trim($text);
        $blockers = [];
        $warnings = [];

        if (! in_array($target, self::SUPPORTED_TARGETS, true)) {
            $blockers[] = 'Unsupported target language. Allowed: fr, de, en.';
        }

        if ($text === '') {
            $blockers[] = 'Input text is empty.';
        }

        $readiness = $this->readiness(false);
        $blockers = array_merge($blockers, $readiness['blockers']);
        $warnings = array_merge($warnings, $readiness['warnings']);

        $translatedText = null;
        $detectedSourceLanguage = null;

        if ($blockers === []) {
            try {
                $response = $this->sendApiKeyTranslateRequest($text, $target, $source);

                if (! $response['ok']) {
                    $blockers[] = $response['error'];
                } else {
                    $translatedText = $response['translated_text'];
                    $detectedSourceLanguage = $response['detected_source_language'];
                }
            } catch (Throwable $e) {
                $blockers[] = $this->safeExceptionMessage($e);
            }
        }

        return [
            'ok' => $blockers === [],
            'provider' => self::PROVIDER,
            'api_mode' => (string) config('services.google_translate.mode', 'dry_run'),
            'source' => $source,
            'target' => $target,
            'input_text' => $text,
            'translated_text' => $translatedText,
            'detected_source_language' => $detectedSourceLanguage,
            'character_count' => Str::length($text),
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dryRunProduct(int $partId, string $target, ?string $source = null): array
    {
        $part = Part::query()->find($partId);
        $target = strtolower($target);
        $source = strtolower($source ?: self::DEFAULT_SOURCE);
        $blockers = [];
        $warnings = [];
        $fields = [];

        if (! $part) {
            $blockers[] = 'Part not found.';
        } else {
            foreach (['name', 'description', 'short_description'] as $field) {
                $sourceText = trim((string) ($part->{$field} ?? ''));
                if ($sourceText === '') {
                    continue;
                }

                $translation = $this->translate($sourceText, $target, $source);
                $blockers = array_merge($blockers, $translation['blockers']);
                $warnings = array_merge($warnings, $translation['warnings']);

                $fields[$field] = [
                    'source_text' => $sourceText,
                    'translated_text' => $translation['translated_text'],
                    'character_count' => $translation['character_count'],
                    'would_save' => false,
                ];
            }
        }

        if ($part && $fields === []) {
            $warnings[] = 'No translatable product text fields found.';
        }

        return [
            'ok' => $blockers === [],
            'part_id' => $partId,
            'target' => $target,
            'source_language' => $source,
            'fields' => $fields,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @return array{ok: bool, translated_text: ?string, detected_source_language: ?string, error: string}
     */
    private function sendApiKeyTranslateRequest(string $text, string $target, string $source): array
    {
        $apiKey = (string) config('services.google_translate.key', '');

        if ($apiKey === '') {
            return [
                'ok' => false,
                'translated_text' => null,
                'detected_source_language' => null,
                'error' => 'GOOGLE_TRANSLATE_API_KEY is not configured.',
            ];
        }

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('services.google_translate.timeout', 10))
                ->post('https://translation.googleapis.com/language/translate/v2?key='.urlencode($apiKey), [
                    'q' => $text,
                    'source' => $source,
                    'target' => $target,
                    'format' => 'text',
                ]);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'translated_text' => null,
                'detected_source_language' => null,
                'error' => $this->safeExceptionMessage($e),
            ];
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'translated_text' => null,
                'detected_source_language' => null,
                'error' => 'HTTP '.$response->status().'.',
            ];
        }

        $translation = $response->json('data.translations.0');

        if (! is_array($translation) || blank($translation['translatedText'] ?? null)) {
            return [
                'ok' => false,
                'translated_text' => null,
                'detected_source_language' => null,
                'error' => 'Response did not include translated text.',
            ];
        }

        return [
            'ok' => true,
            'translated_text' => html_entity_decode((string) $translation['translatedText'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'detected_source_language' => $translation['detectedSourceLanguage'] ?? null,
            'error' => '',
        ];
    }

    private function credentialsPath(): string
    {
        return (string) (config('services.google_translate.credentials_path') ?: env('GOOGLE_APPLICATION_CREDENTIALS', ''));
    }


    private function safeExceptionMessage(Throwable $e): string
    {
        return Str::limit(preg_replace('/(key|token|secret|credential|assertion)[^\s,;]*/i', '[redacted_secret]', $e->getMessage()) ?: 'Google Translate request failed.', 300, '...');
    }
}
