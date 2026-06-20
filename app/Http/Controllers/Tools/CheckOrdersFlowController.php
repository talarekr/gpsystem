<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class CheckOrdersFlowController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! hash_equals('gps_images_import_2026', (string) $request->query('token', ''))) {
            return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        }

        $ordersTable = Schema::hasTable('orders');

        return response()->json([
            'ok' => true,
            'tables' => ['orders' => $ordersTable, 'order_items' => Schema::hasTable('order_items')],
            'models' => ['order' => class_exists(Order::class), 'order_item' => class_exists(OrderItem::class)],
            'checkout_routes' => [
                'storefront.checkout.show' => Route::has('storefront.checkout.show'),
                'storefront.checkout.store' => Route::has('storefront.checkout.store'),
                'storefront.checkout.thank-you' => Route::has('storefront.checkout.thank-you'),
            ],
            'admin_order_routes' => [
                'filament.admin.resources.orders.index' => Route::has('filament.admin.resources.orders.index'),
                'filament.admin.resources.orders.view' => Route::has('filament.admin.resources.orders.view'),
                'filament.admin.resources.orders.edit' => Route::has('filament.admin.resources.orders.edit'),
            ],
            'orders_count' => $ordersTable ? Order::query()->count() : null,
            'latest_orders' => $ordersTable ? Order::query()->latest()->limit(5)->get(['id', 'order_number', 'status', 'total', 'created_at']) : [],
        ]);
    }
}
