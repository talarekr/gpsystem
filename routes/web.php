<?php

use App\Http\Controllers\Admin\ImportMigration\WooProductImportRunController;
use App\Services\ImportMigration\WooProductImport;
use App\Support\ImportMigration\ManualImportFileResolver;
use App\Support\ImportMigration\WooProductImportRunRepository;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::middleware(Authenticate::class)->prefix('admin/import-migracyjny/produkty-woo')->name('admin.import-migration.woo-products.')->group(function (): void {
    Route::post('/start', function (Request $request) {
        woo_import_write_start_ping($request, 'step_01_route_reached');

        try {
            woo_import_append_start_ping_step('step_02_before_controller');
            $controller = app(WooProductImportRunController::class);
            woo_import_append_start_ping_step('step_03_after_controller_resolved');
            woo_import_append_start_ping_step('step_04_before_start_call');
            $response = $controller->start($request);
            woo_import_append_start_ping_step('step_05_after_start_call');

            return $response;
        } catch (Throwable $exception) {
            $debug = woo_import_write_route_emergency_diagnostic($exception, $request, 'start');

            return redirect()
                ->to('/admin/import-migracyjny/produkty-woo')
                ->withInput($request->only(woo_import_request_fields()))
                ->with('woo_import_submitted', $request->only(woo_import_request_fields()))
                ->with('woo_import_debug', $debug)
                ->withErrors(['woo_import' => 'Nie udało się uruchomić importu Woo przed wejściem do kontrolera. Szczegóły zapisano w pliku diagnostycznym last_error.log.']);
        }
    })->name('start');

    Route::get('/start-ping', function (Request $request) {
        woo_import_write_minimal_ping($request, 'get_ping.log', 'GET reached start ping route');

        return response('OK', 200)->header('Content-Type', 'text/plain');
    })->name('start-ping');

    Route::post('/post-ping', function (Request $request) {
        woo_import_write_minimal_ping($request, 'post_ping.log', 'POST reached post ping route');

        return response('OK', 200)->header('Content-Type', 'text/plain');
    })->name('post-ping');

    Route::get('/diagnostyka', function () {
        $directory = storage_path('app/imports/manual/woo');
        $productsPath = $directory.DIRECTORY_SEPARATOR.'products.csv';

        return response()->json([
            'route_exists' => true,
            'controller_class_exists' => class_exists(WooProductImportRunController::class),
            'import_service_class_exists' => class_exists(WooProductImport::class),
            'manual_file_resolver_class_exists' => class_exists(ManualImportFileResolver::class),
            'run_repository_class_exists' => class_exists(WooProductImportRunRepository::class),
            'manual_folder_path' => $directory,
            'manual_folder_exists' => is_dir($directory),
            'manual_folder_writable' => is_dir($directory) && is_writable($directory),
            'products_csv_path' => $productsPath,
            'products_csv_exists' => is_file($productsPath),
            'products_csv_readable' => is_file($productsPath) && is_readable($productsPath),
            'last_error_log_path' => $directory.DIRECTORY_SEPARATOR.'last_error.log',
            'start_ping_log_path' => $directory.DIRECTORY_SEPARATOR.'start_ping.log',
            'get_ping_log_path' => $directory.DIRECTORY_SEPARATOR.'get_ping.log',
            'post_ping_log_path' => $directory.DIRECTORY_SEPARATOR.'post_ping.log',
        ]);
    })->name('diagnostics');

    Route::post('/runs/{runId}/next', [WooProductImportRunController::class, 'next'])->name('next');
});


if (! function_exists('woo_import_write_start_ping')) {
    function woo_import_write_start_ping(Request $request, string $step): void
    {
        try {
            $directory = storage_path('app/imports/manual/woo');

            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            $path = $directory.DIRECTORY_SEPARATOR.'start_ping.log';
            $keys = [];

            try {
                $keys = array_keys($request->all());
            } catch (Throwable $exception) {
                $keys = ['__unavailable__' => $exception->getMessage()];
            }

            $content = implode(PHP_EOL, [
                'timestamp: '.date(DATE_ATOM),
                'message: POST reached start route',
                'step: '.$step,
                'method: '.$request->getMethod(),
                'request_uri: '.($_SERVER['REQUEST_URI'] ?? ''),
                'content_length: '.($_SERVER['CONTENT_LENGTH'] ?? '0'),
                'submitted_keys: '.json_encode($keys, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'php_memory_limit: '.ini_get('memory_limit'),
                'cwd: '.getcwd(),
                '---',
            ]).PHP_EOL;

            file_put_contents($path, $content, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // This ping must never replace the original route behavior.
        }
    }
}

if (! function_exists('woo_import_append_start_ping_step')) {
    function woo_import_append_start_ping_step(string $step): void
    {
        try {
            $directory = storage_path('app/imports/manual/woo');

            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            file_put_contents(
                $directory.DIRECTORY_SEPARATOR.'start_ping.log',
                'timestamp: '.date(DATE_ATOM).PHP_EOL.'step: '.$step.PHP_EOL.'---'.PHP_EOL,
                FILE_APPEND | LOCK_EX,
            );
        } catch (Throwable) {
            // Step logging is diagnostic-only.
        }
    }
}

if (! function_exists('woo_import_write_minimal_ping')) {
    function woo_import_write_minimal_ping(Request $request, string $filename, string $message): void
    {
        try {
            $directory = storage_path('app/imports/manual/woo');

            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            $content = implode(PHP_EOL, [
                'timestamp: '.date(DATE_ATOM),
                'message: '.$message,
                'method: '.$request->getMethod(),
                'request_uri: '.($_SERVER['REQUEST_URI'] ?? ''),
                'content_length: '.($_SERVER['CONTENT_LENGTH'] ?? '0'),
                'submitted_keys: '.json_encode(array_keys($request->all()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'php_memory_limit: '.ini_get('memory_limit'),
                'cwd: '.getcwd(),
                '---',
            ]).PHP_EOL;

            file_put_contents($directory.DIRECTORY_SEPARATOR.$filename, $content, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // Ping routes are diagnostic-only.
        }
    }
}

if (! function_exists('woo_import_request_fields')) {
    function woo_import_request_fields(): array
    {
        return [
            'products_filename',
            'categories_filename',
            'meta_filename',
            'attributes_filename',
            'summary_filename',
            'images_filename',
            'mode',
        ];
    }
}

if (! function_exists('woo_import_write_route_emergency_diagnostic')) {
    function woo_import_write_route_emergency_diagnostic(Throwable $exception, Request $request, string $action): array
    {
        $directory = storage_path('app/imports/manual/woo');
        $diagnosticPath = $directory.DIRECTORY_SEPARATOR.'last_error.log';

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $diagnostic = [
            'timestamp' => date(DATE_ATOM),
            'action' => $action,
            'diagnostic_scope' => 'route_closure_before_controller',
            'route_name' => optional($request->route())->getName(),
            'url' => $request->fullUrl(),
            'exception' => [
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => array_slice(array_map(static fn (array $frame): array => [
                    'file' => $frame['file'] ?? null,
                    'line' => $frame['line'] ?? null,
                    'function' => $frame['function'] ?? null,
                    'class' => $frame['class'] ?? null,
                ], $exception->getTrace()), 0, 12),
            ],
            'submitted_fields' => $request->only(woo_import_request_fields()),
            'expected_folder_path' => $directory,
            'diagnostic_file' => $diagnosticPath,
            'classes' => [
                'controller' => class_exists(WooProductImportRunController::class),
                'import_service' => class_exists(WooProductImport::class),
                'manual_file_resolver' => class_exists(ManualImportFileResolver::class),
                'run_repository' => class_exists(WooProductImportRunRepository::class),
            ],
        ];

        $content = json_encode($diagnostic, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($diagnosticPath, ($content === false ? var_export($diagnostic, true) : $content).PHP_EOL, LOCK_EX);

        return [
            'exception_class' => $diagnostic['exception']['class'],
            'exception_message' => $diagnostic['exception']['message'],
            'expected_folder_path' => $diagnostic['expected_folder_path'],
            'submitted_fields' => $diagnostic['submitted_fields'],
            'diagnostic_file' => $diagnostic['diagnostic_file'],
            'diagnostic_scope' => $diagnostic['diagnostic_scope'],
            'classes' => $diagnostic['classes'],
        ];
    }
}
