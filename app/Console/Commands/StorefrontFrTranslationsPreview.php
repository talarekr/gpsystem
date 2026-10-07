<?php

namespace App\Console\Commands;

use App\Services\Storefront\FrTranslationsPreview;
use Illuminate\Console\Command;

class StorefrontFrTranslationsPreview extends Command
{
    protected $signature = 'storefront:fr-translations-preview {--json : Output JSON} {--examples=5 : Examples per type (0–20)}';

    protected $description = 'Read-only French translation coverage and source character estimate; no API calls';

    public function handle(FrTranslationsPreview $preview): int
    {
        $limit = filter_var($this->option('examples'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 20]]);
        if ($limit === false) {
            $this->error('--examples must be an integer between 0 and 20.');

            return self::INVALID;
        }
        $report = $preview->report($limit);
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->info('FR translation preview — read-only, no API calls');
            $this->line($report['scope']);
            $this->line($report['character_estimate']);
            if ($report['schema_missing']) {
                $this->warn('Missing tables: '.implode(', ', $report['schema_missing']));
            }
            $rows = [];
            foreach (['products', 'categories'] as $kind) {
                $summary = $report[$kind];
                $rows[] = [$kind, $summary['total'], $summary['current'], $summary['needing_translation'], $summary['estimated_characters']];
            }
            $this->table(['Type', 'Total', 'Current FR', 'Need FR', 'Characters'], $rows);
            foreach (['products', 'categories'] as $kind) {
                $this->line($kind.' reasons: '.json_encode($report[$kind]['reasons']));
                foreach ($report[$kind]['examples'] as $example) {
                    $this->line(json_encode($example, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
            }
        }

        return self::SUCCESS;
    }
}
