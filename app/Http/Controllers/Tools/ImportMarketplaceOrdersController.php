<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\MarketplaceOrdersImportService;
use Illuminate\Http\Request;

class ImportMarketplaceOrdersController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    public function __invoke(Request $request, MarketplaceOrdersImportService $service)
    {
        if (! hash_equals(self::TOKEN, (string) $request->query('token', ''))) {
            return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        }

        $dryRun = $request->boolean('dry_run', ! $request->boolean('import'));
        $result = $service->run([
            'marketplace' => (string) $request->query('marketplace', 'all'),
            'dry_run' => $dryRun,
            'limit' => (int) $request->query('limit', 50),
            'offset' => $request->query('offset'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'status' => $request->query('status'),
            'include_debug' => $request->boolean('include_debug'),
        ]);

        if (! $request->boolean('include_debug')) {
            foreach ($result['marketplaces'] as &$marketplace) unset($marketplace['would_import']);
        }

        return response()->json($result);
    }
}
