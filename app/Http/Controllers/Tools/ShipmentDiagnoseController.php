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
    private const CODE_MARKER = 'shipment_module_crash_diagnostics_safe_v3';
    private const TRACKING = '31294120912';
    private const PACKAGE_TRACKING = 'JJD000030249582000000000373';
    private const RECENT_SINCE = '2026-07-09 10:39:00';

    public function __invoke(Request $request): JsonResponse
    {
        try {
            return $this->handleSafely($request);
        } catch (Throwable $e) {
            return $this->safeJsonResponse([
                'code_marker' => self::CODE_MARKER,
                'status' => 'error',
                'errors' => [$this->errorArray('top_level', $e)],
                'diagnostics_health' => [
                    'ok' => false,
                    'status' => 'error',
                    'sections_completed' => [],
                    'sections_failed' => ['top_level'],
                ],
            ]);
        }
    }

    private function handleSafely(Request $request): JsonResponse
    {
        $orderId = (int) $request->integer('order_id');

        if ($request->boolean('minimal')) {
            return $this->safeJsonResponse($this->minimalPayload($orderId));
        }

        $sections = $this->sectionsFor($request);
        $sectionOnly = $request->query('section');
        $payload = $this->emptyPayload($orderId);

        if (is_string($sectionOnly) && $sectionOnly !== '') {
            if (! in_array($sectionOnly, $this->allSections(), true)) {
                return $this->safeJsonResponse([
                    'code_marker' => self::CODE_MARKER,
                    'section_only' => $sectionOnly,
                    'status' => 'error',
                    'section_result' => null,
                    'errors' => [[
                        'section' => 'input',
                        'class' => 'InvalidArgumentException',
                        'message' => 'Unknown section. Allowed: '.implode(', ', $this->allSections()),
                    ]],
                ]);
            }

            $this->runSection($payload, $sectionOnly, $request, $orderId);
            $failed = in_array($sectionOnly, $payload['diagnostics_health']['sections_failed'], true);
            $status = $failed ? 'error' : ($payload['errors'] === [] ? 'ok' : 'partial');

            return $this->safeJsonResponse([
                'code_marker' => self::CODE_MARKER,
                'section_only' => $sectionOnly,
                'status' => $status,
                'section_result' => $payload[$sectionOnly] ?? null,
                'errors' => $payload['errors'],
                'diagnostics_health' => $payload['diagnostics_health'],
            ]);
        }

        foreach ($sections as $section) {
            $this->runSection($payload, $section, $request, $orderId);
        }

        $failed = $payload['diagnostics_health']['sections_failed'];
        $payload['diagnostics_health']['ok'] = $failed === [] && $payload['errors'] === [];
        $payload['diagnostics_health']['status'] = $failed === [] && $payload['errors'] === [] ? 'ok' : 'partial';
        $payload['status'] = $payload['diagnostics_health']['status'];
        $payload['safe'] = $request->boolean('safe');
        $payload['until'] = $request->query('until');

        return $this->safeJsonResponse($payload);
    }

    /** @return array<int, string> */
    private function allSections(): array
    {
        return ['input', 'app', 'table_discovery', 'shipments_probe', 'labels_probe', 'last_exceptions_probe'];
    }

    /** @return array<int, string> */
    private function sectionsFor(Request $request): array
    {
        if ($request->boolean('safe')) {
            return ['input', 'app', 'table_discovery'];
        }

        $sections = $this->allSections();
        $until = $request->query('until');
        if (is_string($until) && $until !== '' && in_array($until, $sections, true)) {
            return array_slice($sections, 0, array_search($until, $sections, true) + 1);
        }

        return $sections;
    }

    private function runSection(array &$payload, string $section, Request $request, int $orderId): void
    {
        $this->guard($payload, $section, match ($section) {
            'input' => fn () => $this->probeInput($payload, $request, $orderId),
            'app' => fn () => $this->probeApp($payload),
            'table_discovery' => fn () => $this->probeTables($payload),
            'shipments_probe' => fn () => $this->probeShipments($payload, $orderId),
            'labels_probe' => fn () => $this->probeLabels($payload, $orderId),
            'last_exceptions_probe' => fn () => $this->probeLastExceptions($payload, $orderId),
            default => fn () => null,
        });
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
            $this->discoverTable($payload, $table);
        }
    }

    private function probeShipments(array &$payload, int $orderId): void
    {
        if (! array_key_exists('shipments', $payload['table_discovery']['tables'])) {
            $this->discoverTable($payload, 'shipments');
        }

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

        if (! array_key_exists('shipment_labels', $payload['table_discovery']['tables'])) {
            $this->discoverTable($payload, 'shipment_labels');
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
            $payload['errors'][] = $this->errorArray($section, $e);
        }
    }

    private function rows($query, array $columns, int $limit = 20): array
    {
        $query = in_array('id', $columns, true) ? $query->orderByDesc('id') : $query;

        return $query->limit($limit)->get()->map(fn ($row): array => $this->sanitizeRow((array) $row))->all();
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
        try {
            $path = storage_path('logs/laravel.log');
            if (! is_string($path) || ! file_exists($path) || ! is_readable($path)) {
                return '';
            }

            $size = filesize($path);
            if ($size === false || $size <= 0) {
                return '';
            }

            $handle = fopen($path, 'rb');
            if ($handle === false) {
                return '';
            }

            $bytes = 200 * 1024;
            fseek($handle, -min($bytes, $size), SEEK_END);
            $tail = stream_get_contents($handle);
            fclose($handle);

            return $this->safeString($tail === false ? '' : $tail);
        } catch (Throwable) {
            return '';
        }
    }

    private function discoverTable(array &$payload, string $table): void
    {
        try {
            $exists = Schema::hasTable($table);
            $payload['table_discovery']['tables'][$table] = (bool) $exists;
        } catch (Throwable $e) {
            $payload['table_discovery']['tables'][$table] = false;
            $payload['table_discovery']['columns'][$table] = [];
            $payload['errors'][] = $this->errorArray('table_discovery.'.$table.'.has_table', $e);

            return;
        }

        if (! $payload['table_discovery']['tables'][$table]) {
            $payload['table_discovery']['columns'][$table] = [];

            return;
        }

        try {
            $payload['table_discovery']['columns'][$table] = array_values(array_map(
                fn ($column): string => (string) $column,
                Schema::getColumnListing($table)
            ));
        } catch (Throwable $e) {
            $payload['table_discovery']['columns'][$table] = [];
            $payload['errors'][] = $this->errorArray('table_discovery.'.$table.'.columns', $e);
        }
    }

    /** @param array<string, mixed> $row */
    private function sanitizeRow(array $row): array
    {
        $safe = [];
        foreach ($row as $key => $value) {
            $safe[(string) $key] = $this->sanitizeValue($value);
        }

        return $safe;
    }

    private function sanitizeValue(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return $this->safeString($value);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if ($value instanceof Throwable) {
            return $this->errorArray('value', $value);
        }

        if (is_array($value)) {
            return array_map(fn ($item) => $this->sanitizeValue($item), $value);
        }

        return $this->safeString((string) json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    private function safeString(string $value): string
    {
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        return iconv('UTF-8', 'UTF-8//IGNORE', $value) ?: '';
    }

    private function errorArray(string $section, Throwable $e): array
    {
        return [
            'section' => $section,
            'class' => get_class($e),
            'message' => $this->safeString($e->getMessage()),
            'file' => $this->safeString($e->getFile()),
            'line' => $e->getLine(),
        ];
    }

    private function safeJsonResponse(array $payload): JsonResponse
    {
        return response()->json($this->sanitizeValue($payload), 200, [], $this->jsonFlags());
    }

    private function looksJsonContainer(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\s*[\[{]/', $value) === 1;
    }

    private function jsonFlags(): int
    {
        return JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;
    }
}
