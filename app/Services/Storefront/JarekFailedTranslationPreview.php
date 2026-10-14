<?php

namespace App\Services\Storefront;

use App\Models\JarekGearbox;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

/** Failed-only read: no full catalog dry-run, provider calls, cache writes or translation writes. */
class JarekFailedTranslationPreview
{
    public const CONFIRM = 'TRANSLATE-MISSING-JAREK-FR';

    public function inspect(string $filter = 'all'): array
    {
        $ready = Schema::hasTable('jarek_gearboxes') && Schema::hasTable('jarek_gearbox_translations');
        $breakdown = [];
        $examples = [];
        $manifest = [];
        if ($ready) {
            $records = JarekGearbox::query()->whereHas('translations', fn ($q) => $q->where('locale', 'fr')->where('status', 'failed'))
                ->with(['translations' => fn ($q) => $q->where('locale', 'fr')])->orderBy('id')->get();
            foreach ($records as $record) {
                $translation = $record->translationForLocale('fr');
                $code = $translation->error_code ?: 'unknown';
                $breakdown[$code] = ($breakdown[$code] ?? 0) + 1;
                if ($filter !== 'all' && $code !== $filter) {
                    continue;
                }
                $item = ['id' => $record->id, 'source_hash' => $record->translationSourceHash(),
                    'expected_attempts' => $translation->attempts, 'error_code' => $translation->error_code,
                    'translation_source_hash' => $translation->source_hash];
                $manifest[] = $item;
                if (count($examples) < 40) {
                    $examples[] = ['gearbox_id' => $record->id, 'sku' => 'JAREK-'.$record->allegro_offer_id,
                        'retry_blocker' => $translation->matchesSourceHash($record->translationSourceHash()) ? null : 'source_changed_requires_manual_review',
                        'error_code' => $code, 'last_error' => FrenchCatalogTranslationPreviewService::safeError($code)['error_message'],
                        'diagnostic' => JarekTranslationTechnicalTokens::storedDiagnostic($translation->error_message),
                        'updated_at' => $translation->updated_at?->toISOString()];
                }
            }
        }

        return ['schema_ready' => $ready, 'total_failed' => array_sum($breakdown), 'selected_failed' => count($manifest),
            'breakdown' => $breakdown, 'examples' => $examples, 'filter' => $filter, 'manifest' => $manifest,
            'snapshot_hash' => hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR))];
    }

    public function publicReport(string $filter = 'all'): array
    {
        $report = $this->inspect($filter);
        unset($report['manifest']);

        return $report;
    }

    public function preview(int $actor, string $filter): array
    {
        $report = $this->publicReport($filter);
        // Signed/encrypted snapshot, not a cache write. Actor and one-hour expiry are checked on apply.
        $report['preview_id'] = Crypt::encryptString(json_encode(['actor' => $actor, 'filter' => $filter,
            'snapshot_hash' => $report['snapshot_hash'], 'expires' => now()->addHour()->timestamp], JSON_THROW_ON_ERROR));

        return $report;
    }

    public function approved(int $actor, string $id): ?array
    {
        try {
            $snapshot = json_decode(Crypt::decryptString($id), true, 512, JSON_THROW_ON_ERROR);
            if (($snapshot['actor'] ?? null) !== $actor || ($snapshot['expires'] ?? 0) < now()->timestamp
                || ! in_array($snapshot['filter'] ?? null, ['all', 'technical_code_changed', 'google_translate_failed'], true)) {
                return null;
            }
            $report = $this->inspect($snapshot['filter']);

            return hash_equals($snapshot['snapshot_hash'], $report['snapshot_hash']) ? $report : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
