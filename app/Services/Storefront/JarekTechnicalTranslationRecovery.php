<?php

namespace App\Services\Storefront;

use App\Models\JarekGearbox;
use Carbon\Carbon;

/** A single, version-fenced repair pass inside the existing run; main cursor/counters never reset. */
class JarekTechnicalTranslationRecovery
{
    public function prepare(array &$run): void
    {
        if (($run['_technical_retry_version'] ?? null) === JarekTranslationTechnicalTokens::VERSION) {
            return;
        }
        $queue = JarekGearbox::query()->with('translations')->where('id', '<=', $run['_cursor']['jarek_gearboxes'])
            ->whereHas('translations', fn ($q) => $q->where('locale', 'fr')->where('status', 'failed')->where('error_code', 'technical_code_changed')->where('updated_at', '>=', Carbon::parse($run['started_at'])))
            ->orderBy('id')->get()->filter(fn ($record) => $record->translationForLocale('fr')->matchesSourceHash($record->translationSourceHash()))
            ->take(max(0, (int) $run['failed']))->map(fn ($record) => ['id' => $record->id, 'source_hash' => $record->translationSourceHash(),
                'expected_attempts' => $record->translationForLocale('fr')->attempts])->values()->all();
        $run['_technical_retry_version'] = JarekTranslationTechnicalTokens::VERSION;
        $run['_technical_retry_queue'] = $queue;
        $run['technical_retry_total'] = count($queue);
        $run['technical_retry_processed'] = 0;
        $run['technical_retry_remaining'] = count($queue);
        foreach (['translated', 'failed', 'skipped'] as $status) {
            $run['technical_retry_'.$status] = 0;
        }
    }

    public function next(array $run): ?array
    {
        return $run['_technical_retry_queue'][$run['technical_retry_processed'] ?? 0] ?? null;
    }

    public function finish(array &$run, string $status): void
    {
        $run['technical_retry_processed']++;
        $run['technical_retry_remaining']--;
        $run['technical_retry_'.$status]++;
        if ($status === 'translated') {
            $run['translated']++;
            $run['failed'] = max(0, $run['failed'] - 1);
        }
    }
}
