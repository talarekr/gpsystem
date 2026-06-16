<?php

namespace App\Http\Controllers\Admin\ImportMigration;

use App\Services\ImportMigration\WooProductImport;
use App\Support\ImportMigration\ManualImportFileResolver;
use App\Support\ImportMigration\WooProductImportRunRepository;
use Illuminate\Http\RedirectResponse;
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
    ];

    public function start(Request $request): RedirectResponse
    {
        $submitted = [];
        $fileResolver = app(ManualImportFileResolver::class);

        try {
            $import = app(WooProductImport::class);
            $runs = app(WooProductImportRunRepository::class);
            $this->ensureDiagnosticDirectoriesExist($fileResolver);
            $submitted = $request->only(self::REQUEST_FIELDS);

            $run = $runs->start($submitted, $import, $fileResolver);
            $run = $runs->processNextBatch($run['id'], $import);

            return redirect()->to(self::WOO_IMPORT_URL.'?run_id='.$run['id']);
        } catch (Throwable $exception) {
            $submitted = $submitted ?: $this->safeSubmittedFields($request);
            $debug = $this->handleImportException($exception, $request, $fileResolver, $submitted, 'start');

            return redirect()
                ->to(self::WOO_IMPORT_URL)
                ->withInput($submitted)
                ->with('woo_import_submitted', $submitted)
                ->with('woo_import_debug', $debug)
                ->withErrors(['woo_import' => 'Nie udało się uruchomić importu Woo. Szczegóły zapisano w pliku diagnostycznym '.self::DIAGNOSTIC_FILENAME.'.']);
        }
    }

    public function next(string $runId, Request $request): RedirectResponse
    {
        $fileResolver = app(ManualImportFileResolver::class);

        try {
            $import = app(WooProductImport::class);
            $runs = app(WooProductImportRunRepository::class);
            $this->ensureDiagnosticDirectoriesExist($fileResolver);
            $runs->processNextBatch($runId, $import);

            return redirect()->to(self::WOO_IMPORT_URL.'?run_id='.$runId);
        } catch (Throwable $exception) {
            $submitted = $this->safeSubmittedFields($request);
            $debug = $this->handleImportException($exception, $request, $fileResolver, $submitted, 'next');

            return redirect()
                ->to(self::WOO_IMPORT_URL.'?run_id='.$runId)
                ->withInput($submitted)
                ->with('woo_import_submitted', $submitted)
                ->with('woo_import_debug', $debug)
                ->withErrors(['woo_import' => 'Nie udało się uruchomić importu Woo. Szczegóły zapisano w pliku diagnostycznym '.self::DIAGNOSTIC_FILENAME.'.']);
        }
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
