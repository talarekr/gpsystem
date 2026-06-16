<?php

namespace App\Support\ImportMigration;

use App\Services\ImportMigration\ImportReport;
use App\Services\ImportMigration\WooProductImport;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class WooProductImportRunRepository
{
    private const SESSION_KEY = 'woo_product_import_runs';
    public const BATCH_SIZE = 250;

    public function start(array $state, WooProductImport $import, ManualImportFileResolver $fileResolver): array
    {
        $path = fn (string $key, string $label, string $extension = 'csv') => $fileResolver->resolveOptionalWooFile($state[$key] ?? null, $label, $extension);
        $productsPath = $fileResolver->resolveRequiredWooFile($state['products_filename'] ?? null, 'products.csv', 'csv');
        $mode = (string) ($state['mode'] ?? WooProductImport::MODE_DRY_RUN);

        if (! in_array($mode, [WooProductImport::MODE_DRY_RUN, WooProductImport::MODE_CREATE_ONLY, WooProductImport::MODE_UPDATE_EXISTING], true)) {
            throw new RuntimeException('Nieprawidłowy tryb importu Woo.');
        }

        $totalRows = $import->countProductRows($productsPath);
        $run = [
            'id' => (string) Str::uuid(),
            'productsPath' => $productsPath,
            'paths' => [
                'images' => $path('images_filename', 'product_images.csv'),
                'categories' => $path('categories_filename', 'product_categories.csv'),
                'meta' => $path('meta_filename', 'product_meta.csv'),
                'attributes' => $path('attributes_filename', 'product_attributes.csv'),
                'summary' => $path('summary_filename', 'export_summary.json', 'json'),
            ],
            'filenames' => $state,
            'mode' => $mode,
            'startedAt' => microtime(true),
            'startedAtText' => now()->toDateTimeString(),
            'currentOffset' => 0,
            'totalRows' => $totalRows,
            'batchSize' => self::BATCH_SIZE,
            'isRunning' => $totalRows > 0,
            'lastBatchProcessed' => 0,
            'lastBatchStartedAt' => null,
            'lastBatchFinishedAt' => null,
            'lastError' => $totalRows > 0 ? null : 'Plik products.csv nie zawiera wierszy produktów.',
            'report' => $import->makeReport()->toArray(),
        ];

        $this->put($run);

        return $run;
    }

    public function processNextBatch(string $runId, WooProductImport $import): array
    {
        $run = $this->find($runId);

        if (! $run) {
            throw new RuntimeException('Nie znaleziono uruchomienia importu Woo.');
        }

        if (! ($run['isRunning'] ?? false)) {
            return $run;
        }

        try {
            $this->assertRunIsUsable($run);
            $run['lastBatchStartedAt'] = now()->toDateTimeString();
            $run['lastBatchFinishedAt'] = null;
            $run['lastBatchProcessed'] = 0;

            $report = new ImportReport($run['report']['counters'] ?? []);
            $report->warnings = $run['report']['warnings'] ?? [];
            $report->errors = $run['report']['errors'] ?? [];

            $report = $import->importBatch(
                $run['productsPath'],
                $run['paths'],
                $run['mode'],
                (int) $run['currentOffset'],
                (int) ($run['batchSize'] ?? self::BATCH_SIZE),
                $report,
                (float) $run['startedAt'],
            );

            $run['report'] = $report->toArray();
            $run['lastBatchProcessed'] = (int) ($run['report']['counters']['last_batch_rows'] ?? 0);
            $run['currentOffset'] = (int) $run['currentOffset'] + $run['lastBatchProcessed'];
            $run['lastBatchFinishedAt'] = now()->toDateTimeString();
            $run['lastError'] = null;

            if ($run['lastBatchProcessed'] === 0 || $run['currentOffset'] >= $run['totalRows']) {
                $run['isRunning'] = false;
            }
        } catch (Throwable $exception) {
            report($exception);
            $run['isRunning'] = false;
            $run['lastError'] = $exception->getMessage();
            $run['lastBatchFinishedAt'] = now()->toDateTimeString();
            $run['report'] ??= ['counters' => [], 'warnings' => [], 'errors' => []];
            $run['report']['errors'][] = $run['lastError'];
            $run['report']['counters']['failed_rows'] = ($run['report']['counters']['failed_rows'] ?? 0) + 1;
        }

        $this->put($run);

        return $run;
    }

    public function find(?string $runId): ?array
    {
        if (! $runId) {
            return null;
        }

        return session(self::SESSION_KEY.'.'.$runId);
    }

    private function put(array $run): void
    {
        session()->put(self::SESSION_KEY.'.'.$run['id'], $run);
    }

    private function assertRunIsUsable(array $run): void
    {
        if (! is_string($run['productsPath'] ?? null) || ! is_file($run['productsPath'])) {
            throw new RuntimeException('Nie można kontynuować importu Woo: products.csv nie jest dostępny między partiami.');
        }

        foreach (($run['paths'] ?? []) as $label => $path) {
            if ($path !== null && (! is_string($path) || ! is_file($path))) {
                throw new RuntimeException("Nie można kontynuować importu Woo: plik {$label} nie jest dostępny między partiami.");
            }
        }
    }
}
