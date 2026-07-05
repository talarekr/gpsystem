<?php

namespace App\Http\Controllers\Tools;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\MarketplaceSyncLog;
use App\Models\Order;
use App\Services\Marketplace\MarketplaceOrderFulfillmentSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Throwable;

class MarketplaceFulfillmentSyncToolController extends Controller
{
    public function __invoke(Request $request, MarketplaceOrderFulfillmentSyncService $service)
    {
        $this->authorizeOwnerAdmin($request);

        if ($request->isMethod('get') && ! $request->has('mode')) {
            return response()->view('tools.marketplace-fulfillment-sync', ['result' => null, 'orderId' => '', 'confirm' => '']);
        }

        $apply = $request->input('mode') === 'apply' || $request->boolean('apply');
        $orderId = (int) $request->input('order_id');
        $confirm = (string) $request->input('confirm', '');

        if ($orderId < 1) {
            return $this->respond($request, ['ok' => false, 'message' => 'Podaj ID zamówienia.'], 422, $orderId, $confirm);
        }
        if ($apply && ! hash_equals('sync-fulfillment', $confirm)) {
            return $this->respond($request, ['ok' => false, 'dry_run' => true, 'message' => 'Apply requires exact confirmation: sync-fulfillment. No marketplace write was executed.'], 422, $orderId, $confirm);
        }

        $order = Order::query()->findOrFail($orderId);
        try {
            $result = $apply ? $service->apply($order) : $service->dryRun($order);
            $this->audit($request, $order, $apply ? 'apply' : 'dry_run', ($result['ok'] ?? false) ? 'success' : 'blocked', $result);
            return $this->respond($request, $result + ['message' => $apply ? 'Fulfillment/status sync processed.' : 'DRY-RUN only. No marketplace write was executed.'], ($result['ok'] ?? false) || ! $apply ? 200 : 422, $orderId, $confirm);
        } catch (Throwable $exception) {
            $result = ['ok' => false, 'message' => 'API error: '.$exception->getMessage()];
            $this->audit($request, $order, $apply ? 'apply' : 'dry_run', 'error', $result);
            return $this->respond($request, $result, 500, $orderId, $confirm);
        }
    }

    private function respond(Request $request, array $result, int $status, int|string $orderId, string $confirm)
    { return $request->expectsJson() ? response()->json($result, $status) : response()->view('tools.marketplace-fulfillment-sync', ['result' => $result, 'orderId' => $orderId, 'confirm' => $confirm], $status); }

    private function authorizeOwnerAdmin(Request $request): void
    { abort_unless($request->user()?->canAccessPanel(filament()->getPanel('admin')), 403); abort_unless($request->user()?->hasAnyRole([UserRole::OwnerAdmin->value]), 403); }

    private function audit(Request $request, Order $order, string $mode, string $status, array $payload): void
    {
        if (! Schema::hasTable('marketplace_sync_logs')) return;
        $row = ['marketplace' => (string) ($order->marketplace ?: 'marketplace'), 'action' => 'marketplace_fulfillment_status_sync_tool_'.$mode, 'status' => $status, 'message' => 'Admin tools fulfillment/status sync '.$mode.' by user '.$request->user()?->id, 'payload' => ['user_id' => $request->user()?->id, 'user_email' => $request->user()?->email, 'result' => $payload], 'created_at' => now()];
        if (Schema::hasColumn('marketplace_sync_logs', 'order_id')) $row['order_id'] = $order->id;
        MarketplaceSyncLog::query()->create($row);
    }
}
