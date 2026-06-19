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
use App\Http\Controllers\Storefront\ContactController;
use App\Http\Controllers\Storefront\Auth\CustomerAuthController;
use App\Http\Controllers\Storefront\Auth\GoogleAuthController;
use App\Http\Controllers\Storefront\Auth\PasswordResetController;
use App\Http\Controllers\Storefront\CustomerAccountController;
use App\Http\Controllers\Storefront\HomeController;
use App\Http\Controllers\Storefront\PartController;
use App\Http\Controllers\Storefront\PrivacyPolicyController;
use App\Http\Controllers\Storefront\SearchController;
use App\Http\Controllers\Storefront\TermsController;
use App\Http\Controllers\Tools\CheckPartImagePresentationController;
use App\Http\Controllers\Tools\CheckProductImageController;
use App\Http\Controllers\Tools\CheckCatalogSearchController;
use App\Http\Controllers\Tools\CheckCatalogRenderController;
use App\Http\Controllers\Tools\CheckCatalogViewController;
use App\Http\Controllers\Tools\CheckCatalogViewStageController;
use App\Http\Controllers\Tools\CheckCatalogErrorController;
use App\Http\Controllers\Tools\LastLaravelErrorController;
use App\Http\Controllers\Tools\FixImportedImagesPublicFilesController;
use App\Http\Controllers\Tools\ImportedImagesStorageReportController;
use App\Http\Controllers\Tools\PhotoStorageReportController;
use App\Http\Controllers\Tools\ProductImagesDryRunController;
use App\Http\Controllers\Tools\ProductImagesImportController;
use App\Http\Controllers\Tools\ProductImagesImportRunnerController;
use App\Http\Controllers\Tools\ProcessPartImagePresentationController;
use App\Http\Controllers\Tools\ProcessPartImagePresentationRunnerController;
use App\Services\ImportMigration\WooProductImport;
use App\Support\ImportMigration\ManualImportFileResolver;
use App\Support\ImportMigration\WooProductImportRunRepository;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('storefront.home');
Route::get('/sklep', fn (Request $request) => redirect()->route('storefront.catalog', $request->query(), 301))->name('storefront.shop.legacy');
Route::get('/czesci', [CatalogController::class, 'index'])->name('storefront.catalog');
Route::get('/szukaj', [SearchController::class, 'index'])->name('storefront.search');
Route::get('/koszyk', [CartController::class, 'index'])->name('storefront.cart.index');

Route::get('/login', fn () => redirect()->route('storefront.login'))->name('login');
Route::get('/logowanie', [CustomerAuthController::class, 'loginForm'])->name('storefront.login');
Route::post('/logowanie', [CustomerAuthController::class, 'login'])->name('storefront.login.store');
Route::get('/rejestracja', [CustomerAuthController::class, 'registerForm'])->name('storefront.register');
Route::post('/rejestracja', [CustomerAuthController::class, 'register'])->name('storefront.register.store');
Route::post('/wyloguj', [CustomerAuthController::class, 'logout'])->name('storefront.logout');
Route::get('/przypomnij-haslo', [PasswordResetController::class, 'requestForm'])->name('password.request');
Route::post('/przypomnij-haslo', [PasswordResetController::class, 'sendLink'])->name('password.email');
Route::get('/reset-hasla/{token}', [PasswordResetController::class, 'resetForm'])->name('password.reset');
Route::post('/reset-hasla', [PasswordResetController::class, 'reset'])->name('password.update');
Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])->name('storefront.google.redirect');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('storefront.google.callback');
Route::middleware('auth')->group(function (): void {
    Route::get('/moje-konto', CustomerAccountController::class)->name('storefront.account');
    Route::patch('/moje-konto/dane', [CustomerAccountController::class, 'update'])->name('storefront.account.update');
    Route::post('/moje-konto/zwroty', [CustomerAccountController::class, 'storeReturn'])->name('storefront.account.returns.store');
});

Route::get('/kontakt', [ContactController::class, 'show'])->name('storefront.contact');
Route::post('/kontakt', [ContactController::class, 'send'])->name('storefront.contact.send');
Route::get('/regulamin', TermsController::class)->name('storefront.terms');
Route::get('/polityka-prywatnosci', PrivacyPolicyController::class)->name('storefront.privacy-policy');
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
Route::get('/tools/check-catalog-search', CheckCatalogSearchController::class)->name('tools.check-catalog-search');
Route::get('/tools/check-catalog-render', CheckCatalogRenderController::class)->name('tools.check-catalog-render');
Route::get('/tools/check-catalog-error', CheckCatalogErrorController::class)->name('tools.check-catalog-error');
Route::get('/tools/last-laravel-error', LastLaravelErrorController::class)->name('tools.last-laravel-error');
Route::get('/tools/mark-log', function (Request $request) {
    if (! hash_equals('gps_images_import_2026', (string) $request->query('token', ''))) {
        return response()->json([
            'ok' => false,
            'error_message' => 'Invalid diagnostics token.',
        ], 403);
    }

    $label = trim((string) $request->query('label', 'manual'));
    $label = preg_replace('/[^A-Za-z0-9_.:-]+/', '-', $label) ?: 'manual';
    $timestamp = now()->toIso8601String();
    $logFile = storage_path('logs/laravel.log');

    \Illuminate\Support\Facades\File::ensureDirectoryExists(dirname($logFile));
    \Illuminate\Support\Facades\File::append($logFile, "[CATALOG_MARKER] {$label} {$timestamp}\n");

    return response()->json([
        'ok' => true,
        'log_file' => $logFile,
        'label' => $label,
        'marker' => "[CATALOG_MARKER] {$label} {$timestamp}",
        'timestamp' => $timestamp,
    ]);
})->name('tools.mark-log');
Route::get('/tools/check-catalog-direct', function () {
    try {
        $trace = function (\Throwable $exception): array {
            return collect($exception->getTrace())->take(5)->map(fn (array $frame): array => [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'function' => $frame['function'] ?? null,
                'class' => $frame['class'] ?? null,
            ])->values()->all();
        };

        $fail = function (\Throwable $exception, string $stage) use ($trace) {
            return response()->json([
                'ok' => false,
                'failed_stage' => $stage,
                'error_class' => $exception::class,
                'error_message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $trace($exception),
            ], 200);
        };

        $result = [
            'ok' => true,
            'route_entered' => true,
        ];

        try {
            if (! hash_equals('gps_images_import_2026', (string) request()->query('token', ''))) {
                return response()->json([
                    'ok' => false,
                    'failed_stage' => 'stage_token',
                    'error_class' => 'AuthorizationException',
                    'error_message' => 'Invalid diagnostics token.',
                    'file' => __FILE__,
                    'line' => __LINE__,
                    'trace' => [],
                ], 403);
            }

            $result['stage_token'] = true;
        } catch (\Throwable $exception) {
            return $fail($exception, 'stage_token');
        }

        try {
            $result['stage_part_model_exists'] = class_exists(\App\Models\Part::class);
        } catch (\Throwable $exception) {
            return $fail($exception, 'stage_part_model_exists');
        }

        try {
            $result['stage_part_count'] = \App\Models\Part::query()->count();
        } catch (\Throwable $exception) {
            return $fail($exception, 'stage_part_count');
        }

        try {
            $result['stage_catalog_controller_exists'] = class_exists(\App\Http\Controllers\Storefront\CatalogController::class);
        } catch (\Throwable $exception) {
            return $fail($exception, 'stage_catalog_controller_exists');
        }

        try {
            $result['stage_catalog_route_exists'] = collect(\Illuminate\Support\Facades\Route::getRoutes())->contains(
                fn ($route): bool => in_array('GET', $route->methods(), true) && $route->uri() === 'czesci'
            );
        } catch (\Throwable $exception) {
            return $fail($exception, 'stage_catalog_route_exists');
        }

        try {
            $result['stage_catalog_view_exists'] = view()->exists('storefront.catalog.index');
        } catch (\Throwable $exception) {
            return $fail($exception, 'stage_catalog_view_exists');
        }

        return response()->json($result);
    } catch (\Throwable $exception) {
        return response()->json([
            'ok' => false,
            'failed_stage' => 'route_outer',
            'error_class' => $exception::class,
            'error_message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => collect($exception->getTrace())->take(5)->map(fn (array $frame): array => [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'function' => $frame['function'] ?? null,
                'class' => $frame['class'] ?? null,
            ])->values()->all(),
        ], 200);
    }
})->name('tools.check-catalog-direct');
Route::get('/tools/check-catalog-minimal-view', function () {
    try {
        if (! hash_equals('gps_images_import_2026', (string) request()->query('token', ''))) {
            return response()->json([
                'ok' => false,
                'error_class' => 'AuthorizationException',
                'error_message' => 'Invalid diagnostics token.',
                'file' => __FILE__,
                'line' => __LINE__,
                'trace' => [],
            ], 403);
        }

        $parts = \App\Models\Part::query()->limit(5)->get(['id', 'name', 'part_number']);
        $items = $parts->map(fn ($part): array => [
            'id' => $part->id,
            'name' => $part->name,
            'part_number' => $part->part_number,
        ])->values();

        if ((string) request()->query('render', '') === '1') {
            $html = '<!doctype html><html><head><meta charset="utf-8"><title>Catalog minimal diagnostic</title></head><body><h1>Catalog minimal diagnostic</h1><ul>';

            foreach ($items as $part) {
                $html .= '<li>#'.e((string) $part['id']).' '.e((string) $part['name']).' — '.e((string) ($part['part_number'] ?? '')).'</li>';
            }

            return response($html.'</ul></body></html>');
        }

        return response()->json([
            'ok' => true,
            'parts' => $items,
        ]);
    } catch (\Throwable $exception) {
        return response()->json([
            'ok' => false,
            'error_class' => $exception::class,
            'error_message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => collect($exception->getTrace())->take(5)->map(fn (array $frame): array => [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'function' => $frame['function'] ?? null,
                'class' => $frame['class'] ?? null,
            ])->values()->all(),
        ], 200);
    }
})->name('tools.check-catalog-minimal-view');
Route::get('/tools/check-catalog-view-ping', function (Request $request) {
    if (! hash_equals('gps_images_import_2026', (string) $request->query('token', ''))) {
        return response()->json([
            'ok' => false,
            'error_class' => 'AuthorizationException',
            'error_message' => 'Invalid diagnostics token.',
            'file' => __FILE__,
            'line' => __LINE__,
            'trace' => [],
        ], 403);
    }

    return response()->json(['ok' => true, 'route' => 'check-catalog-view-ping']);
})->name('tools.check-catalog-view-ping');
Route::get('/tools/check-catalog-view', CheckCatalogViewController::class)->name('tools.check-catalog-view');
Route::get('/tools/check-catalog-view-stage', CheckCatalogViewStageController::class)->name('tools.check-catalog-view-stage');
Route::get('/tools/check-catalog-blade-stages', function () {
    try {
        $trace = function (\Throwable $exception): array {
            return collect($exception->getTrace())->take(10)->map(fn (array $frame): array => [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'function' => $frame['function'] ?? null,
                'class' => $frame['class'] ?? null,
            ])->values()->all();
        };

        $fail = function (\Throwable $exception, string $stage) use ($trace) {
            return response()->json([
                'ok' => false,
                'failed_stage' => $stage,
                'error_class' => $exception::class,
                'error_message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $trace($exception),
            ], 200);
        };

        $result = [
            'ok' => true,
            'route_entered' => true,
            'stages' => [],
        ];

        try {
            if (! hash_equals('gps_images_import_2026', (string) request()->query('token', ''))) {
                return response()->json([
                    'ok' => false,
                    'failed_stage' => 'token',
                    'error_class' => 'AuthorizationException',
                    'error_message' => 'Invalid diagnostics token.',
                    'file' => __FILE__,
                    'line' => __LINE__,
                    'trace' => [],
                ], 403);
            }

            $result['token_valid'] = true;
        } catch (\Throwable $exception) {
            return $fail($exception, 'token');
        }

        try {
            $requestedStage = strtoupper((string) request()->query('stage', ''));
            $allowedStages = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];

            if ($requestedStage !== '' && ! in_array($requestedStage, $allowedStages, true)) {
                return response()->json([
                    'ok' => false,
                    'failed_stage' => 'stage_parameter',
                    'error_class' => 'InvalidArgumentException',
                    'error_message' => 'Invalid stage parameter. Allowed values: A, B, C, D, E, F, G.',
                    'file' => __FILE__,
                    'line' => __LINE__,
                    'trace' => [],
                ], 422);
            }

            $stagesToRun = $requestedStage === '' ? $allowedStages : [$requestedStage];
            $result['requested_stage'] = $requestedStage === '' ? null : $requestedStage;
        } catch (\Throwable $exception) {
            return $fail($exception, 'stage_parameter');
        }

        foreach ($stagesToRun as $stage) {
            try {
                if ($stage === 'A') {
                    $html = '<!doctype html><html><body><h1>Catalog Blade stages diagnostic</h1><p>Inline HTML OK</p></body></html>';
                } elseif ($stage === 'B') {
                    $html = \Illuminate\Support\Facades\Blade::render('<div data-stage="B">Catalog Blade diagnostic: {{ $label }}</div>', ['label' => 'simple Blade OK'], deleteCachedView: true);
                } elseif ($stage === 'C') {
                    $html = view('storefront.partials.search-bar')->render();
                } elseif ($stage === 'D') {
                    $part = \App\Models\Part::query()->storefrontVisible()->first();
                    $html = view('storefront.partials.product-card', ['part' => $part])->render();
                } elseif ($stage === 'E') {
                    $html = \App\Models\Part::query()->storefrontVisible()->paginate(5)->withQueryString()->links()->toHtml();
                } elseif ($stage === 'F') {
                    $catalogData = app(\App\Http\Controllers\Storefront\CatalogController::class)->viewData(request(), app(\App\Services\Storefront\CategoryTreeService::class));
                    $html = view('storefront.catalog._content', $catalogData)->render();
                } else {
                    $catalogData = app(\App\Http\Controllers\Storefront\CatalogController::class)->viewData(request(), app(\App\Services\Storefront\CategoryTreeService::class));
                    $html = view('storefront.catalog.index', $catalogData)->render();
                }

                $result['stages'][$stage] = [
                    'ok' => true,
                    'rendered_length' => strlen($html),
                ];
            } catch (\Throwable $exception) {
                return $fail($exception, $stage);
            }
        }

        return response()->json($result);
    } catch (\Throwable $exception) {
        return response()->json([
            'ok' => false,
            'failed_stage' => 'route_outer',
            'error_class' => $exception::class,
            'error_message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => collect($exception->getTrace())->take(10)->map(fn (array $frame): array => [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'function' => $frame['function'] ?? null,
                'class' => $frame['class'] ?? null,
            ])->values()->all(),
        ], 200);
    }
})->name('tools.check-catalog-blade-stages');
Route::get('/tools/clear-view-cache', function (Request $request) {
    if (
        ! hash_equals('gps_images_import_2026', (string) $request->query('token', ''))
        || ! hash_equals('clear', (string) $request->query('confirm', ''))
    ) {
        return response()->json([
            'ok' => false,
            'error_message' => 'Invalid cache clear token or confirmation.',
        ], 403);
    }

    $commands = [
        'view:clear',
        'optimize:clear',
        'route:clear',
        'config:clear',
        'cache:clear',
    ];

    $results = [];
    foreach ($commands as $command) {
        $exitCode = \Illuminate\Support\Facades\Artisan::call($command);
        $results[$command] = [
            'exit_code' => $exitCode,
            'output' => \Illuminate\Support\Facades\Artisan::output(),
        ];
    }

    return response()->json([
        'ok' => true,
        'commands' => $results,
    ]);
})->name('tools.clear-view-cache');
Route::get('/tools/check-header-source', function (Request $request) {
    if (! hash_equals('gps_images_import_2026', (string) $request->query('token', ''))) {
        return response()->json([
            'ok' => false,
            'error_message' => 'Invalid diagnostics token.',
        ], 403);
    }

    $headerPath = resource_path('views/storefront/partials/header.blade.php');
    $headerExists = is_file($headerPath);
    $headerSource = $headerExists ? (string) file_get_contents($headerPath) : '';
    $compiledViews = collect(glob(storage_path('framework/views/*.php')) ?: [])
        ->sortByDesc(fn (string $path): int => filemtime($path) ?: 0)
        ->take(10)
        ->map(fn (string $path): array => [
            'file' => basename($path),
            'modified_at' => date('c', filemtime($path) ?: 0),
            'size' => filesize($path) ?: 0,
        ])
        ->values();

    return response()->json([
        'ok' => true,
        'header_exists' => $headerExists,
        'header_first_120_lines' => implode("\n", array_slice(preg_split('/\R/', $headerSource) ?: [], 0, 120)),
        'contains_at_media' => str_contains($headerSource, '@media'),
        'contains_escaped_at_media' => str_contains($headerSource, '@@media'),
        'header_modified_at' => $headerExists ? date('c', filemtime($headerPath) ?: 0) : null,
        'compiled_views' => $compiledViews,
    ]);
})->name('tools.check-header-source');
Route::get('/tools/check-compiled-header', function (Request $request) {
    if (! hash_equals('gps_images_import_2026', (string) $request->query('token', ''))) {
        return response()->json([
            'ok' => false,
            'error_message' => 'Invalid diagnostics token.',
        ], 403);
    }

    $headerPath = resource_path('views/storefront/partials/header.blade.php');
    $compiledPath = null;
    $compiledSource = null;

    foreach (glob(storage_path('framework/views/*.php')) ?: [] as $path) {
        $source = (string) file_get_contents($path);

        if (str_contains($source, $headerPath) || str_contains($source, 'storefront/partials/header.blade.php')) {
            $compiledPath = $path;
            $compiledSource = $source;
            break;
        }
    }

    $startLine = 50;
    $endLine = 75;
    $fragment = [];
    $fragmentText = '';

    if ($compiledSource !== null) {
        $lines = preg_split('/\R/', $compiledSource) ?: [];

        foreach (range($startLine, $endLine) as $lineNumber) {
            if (array_key_exists($lineNumber - 1, $lines)) {
                $fragment[$lineNumber] = $lines[$lineNumber - 1];
            }
        }

        $fragmentText = implode("\n", $fragment);
    }

    return response()->json([
        'ok' => true,
        'header_view' => $headerPath,
        'compiled_exists' => $compiledPath !== null,
        'compiled_file' => $compiledPath ? basename($compiledPath) : null,
        'compiled_path' => $compiledPath,
        'compiled_modified_at' => $compiledPath ? date('c', filemtime($compiledPath) ?: 0) : null,
        'fragment_range' => [$startLine, $endLine],
        'fragment' => $fragment,
        'fragment_contains_at' => str_contains($fragmentText, '@'),
    ]);
})->name('tools.check-compiled-header');
Route::get('/tools/check-part-image-presentation', CheckPartImagePresentationController::class)->name('tools.check-part-image-presentation');
Route::get('/tools/process-part-image-presentation', ProcessPartImagePresentationController::class)->name('tools.process-part-image-presentation');
Route::get('/tools/process-part-image-presentation-runner', ProcessPartImagePresentationRunnerController::class)->name('tools.process-part-image-presentation-runner');
Route::get('/tools/fix-imported-images-public-files', FixImportedImagesPublicFilesController::class)->name('tools.fix-imported-images-public-files');
Route::get('/tools/imported-images-storage-report', ImportedImagesStorageReportController::class)->name('tools.imported-images-storage-report');
Route::get('/tools/photo-storage-report', PhotoStorageReportController::class)->name('tools.photo-storage-report');


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
