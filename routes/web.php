<?php

use App\Http\Controllers\Admin\ImportMigration\WooProductImportRunController;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::middleware(Authenticate::class)->prefix('admin/import-migracyjny/produkty-woo')->name('admin.import-migration.woo-products.')->group(function (): void {
    Route::post('/start', [WooProductImportRunController::class, 'start'])->name('start');
    Route::post('/runs/{runId}/next', [WooProductImportRunController::class, 'next'])->name('next');
});
