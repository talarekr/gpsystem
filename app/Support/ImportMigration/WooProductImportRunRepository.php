<?php

namespace App\Support\ImportMigration;

use App\Services\ImportMigration\ImportReport;
use App\Services\ImportMigration\WooProductImport;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class WooProductImportRunRepository
{
    public const BATCH_SIZE = 25;

    public function start(array $state, WooProductImport $import, ManualImportFileResolver $fileResolver): array
    {
        $fileResolver->ensureWooDirectoryExists();
        $productsPath = $fileResolver->resolveRequiredWooFile($state['products_filename'] ?? null, 'products.csv', 'csv');
        $optionalPath = fn (string $key, string $label, string $extension = 'csv') => $fileResolver->resolveOptionalWooFile($state[$key] ?? null, $label, $extension);
        $mode = (string) ($state['mode'] ?? WooProductImport::MODE_DRY_RUN);

        if (! in_array($mode, [WooProductImport::MODE_DRY_RUN, WooProductImport::MODE_CREATE_ONLY, WooProductImport::MODE_UPDATE_EXISTING], true)) {
            throw new RuntimeException('Nieprawidłowy tryb importu Woo.');
        }

        $batchSize = max(1, min(250, (int) ($state['batch_size'] ?? self::BATCH_SIZE)));
        $now = now()->toDateTimeString();
        $run = [
            'id' => (string) Str::uuid(),
            'mode' => $mode,
            'products_filename' => basename((string) $state['products_filename']),
            'categories_filename' => $this->nullableFilename($state['categories_filename'] ?? null),
            'meta_filename' => $this->nullableFilename($state['meta_filename'] ?? null),
            'attributes_filename' => $this->nullableFilename($state['attributes_filename'] ?? null),
            'summary_filename' => $this->nullableFilename($state['summary_filename'] ?? null),
            'images_filename' => $this->nullableFilename($state['images_filename'] ?? null),
            'productsPath' => $productsPath,
            'paths' => [
                'categories' => $optionalPath('categories_filename', 'product_categories.csv'),
                'meta' => $optionalPath('meta_filename', 'product_meta.csv'),
                'attributes' => $optionalPath('attributes_filename', 'product_attributes.csv'),
                'summary' => $optionalPath('summary_filename', 'export_summary.json', 'json'),
                'images' => null,
            ],
            'batch_size' => $batchSize,
            'batchSize' => $batchSize,
            'current_row' => 2,
            'processed_rows' => 0,
            'created_count' => 0,
            'updated_count' => 0,
            'skipped_count' => 0,
            'error_count' => 0,
            'status' => 'pending',
            'last_error' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'startedAtText' => $now,
            'lastBatchProcessed' => 0,
            'lastBatchStartedAt' => null,
            'lastBatchFinishedAt' => null,
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

        if (in_array($run['status'] ?? null, ['finished', 'failed'], true)) {
            return $run;
        }

        $beforeRow = (int) ($run['current_row'] ?? 2);
        $batchSize = (int) ($run['batch_size'] ?? $run['batchSize'] ?? self::BATCH_SIZE);

        try {
            $this->assertRunIsUsable($run);
            $run['status'] = 'running';
            $run['lastBatchStartedAt'] = now()->toDateTimeString();
            $run['lastBatchFinishedAt'] = null;
            $run['lastBatchProcessed'] = 0;
            $this->put($run);

            $report = new ImportReport($run['report']['counters'] ?? []);
            $report->warnings = $run['report']['warnings'] ?? [];
            $report->errors = $run['report']['errors'] ?? [];

            $result = $import->importBatchFromRow(
                $run['productsPath'],
                $run['paths'] ?? [],
                (string) ($run['mode'] ?? WooProductImport::MODE_DRY_RUN),
                $beforeRow,
                $batchSize,
                $report,
            );

            $run['report'] = $result['report']->toArray();
            $run['lastBatchProcessed'] = (int) $result['processed'];
            $run['current_row'] = (int) $result['next_row'];
            $run['processed_rows'] = (int) ($run['processed_rows'] ?? 0) + $run['lastBatchProcessed'];
            $run['created_count'] = (int) ($run['report']['counters']['created'] ?? 0);
            $run['updated_count'] = (int) ($run['report']['counters']['updated'] ?? 0);
            $run['skipped_count'] = (int) (($run['report']['counters']['skipped_existing'] ?? 0) + ($run['report']['counters']['skipped_duplicates'] ?? 0) + ($run['report']['counters']['skipped'] ?? 0));
            $run['error_count'] = (int) ($run['report']['counters']['failed_rows'] ?? 0);
            $run['status'] = ($result['end_of_file'] || $run['lastBatchProcessed'] === 0) ? 'finished' : 'pending';
            $run['last_error'] = null;
            $run['lastBatchFinishedAt'] = now()->toDateTimeString();
            $run['updated_at'] = now()->toDateTimeString();

            $this->put($run);
            $this->appendBatchDebug($run, $beforeRow, $batchSize, null);

            return $run;
        } catch (Throwable $exception) {
            $run['status'] = 'failed';
            $run['last_error'] = $exception->getMessage();
            $run['lastBatchFinishedAt'] = now()->toDateTimeString();
            $run['updated_at'] = now()->toDateTimeString();
            $run['report'] ??= ['counters' => [], 'warnings' => [], 'errors' => []];
            $run['report']['errors'][] = $exception->getMessage();
            $run['error_count'] = (int) ($run['error_count'] ?? 0) + 1;
            $this->put($run);
            $this->appendBatchDebug($run, $beforeRow, $batchSize, $exception);
            $this->appendBatchError($run, $exception);

            throw $exception;
        }
    }

    public function find(?string $runId): ?array
    {
        if (! $runId || basename($runId) !== $runId) {
            return null;
        }

        $path = $this->runPath($runId);
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function put(array $run): void
    {
        $this->ensureRunsDirectoryExists();
        file_put_contents($this->runPath((string) $run['id']), json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL, LOCK_EX);
    }

    private function assertRunIsUsable(array $run): void
    {
        if (! is_string($run['productsPath'] ?? null) || ! is_file($run['productsPath']) || ! is_readable($run['productsPath'])) {
            throw new RuntimeException('Nie można kontynuować importu Woo: products.csv nie jest dostępny między partiami.');
        }

        foreach (($run['paths'] ?? []) as $label => $path) {
            if ($label === 'images') {
                continue;
            }
            if ($path !== null && (! is_string($path) || ! is_file($path) || ! is_readable($path))) {
                throw new RuntimeException("Nie można kontynuować importu Woo: plik {$label} nie jest dostępny między partiami.");
            }
        }
    }

    private function nullableFilename(mixed $filename): ?string
    {
        $filename = trim((string) $filename);
        return $filename === '' ? null : basename($filename);
    }

    private function runPath(string $runId): string
    {
        return $this->runsDirectory().DIRECTORY_SEPARATOR.$runId.'.json';
    }

    private function runsDirectory(): string
    {
        return storage_path('app/imports/manual/woo/runs');
    }

    private function ensureRunsDirectoryExists(): void
    {
        if (! is_dir($this->runsDirectory())) {
            mkdir($this->runsDirectory(), 0755, true);
        }
    }

    private function appendBatchDebug(array $run, int $beforeRow, int $attempted, ?Throwable $exception): void
    {
        $line = json_encode([
            'timestamp' => now()->toIso8601String(),
            'run_id' => $run['id'] ?? null,
            'mode' => $run['mode'] ?? null,
            'current_row_before_batch' => $beforeRow,
            'attempted_rows' => $attempted,
            'processed_rows' => $run['lastBatchProcessed'] ?? 0,
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'peak_memory_usage_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'status_after_batch' => $run['status'] ?? null,
            'last_row_number' => $run['current_row'] ?? null,
            'exception' => $exception?->getMessage(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents(storage_path('app/imports/manual/woo/batch_debug.log'), $line.PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function appendBatchError(array $run, Throwable $exception): void
    {
        $line = json_encode([
            'timestamp' => now()->toIso8601String(),
            'run_id' => $run['id'] ?? null,
            'exception_class' => $exception::class,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents(storage_path('app/imports/manual/woo/batch_error.log'), $line.PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
