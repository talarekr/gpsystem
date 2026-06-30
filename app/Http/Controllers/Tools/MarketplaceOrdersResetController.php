<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\ApiIntegrationLogger;
use App\Services\Marketplace\ResetLocalOrdersService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class MarketplaceOrdersResetController extends Controller
{
    public function __construct(private readonly ApiIntegrationLogger $logger) {}

    public function __invoke(Request $request, ResetLocalOrdersService $service): JsonResponse
    {
        $apply = $request->boolean('apply');
        if ($apply && ! hash_equals('reset-local-orders', (string) $request->query('confirm', ''))) {
            return response()->json([
                'ok' => false,
                'dry_run' => true,
                'message' => 'Apply requires confirm=reset-local-orders. No data changed.',
            ], 422);
        }

        try {
            return response()->json($service->run($apply));
        } catch (Throwable $exception) {
            $this->logger->error('local', 'marketplace_orders_reset_tool', $exception, ['request' => ['apply' => $apply]]);

            return response()->json([
                'ok' => false,
                'message' => 'Technical error. Details logged in MarketplaceSyncLog / API integration logs.',
            ], 500);
        }
    }
}
