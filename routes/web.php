<?php

use App\Http\Controllers\Admin\ImportMigration\PartImagePresentationController;
use App\Http\Controllers\Admin\LocalSaleController;
use App\Http\Controllers\Admin\PartSearchController;
use App\Http\Controllers\Admin\ImportMigration\WooCategoryTreeController;
use App\Http\Controllers\Admin\ImportMigration\WooProductImportRunController;
use App\Http\Controllers\Admin\ImportMigration\WooStoragePublicController;
use App\Http\Controllers\Storefront\CartController;
use App\Http\Controllers\Storefront\CatalogController;
use App\Http\Controllers\Storefront\CategoryController;
use App\Http\Controllers\Storefront\HomeController;
use App\Http\Controllers\Storefront\PartController;
use App\Http\Controllers\Storefront\SearchController;
use App\Http\Controllers\Tools\CheckProductImageController;
use App\Http\Controllers\Tools\FixImportedImagesPublicFilesController;
use App\Http\Controllers\Tools\ImportedImagesStorageReportController;
use App\Http\Controllers\Tools\ProductImagesDryRunController;
use App\Http\Controllers\Tools\ProductImagesImportController;
use App\Http\Controllers\Tools\ProductImagesImportRunnerController;
use App\Services\ImportMigration\WooProductImport;
use App\Support\ImportMigration\ManualImportFileResolver;
use App\Support\ImportMigration\WooProductImportRunRepository;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('storefront.home');
Route::get('/sklep', [CatalogController::class, 'index'])->name('storefront.catalog');
Route::get('/czesci', [CatalogController::class, 'index'])->name('storefront.parts.alias');
Route::get('/szukaj', [SearchController::class, 'index'])->name('storefront.search');
Route::get('/koszyk', [CartController::class, 'index'])->name('storefront.cart.index');
Route::post('/koszyk/dodaj/{part}', [CartController::class, 'add'])->name('storefront.cart.add');
Route::post('/koszyk/aktualizuj', [CartController::class, 'update'])->name('storefront.cart.update');
Route::post('/koszyk/usun/{part}', [CartController::class, 'remove'])->name('storefront.cart.remove');
Route::post('/koszyk/wyczysc', [CartController::class, 'clear'])->name('storefront.cart.clear');
Route::get('/produkt/{slug}', [PartController::class, 'show'])->name('storefront.product');
Route::get('/kategoria-produktu/{path}', [CategoryController::class, 'show'])->where('path', '.*')->name('storefront.category');
Route::get('/product-images-dry-run', ProductImagesDryRunController::class)->name('tools.product-images-dry-run');
Route::get('/product-images-import', ProductImagesImportController::class)->name('tools.product-images-import');
Route::get('/product-images-import-runner', ProductImagesImportRunnerController::class)->name('tools.product-images-import-runner');
Route::get('/tools/check-product-image', CheckProductImageController::class)->name('tools.check-product-image');
Route::get('/tools/fix-imported-images-public-files', FixImportedImagesPublicFilesController::class)->name('tools.fix-imported-images-public-files');
Route::get('/tools/imported-images-storage-report', ImportedImagesStorageReportController::class)->name('tools.imported-images-storage-report');


Route::middleware(Authenticate::class)->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/search/parts', PartSearchController::class)->name('search.parts');
    Route::post('/local-sales', [LocalSaleController::class, 'store'])->name('local-sales.store');
});

Route::middleware(Authenticate::class)->prefix('admin/import-migracyjny/produkty-woo')->name('admin.import-migration.woo-products.')->group(function (): void {
    Route::get('/category-tree/audit', [WooCategoryTreeController::class, 'audit'])->name('category-tree.audit');
    Route::post('/category-tree/import', [WooCategoryTreeController::class, 'import'])->name('category-tree.import');
    Route::get('/storage-public/diagnostyka', [WooStoragePublicController::class, 'diagnostics'])->name('storage-public.diagnostics');
    Route::post('/storage-public/ensure', [WooStoragePublicController::class, 'ensure'])->name('storage-public.ensure');
    Route::post('/storage-public/force-copy', [WooStoragePublicController::class, 'forceCopy'])->name('storage-public.force-copy');
    Route::post('/part-images/{part}/process', [PartImagePresentationController::class, 'process'])->name('part-images.process');

    Route::post('/start', function (Request $request) {
        $lastDiagnosticStep = 'step_00_route_entered';

        $diagnosticStep = static function (string $step) use ($request, &$lastDiagnosticStep): void {
            $lastDiagnosticStep = $step;
            woo_import_append_start_ping_step($step);
        };

        register_shutdown_function(static function () use ($request, &$lastDiagnosticStep): void {
            woo_import_write_fatal_error_diagnostic($request, $lastDiagnosticStep);
        });

        ini_set('memory_limit', '256M');
        ini_set('max_execution_time', '300');

        woo_import_write_start_ping($request, 'step_01_route_reached');
        woo_import_append_start_ping_context([
            'step' => 'step_01_ini_values_after_set_attempt',
            'php_memory_limit' => ini_get('memory_limit'),
            'php_max_execution_time' => ini_get('max_execution_time'),
        ]);

        try {
            $diagnosticStep('step_02_before_controller');
            $controller = app(WooProductImportRunController::class);
            $diagnosticStep('step_03_after_controller_resolved');
            $diagnosticStep('step_04_before_start_call');
            $response = $controller->start($request);
            $diagnosticStep('step_05_after_start_call');

            return $response;
        } catch (Throwable $exception) {
            woo_import_write_route_emergency_diagnostic($exception, $request, 'start', $lastDiagnosticStep);

            $message = htmlspecialchars($exception->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $path = htmlspecialchars(storage_path('app/imports/manual/woo/last_error.log'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            return response(<<<HTML
<!doctype html>
<html lang="pl">
<head><meta charset="utf-8"><title>Import Woo nie wystartował</title></head>
<body>
    <h1>Import Woo nie wystartował</h1>
    <p>Podczas uruchamiania importu wystąpił wyjątek.</p>
    <p><strong>Komunikat:</strong> {$message}</p>
    <p>Szczegóły zapisano w pliku: <code>{$path}</code></p>
</body>
</html>
HTML, 500)->header('Content-Type', 'text/html; charset=UTF-8');
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
            'fatal_error_log_path' => $directory.DIRECTORY_SEPARATOR.'fatal_error.log',
            'start_ping_log_path' => $directory.DIRECTORY_SEPARATOR.'start_ping.log',
            'get_ping_log_path' => $directory.DIRECTORY_SEPARATOR.'get_ping.log',
            'post_ping_log_path' => $directory.DIRECTORY_SEPARATOR.'post_ping.log',
        ]);
    })->name('diagnostics');

    Route::get('/runs/{runId}/autorun', [WooProductImportRunController::class, 'autorun'])->name('autorun');
    Route::get('/runs/{runId}/status', [WooProductImportRunController::class, 'status'])->name('status');
    Route::post('/runs/{runId}/next', [WooProductImportRunController::class, 'next'])->name('next');
    Route::post('/runs/{runId}/next-many', [WooProductImportRunController::class, 'nextMany'])->name('next-many');
    Route::post('/runs/{runId}/autorun-log', [WooProductImportRunController::class, 'autorunnerLog'])->name('autorun-log');
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
                'php_max_execution_time: '.ini_get('max_execution_time'),
                'memory_usage: '.memory_get_usage(true),
                'memory_peak_usage: '.memory_get_peak_usage(true),
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
                'php_max_execution_time: '.ini_get('max_execution_time'),
                'memory_usage: '.memory_get_usage(true),
                'memory_peak_usage: '.memory_get_peak_usage(true),
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
    function woo_import_write_route_emergency_diagnostic(Throwable $exception, Request $request, string $action, string $lastDiagnosticStep = ''): array
    {
        $directory = storage_path('app/imports/manual/woo');
        $diagnosticPath = $directory.DIRECTORY_SEPARATOR.'last_error.log';

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $diagnostic = [
            'timestamp' => date(DATE_ATOM),
            'action' => $action,
            'diagnostic_scope' => 'route_closure_start_endpoint',
            'last_diagnostic_step' => $lastDiagnosticStep,
            'route_name' => optional($request->route())->getName(),
            'url' => $request->fullUrl(),
            'exception' => [
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ],
            'submitted_fields' => $request->only(woo_import_request_fields()),
            'submitted_keys' => woo_import_safe_request_keys($request),
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'memory_usage' => memory_get_usage(true),
            'memory_peak_usage' => memory_get_peak_usage(true),
            'php' => [
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
            ],
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
            'last_diagnostic_step' => $diagnostic['last_diagnostic_step'],
            'classes' => $diagnostic['classes'],
        ];
    }
}

if (! function_exists('woo_import_append_start_ping_context')) {
    /** @param array<string, mixed> $context */
    function woo_import_append_start_ping_context(array $context): void
    {
        try {
            $directory = storage_path('app/imports/manual/woo');

            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            $lines = ['timestamp: '.date(DATE_ATOM)];

            foreach ($context as $key => $value) {
                $lines[] = $key.': '.(is_scalar($value) || $value === null
                    ? (string) $value
                    : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }

            $lines[] = '---';

            file_put_contents(
                $directory.DIRECTORY_SEPARATOR.'start_ping.log',
                implode(PHP_EOL, $lines).PHP_EOL,
                FILE_APPEND | LOCK_EX,
            );
        } catch (Throwable) {
            // Context logging is diagnostic-only.
        }
    }
}

if (! function_exists('woo_import_safe_request_keys')) {
    /** @return array<int|string, string> */
    function woo_import_safe_request_keys(Request $request): array
    {
        try {
            return array_keys($request->all());
        } catch (Throwable $exception) {
            return ['__unavailable__' => $exception->getMessage()];
        }
    }
}

if (! function_exists('woo_import_write_fatal_error_diagnostic')) {
    function woo_import_write_fatal_error_diagnostic(Request $request, string $lastDiagnosticStep): void
    {
        $lastError = error_get_last();
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];

        if ($lastError === null || ! in_array($lastError['type'] ?? null, $fatalTypes, true)) {
            return;
        }

        try {
            $directory = storage_path('app/imports/manual/woo');

            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            $diagnostic = [
                'timestamp' => date(DATE_ATOM),
                'error_get_last' => $lastError,
                'memory_usage' => memory_get_usage(true),
                'memory_peak_usage' => memory_get_peak_usage(true),
                'php' => [
                    'memory_limit' => ini_get('memory_limit'),
                    'max_execution_time' => ini_get('max_execution_time'),
                ],
                'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
                'submitted_keys' => woo_import_safe_request_keys($request),
                'last_diagnostic_step' => $lastDiagnosticStep,
            ];

            $content = json_encode($diagnostic, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            file_put_contents(
                $directory.DIRECTORY_SEPARATOR.'fatal_error.log',
                ($content === false ? var_export($diagnostic, true) : $content).PHP_EOL,
                LOCK_EX,
            );
        } catch (Throwable) {
            // Shutdown diagnostics must never raise another fatal path.
        }
    }
}
