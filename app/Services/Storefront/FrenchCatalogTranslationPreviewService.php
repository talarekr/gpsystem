<?php

namespace App\Services\Storefront;

use App\Models\Part;
use App\Models\PartCategory;
use App\Services\Marketplace\GoogleTranslateService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class FrenchCatalogTranslationPreviewService
{
    public const KEY = 'storefront:fr:admin:dry-run:';

    public const OPTIONS = ['only_missing' => true, 'include_needs_update' => false, 'include_failed' => false];

    public function schemaReady(): bool
    {
        return Schema::hasTable('part_translations') && Schema::hasTable('category_translations');
    }

    /** Configuration inspection only: never probe the provider or return credentials. */
    public function overview(int $actorId): array
    {
        $provider = app(GoogleTranslateService::class)->readiness(false);

        return [
            'enabled' => app(FrenchCatalogTranslationService::class)->enabled(),
            'provider_ready' => (bool) $provider['ok'],
            'provider' => [
                'api_enabled' => (bool) $provider['api_enabled'],
                'api_key_configured' => (bool) $provider['api_key_configured'],
                'api_mode' => $provider['api_mode'] === 'dry_run' ? 'dry_run' : 'other',
            ],
            'schema_ready' => $this->schemaReady(),
            'latest_dry_run' => $this->saved($actorId),
            'catalog' => $this->summary(),
        ];
    }

    public function saved(int $actorId): ?array
    {
        return Cache::get(self::KEY.$actorId);
    }

    /** Only preview metadata is cached; no catalog writes, provider resolution or API calls. */
    public function dryRun(int $actorId): array
    {
        abort_unless($this->schemaReady(), 422, 'Brak tabel tłumaczeń. Uruchom migracje Etapu 2A.');
        $report = $this->summary() + [
            'dry_run_id' => (string) Str::uuid(),
            'created_at' => now()->toISOString(),
            'consumed' => false,
        ];
        // Do not let a concurrent Start overwrite a newer preview while consuming it.
        Cache::lock(FrenchCatalogTranslationAdminRunner::MUTATION_LOCK, 10)->block(3, function () use ($actorId, $report): void {
            Cache::forever(self::KEY.$actorId, $report);
        });

        return $report;
    }

    /** Same source fields and selection guards as storefront:fr-translations-translate-all. */
    private function summary(): array
    {
        $service = app(FrenchCatalogTranslationService::class);
        $report = ['total' => 0, 'estimated_characters' => 0,
            'inventory' => ['translated' => 0, 'missing' => 0, 'needs_update' => 0, 'failed' => 0, 'skipped' => 0, 'queued' => 0],
            'failed_examples' => [],
        ];
        foreach (['products' => Part::class, 'categories' => PartCategory::class] as $kind => $model) {
            $counts = ['total' => 0, 'eligible' => 0, 'fields' => 0, 'estimated_characters' => 0,
                'skipped' => 0, 'skipped_reasons' => [], 'examples' => [], 'max_id' => (int) $model::query()->max('id')];
            $columns = array_merge(['id'], (new $model)->translationSourceFields(), $kind === 'products' ? ['sku'] : []);
            $query = $model::query()->select($columns)->where('id', '<=', $counts['max_id']);
            $schemaReady = $this->schemaReady();
            if ($schemaReady) {
                $foreignKey = $kind === 'products' ? 'part_id' : 'category_id';
                $query->with(['translations' => fn ($q) => $q->select(['id', $foreignKey, 'locale', 'status', 'source_hash', 'error_code'])->where('locale', 'fr')]);
            }
            $query->chunkById(100, function ($records) use ($service, $schemaReady, &$counts, &$report): void {
                foreach ($records as $record) {
                    if (! $schemaReady) {
                        $record->setRelation('translations', collect());
                    }
                    $counts['total']++;
                    $translation = $record->translationForLocale('fr');
                    $status = $translation?->status ?? 'missing';
                    if ($status === 'reviewed') {
                        $status = 'translated';
                    } elseif ($status === 'translated' && ! $translation->matchesSourceHash($record->translationSourceHash())) {
                        $status = 'needs_update';
                    }
                    if (array_key_exists($status, $report['inventory'])) {
                        $report['inventory'][$status]++;
                    }
                    $fields = $service->sourceFields($record);
                    $reason = $service->skipReason($record, self::OPTIONS) ?? ($fields === [] ? 'empty_source' : null);
                    $characters = array_sum(array_map(fn ($text) => mb_strlen(trim($text)), $fields));
                    $identity = [$record instanceof Part ? 'part_id' : 'category_id' => (int) $record->id];
                    if ($record instanceof Part) {
                        $identity['sku'] = $record->sku;
                    }
                    if ($status === 'failed' && count($report['failed_examples']) < 20) {
                        $report['failed_examples'][] = $identity + self::safeError($translation?->error_code);
                    }
                    if ($reason !== null) {
                        $counts['skipped']++;
                        $counts['skipped_reasons'][$reason] = ($counts['skipped_reasons'][$reason] ?? 0) + 1;
                    } else {
                        $counts['eligible']++;
                        $counts['fields'] += count($fields);
                        $counts['estimated_characters'] += $characters;
                    }
                    if (count($counts['examples']) < 10) {
                        $counts['examples'][] = $identity + ['fields' => count($fields), 'estimated_characters' => $characters, 'skip_reason' => $reason];
                    }
                }
            });
            $report[$kind] = $counts;
            $report['total'] += $counts['total'];
            $report['estimated_characters'] += $counts['estimated_characters'];
            $report['inventory']['skipped'] += $counts['skipped'];
        }

        return $report;
    }

    /** Historical error messages are untrusted; display only controlled descriptions. */
    public static function safeError(?string $code): array
    {
        if (preg_match('/^google_http_([1-5][0-9]{2})$/', $code ?? '', $matches)) {
            return ['error_code' => $code, 'error_message' => 'Google Translate zwrócił HTTP '.$matches[1].'.'];
        }
        $messages = [
            'source_changed' => 'Źródło zmieniło się podczas tłumaczenia.',
            'translations_disabled' => 'Tłumaczenia FR są wyłączone.',
            'translated_name_too_long' => 'Przetłumaczona nazwa przekracza limit długości.',
            'google_translate_failed' => 'Google Translate nie zwrócił tłumaczenia. Sprawdź konfigurację i połączenie.',
            'interrupted_attempt' => 'Próba została przerwana. Sprawdź zapis tłumaczenia przed ponowieniem.',
        ];
        $safeCode = array_key_exists($code ?? '', $messages) ? $code : 'translation_failed';

        return ['error_code' => $safeCode, 'error_message' => $messages[$safeCode] ?? 'Tłumaczenie nie powiodło się. Sprawdź konfigurację providera.'];
    }
}
