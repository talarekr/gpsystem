<?php

namespace App\Http\Controllers\Tools;

use App\Filament\Resources\OrderResource;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Admin\SalesAnalyticsService;
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

        $newOrdersCount = $ordersTable ? Order::query()->where('status', 'new')->count() : null;
        $latestServiceLogEvents = $ordersTable
            ? Order::query()
                ->where('status', 'new')
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn (Order $order): array => [
                    'type' => 'order',
                    'title' => 'Nowe zamówienie sklep ' . $order->order_number,
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'created_at' => $order->created_at?->toISOString(),
                    'admin_url' => Route::has('filament.admin.resources.orders.view')
                        ? OrderResource::getUrl('view', ['record' => $order])
                        : null,
                ])
                ->values()
            : collect();

        $salesAnalytics = app(SalesAnalyticsService::class);
        $salesAnalyticsDiagnostics = [
            'today' => $this->salesAnalyticsSnapshot($salesAnalytics, 'today'),
            'last_30_days' => $this->salesAnalyticsSnapshot($salesAnalytics, 'last_30_days'),
            'channels' => $this->diagnosticChannels($salesAnalytics->dashboardData('last_30_days')),
        ];

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
            'new_orders_count' => $newOrdersCount,
            'admin_topbar_orders_count' => $newOrdersCount,
            'service_log_orders_count' => $newOrdersCount,
            'service_log_all_count' => $newOrdersCount,
            'latest_service_log_events' => $latestServiceLogEvents,
            'sales_analytics' => $salesAnalyticsDiagnostics,
            'latest_orders' => $ordersTable ? Order::query()->latest()->limit(5)->get(['id', 'order_number', 'status', 'total', 'meta', 'created_at'])->map(fn (Order $order): array => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'source' => $order->meta['source'] ?? null,
                'channel' => $order->meta['channel'] ?? null,
                'meta' => $order->meta,
                'total' => (float) $order->total,
                'created_at' => $order->created_at?->toISOString(),
            ])->values() : [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function salesAnalyticsSnapshot(SalesAnalyticsService $salesAnalytics, string $range): array
    {
        $data = $salesAnalytics->dashboardData($range);

        return [
            'range' => [
                'key' => $data['range']['key'],
                'label' => $data['range']['label'],
                'starts_at' => $data['range']['starts_at']->toISOString(),
                'ends_at' => $data['range']['ends_at']->toISOString(),
            ],
            'summary' => $data['summary'],
            'channels' => $this->diagnosticChannels($data),
        ];
    }

    /**
     * @param array<string, mixed> $salesAnalyticsData
     * @return array<int, array{label: string, orders_count: int, sales_total: float}>
     */
    private function diagnosticChannels(array $salesAnalyticsData): array
    {
        $channels = collect($salesAnalyticsData['channels'])
            ->map(fn (array $channel): array => [
                'label' => $channel['label'],
                'orders_count' => (int) $channel['orders_count'],
                'sales_total' => (float) $channel['sales_pln'],
            ]);

        $localSales = $salesAnalyticsData['summary'];

        return $channels
            ->push([
                'label' => 'Sprzedaż lokalna',
                'orders_count' => (int) $localSales['local_sales_count'],
                'sales_total' => (float) $localSales['local_sales_pln'],
            ])
            ->values()
            ->all();
    }

}
