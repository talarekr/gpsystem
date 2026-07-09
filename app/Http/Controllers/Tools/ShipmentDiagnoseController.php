<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ShipmentDiagnoseController extends Controller
{
    private const CODE_MARKER = 'shipment_module_crash_diagnostics_safe_v2';
    private const TRACKING = '31294120912';
    private const PACKAGE_TRACKING = 'JJD000030249582000000000373';
    private const RECENT_SINCE = '2026-07-09 10:39:00';

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $orderId = (int) $request->integer('order_id');

            if ($request->boolean('minimal')) {
                return response()->json($this->minimalPayload($orderId), 200, [], $this->jsonFlags());
            }

            $payload = $this->emptyPayload($orderId);

            $this->guard($payload, 'input', fn () => $this->probeInput($payload, $request, $orderId));
            $this->guard($payload, 'app', fn () => $this->probeApp($payload));
            $this->guard($payload, 'table_discovery', fn () => $this->probeTables($payload));
            $this->guard($payload, 'shipments_probe', fn () => $this->probeShipments($payload, $orderId));
            $this->guard($payload, 'labels_probe', fn () => $this->probeLabels($payload, $orderId));
            $this->guard($payload, 'last_exceptions_probe', fn () => $this->probeLastExceptions($payload, $orderId));

            $failed = $payload['diagnostics_health']['sections_failed'];
            $payload['diagnostics_health']['ok'] = $failed === [];
            $payload['diagnostics_health']['status'] = $failed === [] ? 'ok' : 'partial';
            $payload['status'] = $payload['diagnostics_health']['status'];

            return response()->json($payload, 200, [], $this->jsonFlags());
        } catch (Throwable $e) {
            return response()->json([
                'code_marker' => self::CODE_MARKER,
                'status' => 'error',
                'errors' => [[
                    'section' => 'top_level',
                    'class' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]],
                'diagnostics_health' => [
                    'ok' => false,
                    'status' => 'error',
                    'sections_completed' => [],
                    'sections_failed' => ['top_level'],
                ],
            ], 200);
        }
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
                'laravel_version' => app()->version(),
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
            'minimal' => false,
            'status' => 'unknown',
            'order_id' => $orderId,
            'input' => [],
            'app' => [],
            'table_discovery' => ['candidate_tables_checked' => [], 'tables' => [], 'columns' => []],
            'shipments_probe' => ['records_for_order' => [], 'records_for_tracking' => [], 'recent_records' => [], 'partial_or_suspicious_records' => []],
            'labels_probe' => ['records_for_order_or_shipment' => [], 'empty_paths' => []],
            'last_exceptions_probe' => ['admin_shipments' => null, 'admin_order' => null, 'shipment_diagnose' => null, 'view_diagnose' => null],
            'diagnostics_health' => ['ok' => false, 'status' => 'unknown', 'sections_completed' => [], 'sections_failed' => []],
            'errors' => [],
        ];
    }

    private function probeInput(array &$payload, Request $request, int $orderId): void
    {
        $payload['input'] = [
            'order_id' => $orderId,
            'json' => $request->query('json'),
            'minimal' => $request->query('minimal'),
            'path' => $request->path(),
        ];
    }

    private function probeApp(array &$payload): void
    {
        $payload['app'] = [
            'environment' => app()->environment(),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
        ];
    }

    private function probeTables(array &$payload): void
    {
        $candidates = ['shipments', 'shipment_labels', 'labels'];
        $payload['table_discovery']['candidate_tables_checked'] = $candidates;

        foreach ($candidates as $table) {
            $exists = Schema::hasTable($table);
            $payload['table_discovery']['tables'][$table] = $exists;
            $payload['table_discovery']['columns'][$table] = $exists ? Schema::getColumnListing($table) : [];
        }
    }

    private function probeShipments(array &$payload, int $orderId): void
    {
        if (! $this->tableExists($payload, 'shipments')) {
            return;
        }

        $columns = $this->columns($payload, 'shipments');
        $select = $this->safeSelect($columns, ['id', 'order_id', 'carrier', 'service_code', 'shipment_status', 'tracking_number', 'carrier_shipment_id', 'label_path', 'label_format', 'created_at', 'updated_at', 'response_payload', 'request_payload']);
        $base = DB::table('shipments')->select($select);

        if (in_array('order_id', $columns, true)) {
            $payload['shipments_probe']['records_for_order'] = $this->rows((clone $base)->where('order_id', $orderId), $columns);
        }

        $trackingColumns = array_values(array_intersect($columns, ['tracking_number', 'carrier_shipment_id']));
        if ($trackingColumns !== []) {
            $payload['shipments_probe']['records_for_tracking'] = $this->rows((clone $base)->where(function ($query) use ($trackingColumns): void {
                foreach ($trackingColumns as $index => $column) {
                    foreach ([self::TRACKING, self::PACKAGE_TRACKING] as $tracking) {
                        $index === 0 ? $query->orWhere($column, $tracking) : $query->orWhere($column, $tracking);
                    }
                }
            }), $columns);
        }

        if (in_array('created_at', $columns, true)) {
            $payload['shipments_probe']['recent_records'] = $this->rows((clone $base)->where('created_at', '>=', self::RECENT_SINCE), $columns, 30);
        }

        $payload['shipments_probe']['partial_or_suspicious_records'] = collect($payload['shipments_probe']['records_for_order'])
            ->merge($payload['shipments_probe']['records_for_tracking'])
            ->merge($payload['shipments_probe']['recent_records'])
            ->unique('id')
            ->filter(fn (array $record): bool => blank($record['tracking_number'] ?? null) || blank($record['label_path'] ?? null) || $this->looksJsonContainer($record['carrier'] ?? null) || $this->looksJsonContainer($record['shipment_status'] ?? null) || $this->looksJsonContainer($record['service_code'] ?? null))
            ->values()
            ->all();
    }

    private function probeLabels(array &$payload, int $orderId): void
    {
        foreach ($payload['shipments_probe']['records_for_order'] as $record) {
            if (array_key_exists('label_path', $record) && blank($record['label_path'])) {
                $payload['labels_probe']['empty_paths'][] = ['shipment_id' => $record['id'] ?? null, 'label_path' => $record['label_path'] ?? null];
            }
        }

        if (! $this->tableExists($payload, 'shipment_labels')) {
            return;
        }

        $columns = $this->columns($payload, 'shipment_labels');
        $select = $this->safeSelect($columns, ['id', 'order_id', 'shipment_id', 'label_path', 'path', 'format', 'created_at', 'updated_at']);
        $query = DB::table('shipment_labels')->select($select);

        if (in_array('order_id', $columns, true)) {
            $query->where('order_id', $orderId);
        } elseif (in_array('shipment_id', $columns, true)) {
            $shipmentIds = collect($payload['shipments_probe']['records_for_order'])->pluck('id')->filter()->values()->all();
            if ($shipmentIds === []) {
                return;
            }
            $query->whereIn('shipment_id', $shipmentIds);
        } else {
            return;
        }

        $payload['labels_probe']['records_for_order_or_shipment'] = $this->rows($query, $columns);
    }

    private function probeLastExceptions(array &$payload, int $orderId): void
    {
        $tail = $this->logTail();
        $payload['last_exceptions_probe']['admin_shipments'] = $this->findException($tail, '/admin/shipments');
        $payload['last_exceptions_probe']['admin_order'] = $this->findException($tail, '/admin/orders/'.$orderId);
        $payload['last_exceptions_probe']['shipment_diagnose'] = $this->findException($tail, '/admin/tools/shipments/diagnose');
        $payload['last_exceptions_probe']['view_diagnose'] = $this->findException($tail, '/admin/tools/orders/view-diagnose');
    }

    private function guard(array &$payload, string $section, callable $callback): void
    {
        try {
            $callback();
            $payload['diagnostics_health']['sections_completed'][] = $section;
        } catch (Throwable $e) {
            $payload['diagnostics_health']['sections_failed'][] = $section;
            $payload['errors'][] = [
                'section' => $section,
                'class' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ];
        }
    }

    private function rows($query, array $columns, int $limit = 20): array
    {
        $query = in_array('id', $columns, true) ? $query->orderByDesc('id') : $query;

        return $query->limit($limit)->get()->map(fn ($row): array => (array) $row)->all();
    }

    private function tableExists(array $payload, string $table): bool
    {
        return (bool) ($payload['table_discovery']['tables'][$table] ?? false);
    }

    private function columns(array $payload, string $table): array
    {
        return $payload['table_discovery']['columns'][$table] ?? [];
    }

    private function safeSelect(array $columns, array $preferred): array
    {
        $select = array_values(array_intersect($preferred, $columns));

        return $select !== [] ? $select : [DB::raw('1 as probe')];
    }

    private function findException(string $tail, string $needle): ?array
    {
        $pos = strrpos($tail, $needle);
        if ($pos === false) {
            return null;
        }

        $chunk = substr($tail, max(0, $pos - 6000), 12000);
        preg_match('/\[object\]\s+\(([^:]+)::([^:]+):(\d+)\).*?Stack trace:\n(.*?)(?:\n\[previous exception\]|\n\[\d{4}-\d{2}-\d{2}|$)/s', $chunk, $matches);

        return [
            'class' => $matches[1] ?? null,
            'message' => null,
            'file' => $matches[2] ?? null,
            'line' => isset($matches[3]) ? (int) $matches[3] : null,
            'top_10_stack_trace' => isset($matches[4]) ? array_slice(explode("\n", trim($matches[4])), 0, 10) : [],
        ];
    }

    private function logTail(): string
    {
        $path = storage_path('logs/laravel.log');
        if (! is_string($path) || ! is_readable($path)) {
            return '';
        }

        $lines = file($path);

        return $lines === false ? '' : implode('', array_slice($lines, -1500));
    }

    private function looksJsonContainer(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\s*[\[{]/', $value) === 1;
    }

    private function jsonFlags(): int
    {
        return JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    }
}
