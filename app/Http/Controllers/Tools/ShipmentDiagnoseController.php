<?php

namespace App\Http\Controllers\Tools;

use App\Filament\Pages\Shipments as ShipmentsPage;
use App\Http\Controllers\Controller;
use App\Models\Shipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ShipmentDiagnoseController extends Controller
{
    private const CODE_MARKER = 'shipment_module_crash_diagnostics_safe_v1';
    private const TRACKING = '31294120912';
    private const PACKAGE_TRACKING = 'JJD000030249582000000000373';
    private const RECENT_SINCE = '2026-07-09 10:39:00';

    public function __invoke(Request $request): JsonResponse
    {
        $orderId = (int) $request->integer('order_id');
        $payload = $this->emptyPayload($orderId);

        $this->guard($payload['shipment_tables']['errors'], 'shipment_tables', fn () => $this->probeTables($payload));
        $this->guard($payload['shipments_probe']['errors'], 'shipments_probe', fn () => $this->probeShipments($payload, $orderId));
        $this->guard($payload['labels_probe']['errors'], 'labels_probe', fn () => $this->probeLabels($payload, $orderId));
        $this->guard($payload['filament_shipments_risk']['errors'], 'filament_risk', fn () => $this->probeFilamentRisk($payload));
        $this->guard($payload['last_exceptions']['errors'], 'last_exceptions', fn () => $this->probeLastExceptions($payload, $orderId));

        $payload['safe_recommendation'] = $this->recommendation($payload);

        return response()->json($payload, 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function emptyPayload(int $orderId): array
    {
        return [
            'code_marker' => self::CODE_MARKER,
            'order_id' => $orderId,
            'shipment_tables' => ['candidate_tables_checked' => [], 'shipments_table_exists' => null, 'shipment_labels_table_exists' => null, 'errors' => []],
            'shipments_probe' => ['records_for_order' => [], 'records_for_tracking' => [], 'recent_records' => [], 'partial_or_suspicious_records' => [], 'errors' => []],
            'labels_probe' => ['records_for_order_or_shipment' => [], 'missing_files' => [], 'empty_paths' => [], 'errors' => []],
            'filament_shipments_risk' => ['resource_class_exists' => null, 'model_class_exists' => null, 'table_columns_checked' => [], 'suspected_crash_points' => [], 'errors' => []],
            'last_exceptions' => ['admin_shipments' => null, 'admin_order_153' => null, 'view_diagnose' => null, 'errors' => []],
            'safe_recommendation' => null,
        ];
    }

    private function probeTables(array &$payload): void
    {
        $candidates = ['shipments', 'shipment_labels', 'labels'];
        $payload['shipment_tables']['candidate_tables_checked'] = $candidates;
        $payload['shipment_tables']['shipments_table_exists'] = Schema::hasTable('shipments');
        $payload['shipment_tables']['shipment_labels_table_exists'] = Schema::hasTable('shipment_labels');
    }

    private function probeShipments(array &$payload, int $orderId): void
    {
        if (! Schema::hasTable('shipments')) return;

        $columns = Schema::getColumnListing('shipments');
        $select = array_values(array_intersect($columns, ['id', 'order_id', 'carrier', 'service_code', 'shipment_status', 'tracking_number', 'carrier_shipment_id', 'label_path', 'label_format', 'created_at', 'updated_at', 'response_payload', 'request_payload']));
        $base = DB::table('shipments')->select($select ?: ['*']);
        $payload['shipments_probe']['records_for_order'] = (clone $base)->where('order_id', $orderId)->latest('id')->limit(20)->get()->map(fn ($r) => (array) $r)->all();
        $payload['shipments_probe']['records_for_tracking'] = (clone $base)->where(function ($q): void {
            $q->where('tracking_number', self::TRACKING)->orWhere('carrier_shipment_id', self::TRACKING)->orWhere('tracking_number', self::PACKAGE_TRACKING)->orWhere('carrier_shipment_id', self::PACKAGE_TRACKING);
        })->latest('id')->limit(20)->get()->map(fn ($r) => (array) $r)->all();
        if (in_array('created_at', $columns, true)) {
            $payload['shipments_probe']['recent_records'] = (clone $base)->where('created_at', '>=', self::RECENT_SINCE)->latest('id')->limit(30)->get()->map(fn ($r) => (array) $r)->all();
        }

        $payload['shipments_probe']['partial_or_suspicious_records'] = collect($payload['shipments_probe']['records_for_order'])
            ->merge($payload['shipments_probe']['records_for_tracking'])
            ->merge($payload['shipments_probe']['recent_records'])
            ->unique('id')
            ->filter(fn (array $r): bool => blank($r['tracking_number'] ?? null) || blank($r['label_path'] ?? null) || $this->looksJsonContainer($r['carrier'] ?? null) || $this->looksJsonContainer($r['shipment_status'] ?? null) || $this->looksJsonContainer($r['service_code'] ?? null))
            ->values()->all();
    }

    private function probeLabels(array &$payload, int $orderId): void
    {
        $shipmentIds = collect($payload['shipments_probe']['records_for_order'])->pluck('id')->filter()->values()->all();
        foreach ($payload['shipments_probe']['records_for_order'] as $record) {
            $path = $record['label_path'] ?? null;
            if (blank($path)) { $payload['labels_probe']['empty_paths'][] = $record; continue; }
            if (is_scalar($path) && ! Storage::disk('local')->exists((string) $path)) $payload['labels_probe']['missing_files'][] = ['shipment_id' => $record['id'] ?? null, 'path' => (string) $path];
        }
        if (Schema::hasTable('shipment_labels')) {
            $query = DB::table('shipment_labels');
            if (Schema::hasColumn('shipment_labels', 'order_id')) $query->where('order_id', $orderId);
            elseif ($shipmentIds && Schema::hasColumn('shipment_labels', 'shipment_id')) $query->whereIn('shipment_id', $shipmentIds);
            $payload['labels_probe']['records_for_order_or_shipment'] = $query->latest('id')->limit(20)->get()->map(fn ($r) => (array) $r)->all();
        }
    }

    private function probeFilamentRisk(array &$payload): void
    {
        $payload['filament_shipments_risk']['resource_class_exists'] = class_exists(ShipmentsPage::class);
        $payload['filament_shipments_risk']['model_class_exists'] = class_exists(Shipment::class);
        $payload['filament_shipments_risk']['table_columns_checked'] = ['carrier', 'shipment_status', 'tracking_number', 'carrier_shipment_id', 'label_path'];
        $payload['filament_shipments_risk']['suspected_crash_points'] = ['label download link when path is empty/missing', 'string formatting when carrier/status/tracking are non-scalar', 'Storage::exists with invalid label_path'];
    }

    private function probeLastExceptions(array &$payload, int $orderId): void
    {
        $tail = $this->logTail();
        $payload['last_exceptions']['admin_shipments'] = $this->findException($tail, '/admin/shipments');
        $payload['last_exceptions']['admin_order_153'] = $this->findException($tail, '/admin/orders/'.$orderId);
        $payload['last_exceptions']['view_diagnose'] = $this->findException($tail, '/admin/tools/orders/view-diagnose');
    }

    private function findException(string $tail, string $needle): ?array
    {
        $pos = strrpos($tail, $needle);
        if ($pos === false) return null;
        $chunk = substr($tail, max(0, $pos - 6000), 12000);
        preg_match('/\[object\]\s+\(([^:]+)::([^:]+):(\d+)\).*?Stack trace:\n(.*?)(?:\n\[previous exception\]|\n\[\d{4}-\d{2}-\d{2}|$)/s', $chunk, $m);
        return ['class' => $m[1] ?? null, 'message' => null, 'file' => $m[2] ?? null, 'line' => isset($m[3]) ? (int) $m[3] : null, 'top_10_stack_trace' => isset($m[4]) ? array_slice(explode("\n", trim($m[4])), 0, 10) : []];
    }

    private function logTail(): string
    {
        $path = storage_path('logs/laravel.log');
        return is_readable($path) ? implode('', array_slice(file($path) ?: [], -1500)) : '';
    }

    private function recommendation(array $payload): string
    {
        return $payload['shipments_probe']['partial_or_suspicious_records'] || $payload['labels_probe']['missing_files'] || $payload['labels_probe']['empty_paths']
            ? 'Wykryto możliwy partial/broken shipment/label. Nie naprawiać automatycznie; przygotować osobny POST+CSRF repair endpoint z exact confirm tylko dla order_id=153, bez requestów DHL.'
            : 'Brak jednoznacznego partial shipment w probach DB-only. Wkleić last_exceptions oraz probes do dalszej analizy.';
    }

    private function guard(array &$errors, string $stage, callable $callback): void
    {
        try { $callback(); } catch (Throwable $e) { $errors[] = ['stage' => $stage, 'class' => $e::class, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]; }
    }

    private function looksJsonContainer(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\s*[\[{]/', $value) === 1;
    }
}
