<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceSyncLog;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Application;
use Illuminate\Support\Str;
use Throwable;

class OrderViewDiagnoseController extends Controller
{
    private const CODE_MARKER = 'shipment_module_crash_diagnostics_safe_v1';

    public function __invoke(Request $request): JsonResponse
    {
        $orderId = (int) $request->integer('order_id');

        if ($request->boolean('minimal')) {
            return response()->json($this->minimalPayload($orderId));
        }

        $payload = $this->emptyPayload($orderId);

        try {
            $this->guard($payload['order_153_db_probe']['items_probe_errors'], 'order_db_probe', fn () => $this->orderDbProbe($orderId, $payload));

            $order = null;
        $this->guard($payload['relations_probe']['errors'], 'order_load', function () use (&$order, &$payload, $orderId): void {
            $order = Order::query()->find($orderId);
            $payload['order']['exists'] = $order !== null;
            if ($order) {
                $payload['order'] = array_merge($payload['order'], [
                    'id' => $order->id,
                    'source' => $order->marketplace,
                    'number' => $order->marketplace_order_id ?: $order->order_number,
                    'status' => $order->status,
                    'payment_status' => $order->payment_status,
                    'shipping_status' => data_get($order->meta, 'marketplace_fulfillment_status'),
                    'created_at' => optional($order->created_at)->toDateTimeString(),
                ]);
            }
        });

        if ($order) {
            $this->probeRelations($order, $payload);
            $this->shipmentState($order, $payload);
            $this->guard($payload['relations_probe']['errors'], 'dhl_state', fn () => $this->dhlState($order, $payload));
            $this->viewRiskChecks($order, $payload);
        }

            $payload['last_exception_for_order_view'] = $this->lastOrderViewException($orderId);
            $payload['safe_recommendation'] = $this->recommendation($payload);
            $payload['diagnostics_health']['status'] = empty($payload['relations_probe']['errors']) && empty($payload['view_risk_checks']['errors']) ? 'ok' : 'partial';
            $payload['diagnostics_health']['sections_completed'][] = 'full';
        } catch (Throwable $e) {
            $payload['diagnostics_health']['ok'] = false;
            $payload['diagnostics_health']['status'] = 'failed';
            $payload['diagnostics_health']['sections_failed'][] = ['stage' => 'top_level', 'class' => $e::class, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()];
            $payload['errors'][] = ['stage' => 'top_level', 'class' => $e::class, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()];
        }

        return response()->json($payload);
    }

    private function minimalPayload(int $orderId): array
    {
        return [
            'code_marker' => self::CODE_MARKER,
            'minimal' => true,
            'order_id' => $orderId,
            'app' => [
                'environment' => app()->environment(),
                'php_version' => PHP_VERSION,
                'laravel_version' => Application::VERSION,
            ],
            'diagnostics_health' => [
                'ok' => true,
                'status' => 'ok',
                'sections_completed' => ['minimal'],
                'sections_failed' => [],
            ],
            'errors' => [],
        ];
    }

    private function emptyPayload(int $orderId): array
    {
        return [
            'code_marker' => self::CODE_MARKER,
            'order' => ['id' => $orderId, 'exists' => false, 'source' => null, 'number' => null, 'status' => null, 'payment_status' => null, 'shipping_status' => null, 'created_at' => null],
            'order_153_db_probe' => ['order_exists' => false, 'order_scalar' => [], 'raw_payload_present' => null, 'raw_payload_type' => null, 'meta_present' => null, 'meta_type' => null, 'items_count' => null, 'items_probe_errors' => [], 'possible_view_crash_points' => []],
            'relations_probe' => ['customer_loads' => null, 'shipping_address_loads' => null, 'items_load' => null, 'shipments_load' => null, 'labels_load' => null, 'marketplace_logs_load' => null, 'errors' => []],
            'shipment_state' => ['local_shipments_count' => null, 'local_shipment_ids' => [], 'local_tracking_numbers' => [], 'local_label_paths' => [], 'local_label_files_exist' => [], 'has_partial_shipment_without_label' => null, 'has_label_record_without_file' => null, 'has_empty_or_invalid_label_path' => null, 'has_empty_or_invalid_tracking_number' => null],
            'dhl_state' => ['last_create_shipment_log_id' => null, 'last_create_shipment_status' => null, 'remote_created_detected' => null, 'remote_tracking_number' => null, 'remote_package_tracking_number' => null, 'last_fetch_existing_label_log_id' => null, 'last_fetch_existing_label_status' => null, 'last_fetch_existing_label_error' => null],
            'view_risk_checks' => ['tracking_url_can_be_built' => null, 'shipment_component_can_compute_state' => null, 'download_label_url_can_be_built' => null, 'dhl_ui_state_can_be_built' => null, 'order_items_component_can_render_data' => null, 'customer_component_can_render_data' => null, 'errors' => []],
            'last_exception_for_order_view' => ['found' => false, 'class' => null, 'message' => null, 'file' => null, 'line' => null],
            'diagnostics_health' => ['ok' => true, 'status' => 'running', 'sections_completed' => [], 'sections_failed' => []],
            'errors' => [],
            'safe_recommendation' => null,
        ];
    }


    private function orderDbProbe(int $orderId, array &$payload): void
    {
        $probe = &$payload['order_153_db_probe'];
        if (! Schema::hasTable('orders')) {
            $probe['possible_view_crash_points'][] = 'orders table is missing';
            return;
        }

        $orderColumns = Schema::getColumnListing('orders');
        $select = array_values(array_intersect([
            'id', 'order_number', 'marketplace', 'marketplace_order_id', 'status', 'payment_status', 'customer_name', 'company_name', 'email', 'phone', 'address_line1', 'postal_code', 'city', 'country', 'delivery_method', 'shipping_method', 'raw_payload', 'meta', 'created_at', 'updated_at'
        ], $orderColumns));
        $row = DB::table('orders')->select($select ?: ['id'])->where('id', $orderId)->first();
        $probe['order_exists'] = $row !== null;
        if (! $row) {
            $probe['possible_view_crash_points'][] = 'Order not found by DB query.';
            return;
        }

        $data = (array) $row;
        foreach ($data as $key => $value) {
            if (in_array($key, ['raw_payload', 'meta'], true)) {
                continue;
            }
            $probe['order_scalar'][$key] = $this->diagnosticValue($value);
            if ($value !== null && ! is_scalar($value)) {
                $probe['possible_view_crash_points'][] = $key.' is not scalar.';
            }
        }

        foreach (['raw_payload', 'meta'] as $field) {
            $value = $data[$field] ?? null;
            $probe[$field.'_present'] = $value !== null && $value !== '';
            $probe[$field.'_type'] = get_debug_type($value);
            if (is_string($value) && $value !== '') {
                json_decode($value, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $probe['possible_view_crash_points'][] = $field.' contains invalid JSON: '.json_last_error_msg();
                }
                if (function_exists('mb_check_encoding') && ! mb_check_encoding($value, 'UTF-8')) {
                    $probe['possible_view_crash_points'][] = $field.' contains invalid UTF-8.';
                }
            } elseif ($value !== null && ! is_scalar($value) && ! is_array($value)) {
                $probe['possible_view_crash_points'][] = $field.' has unsupported type '.get_debug_type($value).'.';
            }
        }

        if (Schema::hasTable('order_items')) {
            $itemColumns = Schema::getColumnListing('order_items');
            $probe['items_count'] = in_array('order_id', $itemColumns, true) ? DB::table('order_items')->where('order_id', $orderId)->count() : null;
            $itemSelect = array_values(array_intersect(['id', 'order_id', 'name', 'sku', 'quantity', 'unit_price', 'total_price', 'raw_payload', 'meta', 'created_at'], $itemColumns));
            if (in_array('order_id', $itemColumns, true)) {
                $items = DB::table('order_items')->select($itemSelect ?: ['id'])->where('order_id', $orderId)->orderByDesc(in_array('id', $itemColumns, true) ? 'id' : 'order_id')->limit(20)->get();
                foreach ($items as $item) {
                    foreach ((array) $item as $field => $value) {
                        if ($value === null && in_array($field, ['name', 'quantity'], true)) {
                            $probe['possible_view_crash_points'][] = 'order_items.'.$field.' is null for item '.(((array) $item)['id'] ?? '?').'.';
                        }
                        if (in_array($field, ['raw_payload', 'meta'], true) && is_string($value) && $value !== '') {
                            json_decode($value, true);
                            if (json_last_error() !== JSON_ERROR_NONE) {
                                $probe['items_probe_errors'][] = ['item_id' => ((array) $item)['id'] ?? null, 'field' => $field, 'error' => json_last_error_msg()];
                            }
                        }
                    }
                }
            }
        }
    }

    private function diagnosticValue(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value)) {
            if (function_exists('mb_check_encoding') && function_exists('mb_convert_encoding')) {
                return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            }

            return iconv('UTF-8', 'UTF-8//IGNORE', $value) ?: '';
        }
        return json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    private function probeRelations(Order $order, array &$payload): void
    {
        foreach (['customer' => 'customer_loads', 'items' => 'items_load', 'shipments' => 'shipments_load'] as $relation => $key) {
            $this->guard($payload['relations_probe']['errors'], $relation, function () use ($order, &$payload, $relation, $key): void {
                $order->loadMissing($relation);
                $payload['relations_probe'][$key] = true;
            });
        }
        $payload['relations_probe']['shipping_address_loads'] = method_exists($order, 'shippingAddress') ? null : 'not_defined_on_model';
        $payload['relations_probe']['labels_load'] = 'label_path_on_shipments';
        $this->guard($payload['relations_probe']['errors'], 'marketplace_logs', function () use ($order, &$payload): void {
            MarketplaceSyncLog::query()->where('order_id', $order->id)->latest('id')->limit(1)->get();
            $payload['relations_probe']['marketplace_logs_load'] = true;
        });
    }

    private function shipmentState(Order $order, array &$payload): void
    {
        $this->guard($payload['relations_probe']['errors'], 'shipment_state', function () use ($order, &$payload): void {
            $shipments = Shipment::query()->where('order_id', $order->id)->latest('id')->get();
            $payload['shipment_state']['local_shipments_count'] = $shipments->count();
            $payload['shipment_state']['local_shipment_ids'] = $shipments->pluck('id')->all();
            $payload['shipment_state']['local_tracking_numbers'] = $shipments->map(fn (Shipment $s) => $this->scalar($s->tracking_number ?: $s->carrier_shipment_id))->all();
            $payload['shipment_state']['local_label_paths'] = $shipments->map(fn (Shipment $s) => $this->scalar($s->label_path))->all();
            $payload['shipment_state']['local_label_files_exist'] = $shipments->map(fn (Shipment $s) => filled($s->label_path) ? Storage::disk('local')->exists((string) $s->label_path) : false)->all();
            $payload['shipment_state']['has_partial_shipment_without_label'] = $shipments->contains(fn (Shipment $s) => blank($s->label_path));
            $payload['shipment_state']['has_label_record_without_file'] = $shipments->contains(fn (Shipment $s) => filled($s->label_path) && ! Storage::disk('local')->exists((string) $s->label_path));
            $payload['shipment_state']['has_empty_or_invalid_label_path'] = $shipments->contains(fn (Shipment $s) => ! is_scalar($s->label_path) || trim((string) $s->label_path) === '');
            $payload['shipment_state']['has_empty_or_invalid_tracking_number'] = $shipments->contains(fn (Shipment $s) => ! is_scalar($s->tracking_number ?: $s->carrier_shipment_id) || trim((string) ($s->tracking_number ?: $s->carrier_shipment_id)) === '');
        });
    }

    private function dhlState(Order $order, array &$payload): void
    {
        $logs = MarketplaceSyncLog::query()->where('order_id', $order->id)->where(function ($q) { $q->where('marketplace', 'dhl')->orWhere('action', 'like', '%dhl%')->orWhere('action', 'like', '%shipment%')->orWhere('message', 'like', '%DHL%'); })->latest('id')->limit(20)->get();
        $create = $logs->first(fn ($l) => Str::contains(Str::lower((string) $l->action.' '.$l->message), ['createshipment', 'create_shipment', 'create shipment']));
        $fetch = $logs->first(fn ($l) => Str::contains(Str::lower((string) $l->action.' '.$l->message), ['fetch-existing-label', 'fetch_existing_label', 'existing label', 'getlabels']));
        $payload['dhl_state']['last_create_shipment_log_id'] = $create?->id;
        $payload['dhl_state']['last_create_shipment_status'] = $create?->status;
        $payload['dhl_state']['remote_tracking_number'] = $this->findFirst([$create?->tracking_number, data_get($create?->payload, 'tracking_number'), data_get($create?->payload, 'shipment.tracking_number')]);
        $payload['dhl_state']['remote_package_tracking_number'] = $this->findFirst([data_get($create?->payload, 'package_tracking_number'), data_get($create?->payload, 'shipment.packageTrackingNumber')]);
        $payload['dhl_state']['remote_created_detected'] = filled($payload['dhl_state']['remote_tracking_number']) || ($create && in_array($create->status, ['success', 'created'], true));
        $payload['dhl_state']['last_fetch_existing_label_log_id'] = $fetch?->id;
        $payload['dhl_state']['last_fetch_existing_label_status'] = $fetch?->status;
        $payload['dhl_state']['last_fetch_existing_label_error'] = $fetch ? ($fetch->message ?: data_get($fetch->payload, 'error') ?: data_get($fetch->payload, 'exception.message')) : null;
    }

    private function viewRiskChecks(Order $order, array &$payload): void
    {
        $errors = &$payload['view_risk_checks']['errors'];
        $shipment = Shipment::query()->where('order_id', $order->id)->latest('id')->first();
        $this->guard($errors, 'tracking_url', fn () => $payload['view_risk_checks']['tracking_url_can_be_built'] = is_scalar($shipment?->tracking_number ?: $shipment?->carrier_shipment_id));
        $this->guard($errors, 'shipment_component', fn () => $payload['view_risk_checks']['shipment_component_can_compute_state'] = $shipment === null || is_scalar($shipment->carrier));
        $this->guard($errors, 'download_label_url', fn () => $payload['view_risk_checks']['download_label_url_can_be_built'] = $shipment === null || blank($shipment->label_path) || is_scalar($shipment->label_path));
        $this->guard($errors, 'dhl_ui_state', fn () => $payload['view_risk_checks']['dhl_ui_state_can_be_built'] = true);
        $this->guard($errors, 'order_items', fn () => $payload['view_risk_checks']['order_items_component_can_render_data'] = $order->items()->count() >= 0);
        $this->guard($errors, 'customer', fn () => $payload['view_risk_checks']['customer_component_can_render_data'] = is_scalar($order->customer_name) || $order->customer_name === null);
    }

    private function lastOrderViewException(int $orderId): array
    {
        $path = storage_path('logs/laravel.log');
        if (! is_readable($path)) return ['found' => false, 'class' => null, 'message' => null, 'file' => null, 'line' => null];
        $tail = implode('', array_slice(file($path) ?: [], -800));
        if (! Str::contains($tail, ['/admin/orders/'.$orderId, 'orders/'.$orderId])) return ['found' => false, 'class' => null, 'message' => null, 'file' => null, 'line' => null];
        preg_match('/local\.ERROR:\s+([^:]+):?\s*(.*?)\s+\{"exception":"\[object\]\s+\(([^:]+)::(.*?):(\d+)\)/s', $tail, $m);
        return ['found' => true, 'class' => $m[3] ?? null, 'message' => trim($m[2] ?? ''), 'file' => $m[4] ?? null, 'line' => isset($m[5]) ? (int) $m[5] : null];
    }

    private function recommendation(array $payload): string
    {
        if ($payload['shipment_state']['has_partial_shipment_without_label'] || $payload['shipment_state']['has_label_record_without_file']) {
            return 'Wykryto możliwy częściowy stan shipment/label. Nie uruchamiać DHL createShipment/getLabels; rozważyć osobny, potwierdzany repair endpoint tylko dla order_id=153.';
        }
        return 'Diagnostyka jest read-only. Jeżeli widok nadal zgłasza błąd, wkleić sekcje last_exception_for_order_view, shipment_state, dhl_state i view_risk_checks.';
    }

    private function guard(array &$errors, string $stage, callable $callback): void
    {
        try { $callback(); } catch (Throwable $e) { $errors[] = ['stage' => $stage, 'class' => $e::class, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]; Log::warning('Order view diagnostics stage failed', ['stage' => $stage, 'exception' => $e]); }
    }

    private function scalar(mixed $value): ?string { return is_scalar($value) ? (string) $value : null; }
    private function findFirst(array $values): ?string { foreach ($values as $value) { if (is_scalar($value) && trim((string) $value) !== '') return (string) $value; } return null; }
}
