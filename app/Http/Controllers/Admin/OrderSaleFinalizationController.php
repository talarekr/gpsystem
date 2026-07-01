<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Marketplace\SaleFinalizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderSaleFinalizationController extends Controller
{
    public function dryRun(Order $order, SaleFinalizationService $service): JsonResponse
    {
        return response()->json($service->dryRun($order));
    }

    public function apply(Request $request, Order $order, SaleFinalizationService $service): JsonResponse
    {
        if ($request->query('confirm') !== 'sale-finalization') {
            return response()->json(['ok' => false, 'message' => 'Missing required confirm=sale-finalization.'], 422);
        }

        return response()->json($service->applyForOrder($order));
    }
}
