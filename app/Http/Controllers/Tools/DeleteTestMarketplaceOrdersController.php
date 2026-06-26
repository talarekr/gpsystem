<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\MarketplaceOrdersImportService;
use Illuminate\Http\Request;

class DeleteTestMarketplaceOrdersController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    public function __invoke(Request $request, MarketplaceOrdersImportService $service)
    {
        if (! hash_equals(self::TOKEN, (string) $request->query('token', ''))) {
            return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        }

        $sourceBatch = (string) $request->query('source_batch', MarketplaceOrdersImportService::TEST_BATCH);
        if ($sourceBatch !== MarketplaceOrdersImportService::TEST_BATCH) {
            return response()->json(['ok' => false, 'error_message' => 'Only the marketplace_orders_ui_test batch can be deleted by this tool.'], 422);
        }

        return response()->json($service->deleteTestBatch($sourceBatch, $request->boolean('dry_run', true), $request->boolean('confirm')));
    }
}
