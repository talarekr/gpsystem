<?php

namespace App\Services\Storefront;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Durable checkpoints; caller holds the runner's mutation lock for all writes. */
class FrenchCatalogTranslationRunStore
{
    public const TABLE = 'storefront_fr_translation_runs';

    public function ready(): bool
    {
        return Schema::hasTable(self::TABLE);
    }

    public function latest(): ?array
    {
        $row = $this->ready() ? DB::table(self::TABLE)->orderByDesc('id')->first() : null;
        if (! $row) {
            // Existing pre-migration runs are imported by the next mutation.
            return Cache::get(FrenchCatalogTranslationAdminRunner::KEY);
        }
        $durable = json_decode($row->checkpoint, true, 512, JSON_THROW_ON_ERROR);

        // Latest run includes terminal states: never resurrect an older incomplete
        // run after it was stopped or replaced. GET fallback does not write cache.
        return $durable;
    }

    public function save(array &$run): void
    {
        $run['_revision'] = (int) ($run['_revision'] ?? 0) + 1;
        $values = [
            'status' => $run['status'],
            'runner_mode' => $run['runner_mode'] ?? 'queue',
            'checkpoint' => json_encode($run, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ];
        $existing = DB::table(self::TABLE)->where('run_id', $run['run_id'])->exists();
        if ($existing) {
            DB::table(self::TABLE)->where('run_id', $run['run_id'])->update($values);
        } else {
            DB::table(self::TABLE)->insert($values + ['run_id' => $run['run_id'], 'created_at' => now()]);
        }
        // Write DB first, before provider calls and before the disposable pointer.
        Cache::forever(FrenchCatalogTranslationAdminRunner::KEY, $run);
    }
}
