<?php

namespace App\Http\Controllers\Admin\ImportMigration;

use App\Services\ImportMigration\WooProductImport;
use App\Support\ImportMigration\ManualImportFileResolver;
use App\Support\ImportMigration\WooProductImportRunRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class WooProductImportRunController
{
    private const WOO_IMPORT_URL = '/admin/import-migracyjny/produkty-woo';
    private const DIAGNOSTIC_FILENAME = 'last_error.log';

    private const REQUEST_FIELDS = [
        'products_filename',
        'categories_filename',
        'meta_filename',
        'attributes_filename',
        'summary_filename',
        'images_filename',
        'mode',
        'batch_size',
    ];

    public function start(Request $request): Response
    {
        $submitted = [];
        $fileResolver = app(ManualImportFileResolver::class);

        try {
            $import = app(WooProductImport::class);
            $runs = app(WooProductImportRunRepository::class);
            $this->ensureDiagnosticDirectoriesExist($fileResolver);
            $submitted = $request->only(self::REQUEST_FIELDS);

            $run = $runs->start($submitted, $import, $fileResolver);

            return $this->renderRunPage($run, 'Import Woo został przygotowany. Start nie przetworzył żadnego produktu.');
        } catch (Throwable $exception) {
            $submitted = $submitted ?: $this->safeSubmittedFields($request);
            $debug = $this->handleImportException($exception, $request, $fileResolver, $submitted, 'start');

            return $this->renderErrorPage('Import Woo nie wystartował', $exception->getMessage(), $debug);
        }
    }


    public function autorun(string $runId): Response
    {
        $runs = app(WooProductImportRunRepository::class);
        $run = $runs->find($runId);

        if (! $run) {
            return $this->renderErrorPage('Autorunner importu Woo nie został uruchomiony', 'Nie znaleziono uruchomienia importu Woo.', [
                'run_id' => $runId,
            ]);
        }

        $this->appendAutorunnerDebug('opened', $run, 'Strona autorunnera została otwarta.');

        return response()->view('admin.import-migration.woo-product-autorun', [
            'run' => $this->safeRunPayload($run),
            'statusUrl' => route('admin.import-migration.woo-products.status', ['runId' => $runId]),
            'nextManyUrl' => route('admin.import-migration.woo-products.next-many', ['runId' => $runId]),
            'logUrl' => route('admin.import-migration.woo-products.autorun-log', ['runId' => $runId]),
            'importUrl' => self::WOO_IMPORT_URL.'?run_id='.rawurlencode($runId),
        ])->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function status(string $runId): JsonResponse
    {
        $run = app(WooProductImportRunRepository::class)->find($runId);

        if (! $run) {
            return response()->json([
                'ok' => false,
                'run_id' => $runId,
                'message' => 'Nie znaleziono uruchomienia importu Woo.',
            ], 404);
        }

        return response()->json($this->runJsonPayload($run, [
            'ok' => true,
            'message' => 'Odczytano aktualny stan runa.',
        ]));
    }

    public function nextMany(string $runId, Request $request): Response|JsonResponse
    {
        $fileResolver = app(ManualImportFileResolver::class);
        $wantsJson = $request->expectsJson() || $request->boolean('json');
        $completedBatches = 0;
        $processedRowsInRequest = 0;
        $stopReason = null;
        $run = null;

        try {
            $import = app(WooProductImport::class);
            $runs = app(WooProductImportRunRepository::class);
            $this->ensureDiagnosticDirectoriesExist($fileResolver);
            $maxBatches = max(1, min(25, (int) $request->input('batches', 10)));
            $run = $runs->find($runId);

            if (! $run) {
                throw new \RuntimeException('Nie znaleziono uruchomienia importu Woo.');
            }

            for ($i = 0; $i < $maxBatches; $i++) {
                if (in_array($run['status'] ?? null, ['finished', 'failed'], true)) {
                    $stopReason = (string) $run['status'];
                    break;
                }

                $run = $runs->processNextBatch($runId, $import);
                $completedBatches++;
                $processedRowsInRequest += (int) ($run['lastBatchProcessed'] ?? 0);

                if (in_array($run['status'] ?? null, ['finished', 'failed'], true)) {
                    $stopReason = (string) $run['status'];
                    break;
                }

                if ((int) ($run['lastBatchProcessed'] ?? 0) === 0) {
                    $stopReason = 'empty_batch';
                    break;
                }
            }

            $stopReason ??= $completedBatches >= $maxBatches ? 'batch_limit' : 'unknown';
            $this->appendBatchManyDebug($run, $completedBatches, $processedRowsInRequest, $stopReason);

            $payload = $this->runJsonPayload($run, [
                'ok' => true,
                'completed_batches' => $completedBatches,
                'processed_rows_in_request' => $processedRowsInRequest,
                'stop_reason' => $stopReason,
                'message' => $stopReason === 'finished' ? 'Import został zakończony.' : 'Przetworzono paczki next-many.',
            ]);

            if ($wantsJson) {
                return response()->json($payload);
            }

            return $this->renderRunPage($run, "Przetworzono {$completedBatches} paczek przez next-many.");
        } catch (Throwable $exception) {
            $submitted = $this->safeSubmittedFields($request);
            $debug = $this->handleImportException($exception, $request, $fileResolver, $submitted, 'next-many');
            $run ??= app(WooProductImportRunRepository::class)->find($runId) ?: ['id' => $runId, 'status' => 'failed', 'last_error' => $exception->getMessage()];
            $this->appendBatchManyDebug($run, $completedBatches, $processedRowsInRequest, 'exception', $exception);
            $this->appendAutorunnerDebug('failed', $run, $exception->getMessage());

            if ($wantsJson) {
                return response()->json($this->runJsonPayload($run, [
                    'ok' => false,
                    'completed_batches' => $completedBatches,
                    'processed_rows_in_request' => $processedRowsInRequest,
                    'stop_reason' => 'exception',
                    'message' => $exception->getMessage(),
                ]), 500);
            }

            return $this->renderErrorPage('Batch importu Woo next-many nie został ukończony', $exception->getMessage(), $debug);
        }
    }

    public function autorunnerLog(string $runId, Request $request): JsonResponse
    {
        $run = app(WooProductImportRunRepository::class)->find($runId) ?: ['id' => $runId];
        $event = (string) $request->input('event', 'unknown');
        if (! in_array($event, ['opened', 'start', 'pause', 'step', 'completed', 'failed', 'request_error'], true)) {
            $event = 'unknown';
        }

        $this->appendAutorunnerDebug($event, $run, (string) $request->input('message', ''));

        return response()->json(['ok' => true]);
    }

    public function next(string $runId, Request $request): Response
    {
        $fileResolver = app(ManualImportFileResolver::class);

        try {
            $import = app(WooProductImport::class);
            $runs = app(WooProductImportRunRepository::class);
            $this->ensureDiagnosticDirectoriesExist($fileResolver);
            $run = $runs->processNextBatch($runId, $import);

            return $this->renderRunPage($run, 'Przetworzono kolejną paczkę.');
        } catch (Throwable $exception) {
            $submitted = $this->safeSubmittedFields($request);
            $debug = $this->handleImportException($exception, $request, $fileResolver, $submitted, 'next');

            return $this->renderErrorPage('Batch importu Woo nie został ukończony', $exception->getMessage(), $debug);
        }
    }



    /** @param array<string, mixed> $run */
    private function safeRunPayload(array $run): array
    {
        return [
            'run_id' => (string) ($run['id'] ?? ''),
            'mode' => (string) ($run['mode'] ?? ''),
            'status' => (string) ($run['status'] ?? 'unknown'),
            'batch_size' => (int) ($run['batch_size'] ?? $run['batchSize'] ?? WooProductImportRunRepository::BATCH_SIZE),
            'total_processed_rows' => (int) ($run['processed_rows'] ?? 0),
            'current_row' => (int) ($run['current_row'] ?? 2),
            'created_count' => (int) ($run['created_count'] ?? 0),
            'updated_count' => (int) ($run['updated_count'] ?? 0),
            'skipped_count' => (int) ($run['skipped_count'] ?? 0),
            'error_count' => (int) ($run['error_count'] ?? 0),
            'last_error' => (string) ($run['last_error'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $run @param array<string, mixed> $extra */
    private function runJsonPayload(array $run, array $extra = []): array
    {
        return array_merge([
            'ok' => true,
            'run_id' => (string) ($run['id'] ?? ''),
            'mode' => (string) ($run['mode'] ?? ''),
            'status' => (string) ($run['status'] ?? 'unknown'),
            'batch_size' => (int) ($run['batch_size'] ?? $run['batchSize'] ?? WooProductImportRunRepository::BATCH_SIZE),
            'completed_batches' => 0,
            'processed_rows_in_request' => 0,
            'total_processed_rows' => (int) ($run['processed_rows'] ?? 0),
            'current_row' => (int) ($run['current_row'] ?? 2),
            'created_count' => (int) ($run['created_count'] ?? 0),
            'updated_count' => (int) ($run['updated_count'] ?? 0),
            'skipped_count' => (int) ($run['skipped_count'] ?? 0),
            'error_count' => (int) ($run['error_count'] ?? 0),
            'stop_reason' => null,
            'memory_usage' => memory_get_usage(true),
            'memory_peak_usage' => memory_get_peak_usage(true),
            'message' => (string) ($run['last_error'] ?? ''),
        ], $extra);
    }


    /** @param array<string, mixed> $run */
    private function appendBatchManyDebug(array $run, int $completedBatches, int $processedRowsInRequest, ?string $stopReason, ?Throwable $exception = null): void
    {
        $directory = storage_path('app/imports/manual/woo');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $line = json_encode([
            'timestamp' => now()->toIso8601String(),
            'run_id' => $run['id'] ?? null,
            'mode' => $run['mode'] ?? null,
            'status' => $run['status'] ?? null,
            'completed_batches' => $completedBatches,
            'processed_rows_in_request' => $processedRowsInRequest,
            'total_processed_rows' => (int) ($run['processed_rows'] ?? 0),
            'current_row' => (int) ($run['current_row'] ?? 2),
            'created_count' => (int) ($run['created_count'] ?? 0),
            'updated_count' => (int) ($run['updated_count'] ?? 0),
            'skipped_count' => (int) ($run['skipped_count'] ?? 0),
            'error_count' => (int) ($run['error_count'] ?? 0),
            'stop_reason' => $stopReason,
            'memory_usage' => memory_get_usage(true),
            'memory_peak_usage' => memory_get_peak_usage(true),
            'exception' => $exception?->getMessage(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        file_put_contents($directory.DIRECTORY_SEPARATOR.'batch_many_debug.log', $line.PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /** @param array<string, mixed> $run */
    private function appendAutorunnerDebug(string $event, array $run, string $message = ''): void
    {
        $directory = storage_path('app/imports/manual/woo');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $line = json_encode([
            'timestamp' => now()->toIso8601String(),
            'run_id' => $run['id'] ?? null,
            'event' => $event,
            'status' => $run['status'] ?? null,
            'processed_rows' => (int) ($run['processed_rows'] ?? 0),
            'current_row' => (int) ($run['current_row'] ?? 2),
            'created_count' => (int) ($run['created_count'] ?? 0),
            'updated_count' => (int) ($run['updated_count'] ?? 0),
            'skipped_count' => (int) ($run['skipped_count'] ?? 0),
            'error_count' => (int) ($run['error_count'] ?? 0),
            'message' => $message !== '' ? $message : (string) ($run['last_error'] ?? ''),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        file_put_contents($directory.DIRECTORY_SEPARATOR.'autorunner_debug.log', $line.PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function renderRunPage(array $run, string $message): Response
    {
        $action = route('admin.import-migration.woo-products.next', ['runId' => $run['id']]);
        $nextManyAction = route('admin.import-migration.woo-products.next-many', ['runId' => $run['id']]);
        $autorunUrl = route('admin.import-migration.woo-products.autorun', ['runId' => $run['id']]);
        $csrf = csrf_field();
        $status = htmlspecialchars((string) ($run['status'] ?? 'pending'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $mode = htmlspecialchars((string) ($run['mode'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $id = htmlspecialchars((string) ($run['id'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $lastError = htmlspecialchars((string) ($run['last_error'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $processed = (int) ($run['processed_rows'] ?? 0);
        $batchSize = (int) ($run['batch_size'] ?? $run['batchSize'] ?? WooProductImportRunRepository::BATCH_SIZE);
        $currentRow = (int) ($run['current_row'] ?? 2);
        $lastBatch = (int) ($run['lastBatchProcessed'] ?? 0);
        $button = in_array($run['status'] ?? null, ['finished', 'failed'], true) ? '' : <<<HTML
        <form method="POST" action="{$action}">
            {$csrf}
            <button type="submit">Przetwórz kolejną paczkę</button>
        </form>
        <form method="POST" action="{$nextManyAction}" style="margin-top: .5rem;">
            {$csrf}
            <button type="submit">Przetwórz 10 paczek</button>
        </form>
HTML;

        return response(<<<HTML
<!doctype html>
<html lang="pl">
<head><meta charset="utf-8"><title>Batch import Woo</title></head>
<body>
    <h1>Batch import Woo</h1>
    <p>{$message}</p>
    <dl>
        <dt>Import run ID</dt><dd><code>{$id}</code></dd>
        <dt>Mode</dt><dd>{$mode}</dd>
        <dt>Batch size</dt><dd>{$batchSize}</dd>
        <dt>Processed rows</dt><dd>{$processed}</dd>
        <dt>Current CSV row</dt><dd>{$currentRow}</dd>
        <dt>Last batch processed</dt><dd>{$lastBatch}</dd>
        <dt>Status</dt><dd>{$status}</dd>
        <dt>Last error</dt><dd>{$lastError}</dd>
    </dl>
    {$button}
    <p><a href="{$autorunUrl}">Uruchom autorunner</a></p>
    <p><a href="/admin/import-migracyjny/produkty-woo?run_id={$id}">Wróć do strony importu</a></p>
</body>
</html>
HTML, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    private function renderErrorPage(string $title, string $message, array $debug): Response
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $debugJson = htmlspecialchars(json_encode($debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return response(<<<HTML
<!doctype html>
<html lang="pl"><head><meta charset="utf-8"><title>{$safeTitle}</title></head>
<body><h1>{$safeTitle}</h1><p>{$safeMessage}</p><pre>{$debugJson}</pre></body></html>
HTML, 500)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** @param array<string, mixed> $submitted */
    private function handleImportException(
        Throwable $exception,
        Request $request,
        ManualImportFileResolver $fileResolver,
        array $submitted,
        string $action,
    ): array {
        $diagnostic = $this->buildDiagnosticPayload($exception, $request, $fileResolver, $submitted, $action);
        $this->writeEmergencyDiagnostic($fileResolver, $diagnostic);

        try {
            Log::error('Woo import HTTP route failed.', $diagnostic);
        } catch (Throwable) {
            // The emergency diagnostic above is intentionally independent from Laravel logging.
        }

        try {
            report($exception);
        } catch (Throwable) {
            // Do not let broken logging/reporting replace the controlled redirect with HTTP 500.
        }

        return [
            'exception_class' => $diagnostic['exception']['class'],
            'exception_message' => $diagnostic['exception']['message'],
            'expected_folder_path' => $diagnostic['expected_folder_path'],
            'submitted_fields' => $diagnostic['submitted_fields'],
            'diagnostic_file' => $diagnostic['diagnostic_file'],
        ];
    }

    /** @param array<string, mixed> $submitted */
    private function buildDiagnosticPayload(
        Throwable $exception,
        Request $request,
        ManualImportFileResolver $fileResolver,
        array $submitted,
        string $action,
    ): array {
        $expectedFolderPath = $fileResolver->wooDirectoryPath();
        $diagnosticPath = $expectedFolderPath.DIRECTORY_SEPARATOR.self::DIAGNOSTIC_FILENAME;

        return [
            'timestamp' => now()->toIso8601String(),
            'action' => $action,
            'route_name' => optional($request->route())->getName(),
            'url' => $request->fullUrl(),
            'exception' => [
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => collect($exception->getTrace())->take(12)->map(fn (array $frame): array => [
                    'file' => $frame['file'] ?? null,
                    'line' => $frame['line'] ?? null,
                    'function' => $frame['function'] ?? null,
                    'class' => $frame['class'] ?? null,
                ])->all(),
            ],
            'submitted_fields' => $submitted,
            'expected_folder_path' => $expectedFolderPath,
            'diagnostic_file' => $diagnosticPath,
            'files' => $this->fileDiagnostics($expectedFolderPath, $submitted),
            'php' => [
                'memory_limit' => ini_get('memory_limit'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'),
            ],
            'user_id' => optional($request->user())->getAuthIdentifier(),
            'app_environment' => app()->environment(),
            'current_working_directory' => getcwd(),
            'storage_logs' => [
                'path' => storage_path('logs'),
                'exists' => is_dir(storage_path('logs')),
                'writable' => is_writable(storage_path('logs')),
            ],
        ];
    }

    /** @param array<string, mixed> $submitted */
    private function fileDiagnostics(string $expectedFolderPath, array $submitted): array
    {
        $files = [];

        foreach (self::REQUEST_FIELDS as $field) {
            if ($field === 'mode') {
                continue;
            }

            $filename = trim((string) ($submitted[$field] ?? ''));
            $path = $filename === '' || basename($filename) !== $filename || str_contains($filename, "\0")
                ? null
                : $expectedFolderPath.DIRECTORY_SEPARATOR.$filename;

            $files[$field] = [
                'filename' => $filename,
                'path' => $path,
                'exists' => $path !== null && file_exists($path),
                'readable' => $path !== null && is_readable($path),
            ];
        }

        return $files;
    }

    private function ensureDiagnosticDirectoriesExist(ManualImportFileResolver $fileResolver): void
    {
        if (! is_dir(storage_path('logs'))) {
            mkdir(storage_path('logs'), 0755, true);
        }

        $fileResolver->ensureWooDirectoryExists();
    }

    /** @return array<string, mixed> */
    private function safeSubmittedFields(Request $request): array
    {
        return $request->only(self::REQUEST_FIELDS);
    }

    /** @param array<string, mixed> $diagnostic */
    private function writeEmergencyDiagnostic(ManualImportFileResolver $fileResolver, array $diagnostic): void
    {
        $this->ensureDiagnosticDirectoriesExist($fileResolver);

        $content = json_encode($diagnostic, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        file_put_contents(
            $fileResolver->wooDirectoryPath().DIRECTORY_SEPARATOR.self::DIAGNOSTIC_FILENAME,
            ($content === false ? var_export($diagnostic, true) : $content).PHP_EOL,
            LOCK_EX,
        );
    }
}
