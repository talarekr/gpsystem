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
    private const CODE_MARKER = 'shipment_module_crash_diagnostics_safe_v4';
    private const TRACKING = '31294120912';
    private const PACKAGE_TRACKING = 'JJD000030249582000000000373';
    private const RECENT_SINCE = '2026-07-09 10:39:00';
    private const CANDIDATE_TABLES = [
        'shipments',
        'shipment_labels',
        'shipping_labels',
        'order_shipments',
        'orders',
        'marketplace_sync_logs',
        'api_integration_logs',
        'integration_logs',
        'order_logs',
        'labels',
    ];

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

        if ($request->query('section') === 'candidate_tables') {
            $tables = self::CANDIDATE_TABLES;

            return $this->safeJsonResponse([
                'code_marker' => 'shipment_module_crash_diagnostics_safe_v4_candidate_direct',
                'section_only' => 'candidate_tables',
                'status' => 'ok',
                'section_result' => [
                    'candidate_tables_checked' => array_values($tables),
                    'count' => count($tables),
                ],
                'errors' => [],
                'diagnostics_health' => [
                    'ok' => true,
                    'status' => 'ok',
                    'sections_completed' => ['candidate_tables'],
                    'sections_failed' => [],
                ],
            ], 200);
        }

        if ($request->query('section') === 'table_discovery') {
            $payload = $this->emptyPayload($orderId, $request);
            $this->probeTables($payload);
            $status = $payload['errors'] === [] ? 'ok' : 'partial';

            return $this->safeJsonResponse([
                'code_marker' => self::CODE_MARKER,
                'section_only' => 'table_discovery',
                'status' => $status,
                'section_result' => $payload['table_discovery'],
                'errors' => $payload['errors'],
                'diagnostics_health' => [
                    'ok' => $status === 'ok',
                    'status' => $status,
                    'sections_completed' => ['table_discovery'],
                    'sections_failed' => [],
                ],
            ], 200);
        }

        if ($request->query('section') === 'app') {
            return $this->safeJsonResponse([
                'code_marker' => self::CODE_MARKER,
                'section_only' => 'app',
                'status' => 'ok',
                'section_result' => [
                    'environment' => $this->safeAppEnvironment(),
                    'php_version' => PHP_VERSION,
                    'laravel_version' => $this->safeLaravelVersion(),
                ],
                'errors' => [],
                'diagnostics_health' => [
                    'ok' => true,
                    'status' => 'ok',
                    'sections_completed' => ['app'],
                    'sections_failed' => [],
                ],
            ], 200);
        }

        if ($request->query('section') === 'input') {
            return $this->safeJsonResponse([
                'code_marker' => self::CODE_MARKER,
                'section_only' => 'input',
                'status' => 'ok',
                'section_result' => [
                    'order_id' => $orderId,
                    'safe' => $this->booleanQuery($request, 'safe'),
                    'section' => $request->query('section'),
                    'until' => $request->query('until'),
                ],
                'errors' => [],
                'diagnostics_health' => [
                    'ok' => true,
                    'status' => 'ok',
                    'sections_completed' => ['input'],
                    'sections_failed' => [],
                ],
            ], 200);
        }

        $sections = $this->sectionsFor($request);
        $sectionOnly = $request->query('section');
        $payload = $this->emptyPayload($orderId, $request);

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
            $payload['diagnostics_health']['ok'] = $status === 'ok';
            $payload['diagnostics_health']['status'] = $status;

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
        $payload['safe'] = $this->booleanQuery($request, 'safe');
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
            return $this->allSections();
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

    private function emptyPayload(int $orderId, ?Request $request = null): array
    {
        return [
            'code_marker' => self::CODE_MARKER,
            'diagnostics_build' => $this->diagnosticsBuild($request),
            'minimal' => false,
            'status' => 'unknown',
            'order_id' => $orderId,
            'input' => ['order_id' => $orderId, 'safe' => $request ? $this->booleanQuery($request, 'safe') : false, 'section' => $request?->query('section'), 'until' => $request?->query('until')],
            'app' => ['environment' => null, 'php_version' => null, 'laravel_version' => null],
            'known_tracking_context' => $this->knownTrackingContext($orderId),
            'table_discovery' => ['candidate_tables_checked' => $this->candidateTables(), 'tables' => [], 'columns' => []],
            'shipments_probe' => ['records_for_order' => [], 'records_for_tracking' => [], 'recent_records' => [], 'partial_or_suspicious_records' => [], 'warnings' => []],
            'labels_probe' => ['records_for_order_or_shipment' => [], 'empty_paths' => [], 'warnings' => []],
            'last_exceptions_probe' => ['admin_shipments' => null, 'admin_order' => null, 'shipment_diagnose' => null, 'view_diagnose' => null, 'integration_logs' => []],
            'diagnostics_health' => ['ok' => false, 'status' => 'unknown', 'sections_completed' => [], 'sections_failed' => []],
            'errors' => [],
        ];
    }

    private function diagnosticsBuild(?Request $request): array
    {
        $candidates = $this->candidateTables();

        return [
            'code_marker' => self::CODE_MARKER,
            'contains_candidate_table_list' => in_array('shipments', $candidates, true) && in_array('labels', $candidates, true),
            'candidate_table_list_count' => count($candidates),
            'safe_param_raw' => $request ? $this->rawQueryStringValue($request, 'safe') : null,
            'safe_param_boolean' => $request ? $this->booleanQuery($request, 'safe') : false,
        ];
    }

    private function booleanQuery(Request $request, string $key): bool
    {
        $value = $request->query($key);

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
        }

        return false;
    }

    private function rawQueryStringValue(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
        }

        return $value === null ? null : (string) $value;
    }

    private function safeAppEnvironment(): ?string
    {
        try {
            return (string) app()->environment();
        } catch (Throwable) {
            return function_exists('config') ? (string) config('app.env') : null;
        }
    }

    private function safeLaravelVersion(): ?string
    {
        try {
            return (string) app()->version();
        } catch (Throwable) {
            return class_exists(\Illuminate\Foundation\Application::class) ? \Illuminate\Foundation\Application::VERSION : null;
        }
    }

    private function knownTrackingContext(int $orderId): array
    {
        return [
            'order_id' => $orderId,
            'remote_tracking_number' => $orderId === 153 ? self::TRACKING : null,
            'remote_package_tracking_number' => $orderId === 153 ? self::PACKAGE_TRACKING : null,
            'first_failed_fetch_after' => $orderId === 153 ? self::RECENT_SINCE : null,
        ];
    }

    /** @return array<int, string> */
    private function candidateTables(): array
    {
        return self::CANDIDATE_TABLES;
    }

    /** @return array<int, string> */
    private function relevantColumns(): array
    {
        return ['id', 'order_id', 'shipment_id', 'tracking_number', 'tracking', 'shipment_tracking_number', 'external_id', 'external_tracking_number', 'carrier', 'service', 'service_code', 'status', 'shipment_status', 'action', 'integration', 'marketplace', 'label_path', 'label_file_path', 'path', 'file_path', 'created_at', 'updated_at', 'metadata', 'meta', 'raw_payload', 'payload', 'request_payload', 'response_payload'];
    }

    private function probeInput(array &$payload, Request $request, int $orderId): void
    {
        $payload['input'] = [
            'order_id' => $orderId,
            'safe' => $this->booleanQuery($request, 'safe'),
            'section' => $request->query('section'),
            'until' => $request->query('until'),
        ];
    }

    private function probeApp(array &$payload): void
    {
        $payload['app'] = [
            'environment' => $this->safeAppEnvironment(),
            'php_version' => PHP_VERSION,
            'laravel_version' => $this->safeLaravelVersion(),
        ];
    }

    private function probeCandidateTables(array &$payload): void
    {
        $candidates = $this->candidateTables();

        $payload['candidate_tables'] = [
            'candidate_tables_checked' => $candidates,
            'count' => count($candidates),
        ];
    }

    private function probeTables(array &$payload): void
    {
        $candidates = $this->candidateTables();
        $payload['table_discovery']['candidate_tables_checked'] = $candidates;

        foreach ($candidates as $table) {
            $this->discoverTable($payload, $table);
        }
    }

    private function probeShipments(array &$payload, int $orderId): void
    {
        $this->ensureDiscovery($payload);
        $shipmentTables = array_values(array_filter(['shipments', 'order_shipments'], fn (string $table): bool => $this->tableExists($payload, $table)));
        if (! $this->hasExistingCandidateTable($payload)) {
            $payload['shipments_probe']['warnings'] = ['No candidate shipment tables found'];
            return;
        }

        $trackingColumns = ['tracking_number', 'tracking', 'shipment_tracking_number', 'external_id', 'external_tracking_number', 'carrier_shipment_id'];

        foreach ($shipmentTables as $table) {
            $columns = $this->columns($payload, $table);
            $select = $this->safeSelect($columns, $this->relevantColumns());
            $base = DB::table($table)->select($select);

            if (in_array('order_id', $columns, true) && $orderId > 0) {
                $payload['shipments_probe']['records_for_order'][$table] = $this->rows((clone $base)->where('order_id', $orderId), $columns);
            }

            $presentTrackingColumns = array_values(array_intersect($trackingColumns, $columns));
            if ($presentTrackingColumns !== []) {
                $payload['shipments_probe']['records_for_tracking'][$table] = $this->rows((clone $base)->where(function ($query) use ($presentTrackingColumns): void {
                    foreach ($presentTrackingColumns as $column) {
                        foreach ([self::TRACKING, self::PACKAGE_TRACKING] as $tracking) {
                            $query->orWhere($column, $tracking);
                        }
                    }
                }), $columns);
            }

            if (in_array('created_at', $columns, true)) {
                $payload['shipments_probe']['recent_records'][$table] = $this->rows((clone $base)->where('created_at', '>=', self::RECENT_SINCE), $columns, 30);
            }
        }

        $payload['shipments_probe']['partial_or_suspicious_records'] = collect($payload['shipments_probe']['records_for_order'])->flatten(1)
            ->merge(collect($payload['shipments_probe']['records_for_tracking'])->flatten(1))
            ->merge(collect($payload['shipments_probe']['recent_records'])->flatten(1))
            ->filter(fn (array $record): bool => blank($record['tracking_number'] ?? $record['tracking'] ?? null) || blank($record['label_path'] ?? $record['label_file_path'] ?? $record['path'] ?? null) || $this->looksJsonContainer($record['carrier'] ?? null) || $this->looksJsonContainer($record['shipment_status'] ?? $record['status'] ?? null) || $this->looksJsonContainer($record['service_code'] ?? $record['service'] ?? null))
            ->values()
            ->all();
    }

    private function probeLabels(array &$payload, int $orderId): void
    {
        $this->ensureDiscovery($payload);
        if (! $this->hasExistingCandidateTable($payload)) {
            $payload['labels_probe']['warnings'] = ['No candidate shipment tables found'];
            return;
        }

        $shipmentIds = collect($payload['shipments_probe']['records_for_order'])->flatten(1)->pluck('id')->filter()->unique()->values()->all();
        $labelTables = array_values(array_filter(['shipment_labels', 'shipping_labels', 'labels'], fn (string $table): bool => $this->tableExists($payload, $table)));
        $pathColumns = ['label_path', 'label_file_path', 'path', 'file_path'];

        foreach ($labelTables as $table) {
            $columns = $this->columns($payload, $table);
            $select = $this->safeSelect($columns, $this->relevantColumns());
            $query = DB::table($table)->select($select);
            $hasCondition = false;
            $query->where(function ($where) use ($columns, $orderId, $shipmentIds, &$hasCondition): void {
                if (in_array('order_id', $columns, true) && $orderId > 0) {
                    $where->orWhere('order_id', $orderId);
                    $hasCondition = true;
                }
                if (in_array('shipment_id', $columns, true) && $shipmentIds !== []) {
                    $where->orWhereIn('shipment_id', $shipmentIds);
                    $hasCondition = true;
                }
            });
            if ($hasCondition) {
                $payload['labels_probe']['records_for_order_or_shipment'][$table] = $this->rows($query, $columns);
            }

            $presentPathColumns = array_values(array_intersect($pathColumns, $columns));
            foreach ($presentPathColumns as $pathColumn) {
                $rows = $this->rows(DB::table($table)->select($select)->where(function ($q) use ($pathColumn): void {
                    $q->whereNull($pathColumn)->orWhere($pathColumn, '');
                }), $columns, 20);
                foreach ($rows as $row) {
                    $payload['labels_probe']['empty_paths'][] = ['table' => $table, 'column' => $pathColumn, 'record' => $row];
                }

                foreach (($payload['labels_probe']['records_for_order_or_shipment'][$table] ?? []) as $row) {
                    if (! blank($row[$pathColumn] ?? null)) {
                        $payload['labels_probe']['file_checks'][] = ['table' => $table, 'id' => $row['id'] ?? null, 'column' => $pathColumn, 'path' => $row[$pathColumn], 'exists' => $this->safeFileExists((string) $row[$pathColumn])];
                    }
                }
            }
        }
    }

    private function probeLastExceptions(array &$payload, int $orderId): void
    {
        $tail = $this->logTail();
        $payload['last_exceptions_probe']['admin_shipments'] = $this->findException($tail, '/admin/shipments');
        $payload['last_exceptions_probe']['admin_order'] = $this->findException($tail, '/admin/orders/'.$orderId);
        $payload['last_exceptions_probe']['shipment_diagnose'] = $this->findException($tail, '/admin/tools/shipments/diagnose');
        $payload['last_exceptions_probe']['view_diagnose'] = $this->findException($tail, '/admin/tools/orders/view-diagnose');
        $this->probeIntegrationLogs($payload, $orderId);
    }

    private function probeIntegrationLogs(array &$payload, int $orderId): void
    {
        $this->ensureDiscovery($payload);
        foreach (['marketplace_sync_logs', 'api_integration_logs', 'integration_logs', 'order_logs'] as $table) {
            if (! $this->tableExists($payload, $table)) {
                continue;
            }
            $columns = $this->columns($payload, $table);
            $select = $this->safeSelect($columns, $this->relevantColumns());
            $hasCondition = false;
            $query = DB::table($table)->select($select)->where(function ($where) use ($columns, $orderId, &$hasCondition): void {
                if (in_array('order_id', $columns, true) && $orderId > 0) {
                    $where->orWhere('order_id', $orderId);
                    $hasCondition = true;
                }
                foreach (['integration', 'marketplace', 'carrier'] as $column) {
                    if (in_array($column, $columns, true)) {
                        $where->orWhere($column, 'like', '%dhl%');
                        $hasCondition = true;
                    }
                }
                if (in_array('action', $columns, true)) {
                    foreach (['createShipment', 'getLabels', 'fetchExistingLabel'] as $action) {
                        $where->orWhere('action', 'like', '%'.$action.'%');
                        $hasCondition = true;
                    }
                }
                foreach (['tracking_number', 'tracking', 'external_id', 'external_tracking_number', 'payload', 'metadata', 'meta', 'raw_payload', 'request_payload', 'response_payload'] as $column) {
                    if (in_array($column, $columns, true)) {
                        $where->orWhere($column, 'like', '%'.self::TRACKING.'%');
                        $hasCondition = true;
                    }
                }
            });
            $payload['last_exceptions_probe']['integration_logs'][$table] = $hasCondition ? $this->rows($query, $columns, 30) : [];
        }
    }

    private function guard(array &$payload, string $section, callable $callback): void
    {
        try {
            $callback();
            if (! array_key_exists($section, $payload) || $payload[$section] === null) {
                $payload['diagnostics_health']['sections_failed'][] = $section;
                $payload['errors'][] = [
                    'section' => $section,
                    'message' => 'Section returned null',
                ];
                return;
            }

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
        return (bool) ($payload['table_discovery']['tables'][$table]['exists'] ?? false);
    }

    private function columns(array $payload, string $table): array
    {
        return $payload['table_discovery']['tables'][$table]['columns'] ?? $payload['table_discovery']['columns'][$table] ?? [];
    }

    private function hasExistingCandidateTable(array $payload): bool
    {
        foreach ($payload['table_discovery']['tables'] ?? [] as $table) {
            if (($table['exists'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    private function ensureDiscovery(array &$payload): void
    {
        $missingTableDetails = false;
        foreach ($this->candidateTables() as $table) {
            if (! array_key_exists($table, $payload['table_discovery']['tables'] ?? [])) {
                $missingTableDetails = true;
                break;
            }
        }

        if (($payload['table_discovery']['candidate_tables_checked'] ?? []) === [] || $missingTableDetails) {
            $this->probeTables($payload);
        }
    }

    private function safeFileExists(string $path): ?bool
    {
        try {
            if ($path === '' || str_contains($path, "\0") || preg_match('/^[a-z]+:\/\//i', $path) === 1) {
                return null;
            }
            $candidate = str_starts_with($path, '/') ? $path : storage_path('app/'.$path);
            return file_exists($candidate);
        } catch (Throwable) {
            return null;
        }
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
        $payload['table_discovery']['tables'][$table] = ['exists' => false, 'columns' => [], 'relevant_columns_present' => [], 'error' => null];
        $payload['table_discovery']['columns'][$table] = [];

        try {
            $payload['table_discovery']['tables'][$table]['exists'] = (bool) Schema::hasTable($table);
        } catch (Throwable $e) {
            $payload['table_discovery']['tables'][$table]['exists'] = null;
            $error = $this->errorArray('table_discovery.'.$table.'.has_table', $e);
            $payload['table_discovery']['tables'][$table]['error'] = $error;
            $payload['errors'][] = $error;
            return;
        }

        if (! $payload['table_discovery']['tables'][$table]['exists']) {
            return;
        }

        try {
            $columns = array_values(array_map(fn ($column): string => (string) $column, Schema::getColumnListing($table)));
            $payload['table_discovery']['tables'][$table]['columns'] = $columns;
            $payload['table_discovery']['tables'][$table]['relevant_columns_present'] = array_values(array_intersect($this->relevantColumns(), $columns));
            $payload['table_discovery']['columns'][$table] = $columns;
        } catch (Throwable $e) {
            $error = $this->errorArray('table_discovery.'.$table.'.columns', $e);
            $payload['table_discovery']['tables'][$table]['error'] = $error;
            $payload['errors'][] = $error;
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

    private function safeJsonResponse(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($this->sanitizeValue($payload), $status, [], $this->jsonFlags());
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
