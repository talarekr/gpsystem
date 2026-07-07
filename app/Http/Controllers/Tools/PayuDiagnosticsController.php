<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Payments\PayuService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class PayuDiagnosticsController extends Controller
{
    public function __invoke(Request $request, PayuService $payu): JsonResponse
    {
        $result = ['config' => $payu->configForDiagnostics()];

        if ($request->boolean('oauth')) {
            try { $result['oauth'] = ['ok' => true, 'token_prefix' => substr($payu->token(), 0, 8).'...']; }
            catch (Throwable $e) { $result['oauth'] = ['ok' => false, 'error' => $e->getMessage()]; }
        }

        if ($orderId = $request->integer('dry_order')) {
            $order = Order::query()->with('items')->findOrFail($orderId);
            $result['dry_run_payload'] = $payu->orderPayload($order, $request->ip() ?: '127.0.0.1');
            $result['last_notification'] = data_get($order->meta, 'payu.last_notification');
        }

        if ($payuOrderId = $request->query('payu_order_id')) {
            try { $result['payu_status'] = $payu->getOrder((string) $payuOrderId); }
            catch (Throwable $e) { $result['payu_status'] = ['ok' => false, 'error' => $e->getMessage()]; }
        }

        return response()->json($result);
    }
}
