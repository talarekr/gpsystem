<?php

namespace App\Services\Storefront;

use App\Models\Part;
use App\Models\PartCategory;
use App\Models\StorefrontTranslation;
use App\Services\Marketplace\GoogleTranslateService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

class FrenchCatalogTranslationService
{
    public const BACKOFF = [30, 120, 300];

    public function enabled(): bool
    {
        return (bool) config('storefront-translations.fr_enabled', false);
    }

    /** Null means eligible. Reviewed records are always protected. */
    public function skipReason(Part|PartCategory $record, array $options = []): ?string
    {
        $translation = $record->translationForLocale('fr');
        if ($translation?->status === 'reviewed') {
            return 'reviewed_protected';
        }
        if ($translation?->isReady() && $translation->matchesSourceHash($record->translationSourceHash())) {
            return 'current';
        }
        if ($translation?->status === 'failed') {
            return ($options['include_failed'] ?? false) ? null : 'failed_requires_include_failed';
        }
        if (! $translation || $translation->status === 'missing') {
            return null;
        }
        if ($translation && ($translation->status === 'needs_update' || ! $translation->matchesSourceHash($record->translationSourceHash()))) {
            return ($options['include_needs_update'] ?? false) ? null : 'needs_update_requires_include_needs_update';
        }
        if (($options['only_missing'] ?? false) && $translation->status !== 'queued') {
            return 'not_missing';
        }

        return null;
    }

    public function sourceFields(Part|PartCategory $record): array
    {
        return array_filter($record->translationSource(), fn (string $text): bool => trim($text) !== '');
    }

    /** Synchronous full-catalog runner: bounded retries, persisted progress after each record. */
    public function translateWithRetries(string $type, int $id, array $options = []): array
    {
        $characters = 0;
        for ($attempt = 0; ; $attempt++) {
            $result = $this->translate($type, $id, $options);
            $characters += $result['chars_translated'];
            if (! $result['retryable'] || $attempt >= count(self::BACKOFF)) {
                $result['chars_translated'] = $characters;

                return $result;
            }
            Sleep::for(self::BACKOFF[$attempt])->seconds();
            // Only this execution's transient failure can be retried automatically.
            $options['include_failed'] = true;
        }
    }

    /** One provider attempt, also used by queued jobs. No provider payloads enter logs/errors. */
    public function translate(string $type, int $id, array $options = []): array
    {
        if (! $this->enabled()) {
            return $this->result('skipped', 'translations_disabled');
        }
        $model = match ($type) {
            'parts' => Part::class,
            'categories' => PartCategory::class,
            default => throw new \InvalidArgumentException('Invalid catalog type.'),
        };
        // Use the shared production cache store; never hold a database transaction across HTTP.
        $lock = Cache::lock('storefront:fr:'.$type.':'.$id, 600);
        if (! $lock->get()) {
            return $this->result('skipped', 'busy');
        }

        try {
            $claim = DB::transaction(function () use ($model, $id, $options): array {
                $record = $model::query()->lockForUpdate()->find($id);
                if (! $record) {
                    return ['result' => $this->result('skipped', 'record_missing')];
                }
                $translation = $record->translations()->where('locale', 'fr')->lockForUpdate()->first();
                $record->setRelation('translations', collect($translation ? [$translation] : []));
                if ($reason = $this->skipReason($record, $options)) {
                    if ($reason === 'needs_update_requires_include_needs_update' && $translation->status !== 'needs_update') {
                        $translation->update(['status' => 'needs_update']);
                    }

                    return ['result' => $this->loggedResult($record, $translation, $this->result('skipped', $reason))];
                }
                $source = $this->sourceFields($record);
                if ($source === []) {
                    return ['result' => $this->loggedResult($record, $translation, $this->result('skipped', 'empty_source'))];
                }
                $translation ??= $record->translations()->make(['locale' => 'fr']);
                $translation->fill([
                    'provider' => 'google_translate', 'source_hash' => $record->translationSourceHash(),
                    'status' => 'queued', 'attempts' => ($translation->attempts ?? 0) + 1,
                    'translated_at' => null, 'reviewed_at' => null, 'error_code' => null, 'error_message' => null,
                ])->save();

                return ['record' => $record, 'source' => $source, 'hash' => $record->translationSourceHash(), 'attempts' => $translation->attempts];
            });
            if (isset($claim['result'])) {
                return $claim['result'];
            }

            $translated = array_fill_keys($claim['record']->translationSourceFields(), null);
            $characters = 0;
            $errorCode = null;
            $errorMessage = null;
            $retryable = false;
            foreach ($claim['source'] as $field => $text) {
                $output = '';
                // Google v2 has request size limits. Preserve boundary whitespace across segments.
                foreach ($this->segments($text) as $segment) {
                    if (trim($segment) === '') {
                        $output .= $segment;

                        continue;
                    }
                    if (! $this->enabled()) {
                        $errorCode = 'translations_disabled';
                        $errorMessage = 'French catalog translation is disabled.';
                        break 2;
                    }
                    try {
                        $response = app(GoogleTranslateService::class)->translate($segment, 'fr', 'pl');
                    } catch (Throwable) {
                        $response = ['ok' => false];
                    }
                    if (! ($response['ok'] ?? false) || trim((string) ($response['translated_text'] ?? '')) === '') {
                        $http = (int) ($response['api_test_http_status'] ?? 0);
                        $errorCode = $http ? 'google_http_'.$http : 'google_translate_failed';
                        $errorMessage = $http ? 'Google Translate returned HTTP '.$http.'.' : 'Google Translate did not return a translation; check provider configuration/connectivity.';
                        $retryable = $http === 429 || ($http >= 500 && $http <= 599);
                        break 2;
                    }
                    $characters += mb_strlen(trim($segment));
                    preg_match('/^\s*/u', $segment, $leading);
                    preg_match('/\s*$/u', $segment, $trailing);
                    $output .= $leading[0].trim($response['translated_text']).$trailing[0];
                }
                if ($field === 'name' && mb_strlen($output) > 255) {
                    $errorCode = 'translated_name_too_long';
                    $errorMessage = 'Translated name exceeds the translation column limit.';
                    break;
                }
                $translated[$field] = $output;
            }

            return DB::transaction(function () use ($model, $id, $claim, $translated, $characters, $errorCode, $errorMessage, $retryable): array {
                $record = $model::query()->lockForUpdate()->find($id);
                if (! $record) {
                    return $this->result('skipped', 'record_missing', $characters);
                }
                $translation = $record->translations()->where('locale', 'fr')->lockForUpdate()->first();
                // A reviewer or another attempt may have won while the provider was running.
                if (! $translation || $translation->status !== 'queued' || $translation->attempts !== $claim['attempts'] || ! $translation->matchesSourceHash($claim['hash'])) {
                    return $this->loggedResult($record, $translation, $this->result('skipped', 'translation_changed', $characters));
                }
                if (! hash_equals($claim['hash'], $record->translationSourceHash())) {
                    $translation->fill(['status' => 'needs_update', 'error_code' => 'source_changed', 'error_message' => 'Source changed during translation; rerun with --include-needs-update.'])->save();

                    return $this->loggedResult($record, $translation, $this->result('failed', 'source_changed', $characters));
                }
                if ($errorCode !== null) {
                    $translation->fill(['status' => 'failed', 'error_code' => $errorCode, 'error_message' => $errorMessage])->save();

                    return $this->loggedResult($record, $translation, $this->result('failed', $errorCode, $characters, $retryable));
                }
                $translation->fill($translated + ['status' => 'translated', 'translated_at' => now(), 'error_code' => null, 'error_message' => null])->save();

                return $this->loggedResult($record, $translation, $this->result('translated', null, $characters));
            });
        } finally {
            $lock->release();
        }
    }

    private function segments(string $text): array
    {
        $segments = [];
        while (mb_strlen($text) > 4500) {
            $prefix = mb_substr($text, 0, 4500);
            $boundary = mb_strrpos($prefix, ' ');
            $length = $boundary !== false && $boundary > 2250 ? $boundary + 1 : 4500;
            $segments[] = mb_substr($text, 0, $length);
            $text = mb_substr($text, $length);
        }
        if ($text !== '') {
            $segments[] = $text;
        }

        return $segments;
    }

    private function result(string $status, ?string $reason = null, int $characters = 0, bool $retryable = false): array
    {
        return ['status' => $status, 'reason' => $reason, 'chars_translated' => $characters, 'retryable' => $retryable];
    }

    private function loggedResult(Part|PartCategory $record, ?StorefrontTranslation $translation, array $result): array
    {
        Log::info('storefront.fr_catalog_translation', [
            $record instanceof Part ? 'part_id' : 'category_id' => $record->id,
            'sku' => $record instanceof Part ? $record->sku : null,
            'locale' => 'fr', 'provider' => 'google_translate', 'source_hash' => $record->translationSourceHash(),
            'status' => $translation?->status, 'chars_count' => $result['chars_translated'],
            'skipped_reason' => $result['status'] === 'skipped' ? $result['reason'] : null,
            'error_code' => $translation?->error_code, 'attempts' => $translation?->attempts ?? 0,
        ]);

        return $result;
    }
}
