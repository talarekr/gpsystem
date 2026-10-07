<?php

namespace App\Console\Commands;

use App\Models\Part;
use App\Models\PartCategory;
use App\Services\Marketplace\GoogleTranslateService;
use App\Services\Storefront\FrenchCatalogTranslationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class StorefrontFrTranslationsTranslateAll extends Command
{
    protected $signature = 'storefront:fr-translations-translate-all
        {--dry-run : Count only; no API calls or writes}
        {--apply : Translate and persist the selected catalog}
        {--type=all : parts, categories, or all}
        {--only-missing : Select absent/missing translations}
        {--include-needs-update : Include stale and needs_update translations}
        {--include-failed : Retry failed translations}
        {--chunk=100 : Database chunk size (1–1000)}
        {--confirm= : Required apply token}';

    protected $description = 'Translate the entire PL catalog to FR with resumable, guarded Google Translate calls';

    public function handle(FrenchCatalogTranslationService $service): int
    {
        $type = $this->option('type');
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT);
        if (! in_array($type, ['parts', 'categories', 'all'], true) || $chunk === false || $chunk < 1 || $chunk > 1000) {
            $this->error('Use --type=parts/categories/all and --chunk=1..1000.');

            return self::FAILURE;
        }
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('--apply and --dry-run are mutually exclusive.');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        if ($apply && $this->option('confirm') !== 'TRANSLATE-ALL-GPSWISS-FR-CATALOG') {
            $this->error('--apply requires --confirm=TRANSLATE-ALL-GPSWISS-FR-CATALOG.');

            return self::FAILURE;
        }
        if ($apply && ! $service->enabled()) {
            $this->error('--apply requires GPSWISS_FR_TRANSLATIONS_ENABLED=true.');

            return self::FAILURE;
        }
        $models = ['parts' => [Part::class, 'part_translations'], 'categories' => [PartCategory::class, 'category_translations']];
        if ($type !== 'all') {
            $models = [$type => $models[$type]];
        }
        foreach ($models as [$model, $table]) {
            if (! Schema::hasTable($table)) {
                $this->error('Missing '.$table.'; deploy/run Etap 2A migrations first.');

                return self::FAILURE;
            }
        }
        if ($apply && ! app(GoogleTranslateService::class)->readiness(false)['ok']) {
            $this->error('Google Translate is not ready. Check GOOGLE_TRANSLATE_ENABLED, API key and existing dry_run mode. No API calls made.');

            return self::FAILURE;
        }
        $options = [
            'only_missing' => (bool) $this->option('only-missing'),
            'include_needs_update' => (bool) $this->option('include-needs-update'),
            'include_failed' => (bool) $this->option('include-failed'),
        ];
        $start = microtime(true);
        $summary = [];
        $this->info($apply ? 'APPLY: full catalog; reviewed translations protected.' : 'DRY-RUN: read-only; no Google API calls.');
        foreach ($models as $kind => [$model]) {
            $counts = ['total' => 0, 'eligible' => 0, 'skipped' => 0, 'fields' => 0, 'estimated_characters' => 0];
            $model::query()->with(['translations' => fn ($q) => $q->where('locale', 'fr')])->chunkById($chunk, function ($records) use ($service, $options, &$counts): void {
                foreach ($records as $record) {
                    $counts['total']++;
                    if ($service->skipReason($record, $options) !== null || $service->sourceFields($record) === []) {
                        $counts['skipped']++;

                        continue;
                    }
                    $counts['eligible']++;
                    $fields = $service->sourceFields($record);
                    $counts['fields'] += count($fields);
                    $counts['estimated_characters'] += array_sum(array_map(fn ($text) => mb_strlen(trim($text)), $fields));
                }
            });
            $summary[$kind] = $counts;
            $this->line($kind.' '.json_encode($counts, JSON_THROW_ON_ERROR));
        }
        if (! $apply) {
            return self::SUCCESS;
        }

        // Run synchronously so progress means completed writes, not merely queued jobs.
        $progress = ['total' => array_sum(array_column($summary, 'total')), 'processed' => 0, 'translated' => 0,
            'skipped' => 0, 'failed' => 0, 'chars_translated' => 0, 'current_id' => null, 'elapsed_time' => 0];
        foreach ($models as $kind => [$model]) {
            $model::query()->chunkById($chunk, function ($records) use ($kind, $options, $service, &$progress, $start): void {
                foreach ($records as $record) {
                    $result = $service->translateWithRetries($kind, (int) $record->id, $options);
                    $progress['processed']++;
                    $progress[$result['status']]++;
                    $progress['chars_translated'] += $result['chars_translated'];
                    $progress['current_id'] = $record->id;
                    $progress['elapsed_time'] = round(microtime(true) - $start, 2);
                    $this->line(json_encode(['type' => $kind] + $progress, JSON_THROW_ON_ERROR));
                }
            });
        }

        return $progress['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
