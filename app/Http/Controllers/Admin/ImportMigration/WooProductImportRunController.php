<?php

namespace App\Http\Controllers\Admin\ImportMigration;

use App\Services\ImportMigration\WooProductImport;
use App\Support\ImportMigration\ManualImportFileResolver;
use App\Support\ImportMigration\WooProductImportRunRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class WooProductImportRunController
{
    public function start(
        Request $request,
        WooProductImport $import,
        ManualImportFileResolver $fileResolver,
        WooProductImportRunRepository $runs,
    ): RedirectResponse {
        try {
            $submitted = $request->only([
                'products_filename',
                'categories_filename',
                'meta_filename',
                'attributes_filename',
                'summary_filename',
                'images_filename',
                'mode',
            ]);

            $run = $runs->start($submitted, $import, $fileResolver);
            $run = $runs->processNextBatch($run['id'], $import);

            return redirect()->to('/admin/import-migracyjny/produkty-woo?run_id='.$run['id']);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->to('/admin/import-migracyjny/produkty-woo')
                ->withInput()
                ->with('woo_import_submitted', $request->only([
                    'products_filename',
                    'categories_filename',
                    'meta_filename',
                    'attributes_filename',
                    'summary_filename',
                    'images_filename',
                    'mode',
                ]))
                ->withErrors(['woo_import' => $exception->getMessage()]);
        }
    }

    public function next(string $runId, WooProductImport $import, WooProductImportRunRepository $runs): RedirectResponse
    {
        try {
            $runs->processNextBatch($runId, $import);

            return redirect()->to('/admin/import-migracyjny/produkty-woo?run_id='.$runId);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->to('/admin/import-migracyjny/produkty-woo?run_id='.$runId)
                ->withErrors(['woo_import' => $exception->getMessage()]);
        }
    }
}
