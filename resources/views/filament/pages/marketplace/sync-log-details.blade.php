@php
    $payload = $log->payload ?? [];
    $requestSummary = $payload['request_summary'] ?? $payload['request'] ?? null;
    $responseSummary = $payload['response_summary'] ?? null;
    $errorSummary = $payload['error_summary'] ?? $payload['error'] ?? null;

    if ($responseSummary === null && array_key_exists('response', $payload)) {
        $responseSummary = $payload['response'];
    }

    $orderStatusMarkers = [
        'order_status_sync_code_version' => data_get($payload, 'order_status_sync_code_version') ?? data_get($payload, 'meta.order_status_sync_code_version') ?? data_get($requestSummary, 'order_status_sync_code_version'),
        'sync_writer' => data_get($payload, 'sync_writer') ?? data_get($payload, 'meta.sync_writer') ?? data_get($requestSummary, 'sync_writer'),
        'local_status_raw_value' => data_get($payload, 'local_status_raw_value') ?? data_get($requestSummary, 'local_status_raw_value'),
        'normalized_marketplace' => data_get($payload, 'normalized_marketplace') ?? data_get($requestSummary, 'normalized_marketplace'),
        'target_marketplace_status' => data_get($payload, 'target_marketplace_status') ?? data_get($responseSummary, 'target_marketplace_status') ?? data_get($requestSummary, 'target_marketplace_status'),
        'mapper_branch' => data_get($payload, 'mapper_branch') ?? data_get($requestSummary, 'mapper_branch'),
    ];
@endphp
<div class="space-y-4 text-sm">
    <div class="grid gap-3 md:grid-cols-2">
        <div><strong>Integracja:</strong> {{ $log->marketplace }}</div>
        <div><strong>Akcja:</strong> {{ $log->action }}</div>
        <div><strong>Status:</strong> {{ $log->status }}</div>
        <div><strong>Kod/status:</strong> {{ $log->http_status ?: '—' }}</div>
        <div><strong>Czas:</strong> {{ $log->created_at }}</div>
        <div><strong>Czas wykonania:</strong> {{ $log->duration_ms ? $log->duration_ms.' ms' : '—' }}</div>
        <div><strong>Request/correlation ID:</strong> {{ $log->request_id ?: '—' }}</div>
        <div><strong>Tracking/external ID:</strong> {{ $log->tracking_number ?: ($log->external_id ?: '—') }}</div>
    </div>
    <div><strong>Komunikat:</strong> {{ $log->message ?: '—' }}</div>
    <div class="grid gap-3 md:grid-cols-2">
        <div><strong>Zamówienie:</strong> {{ $log->order_id ? '#'.$log->order_id : '—' }}</div>
        <div><strong>Przesyłka:</strong> {{ $log->shipment_id ? '#'.$log->shipment_id : '—' }}</div>
        <div><strong>Listing:</strong> {{ $log->marketplace_listing_id ? '#'.$log->marketplace_listing_id : '—' }}</div>
        <div><strong>Część:</strong> {{ $log->part_id ? '#'.$log->part_id : '—' }}</div>
    </div>
    @if ($log->action === \App\Services\Marketplace\OrderStatusMarketplaceSyncService::ACTION)
        <div>
            <strong>Order status sync markers</strong>
            <pre class="mt-2 max-h-80 overflow-auto rounded-lg bg-gray-950 p-3 text-xs text-gray-100">{{ json_encode($orderStatusMarkers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        </div>
    @endif
    <div>
        <strong>Sanitized request summary</strong>
        <pre class="mt-2 max-h-80 overflow-auto rounded-lg bg-gray-950 p-3 text-xs text-gray-100">{{ json_encode($requestSummary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
    </div>
    <div>
        <strong>Sanitized response/error summary</strong>
        <pre class="mt-2 max-h-80 overflow-auto rounded-lg bg-gray-950 p-3 text-xs text-gray-100">{{ json_encode(['response' => $responseSummary, 'error' => $errorSummary], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
    </div>
    <div>
        <strong>Full sanitized raw payload</strong>
        <pre class="mt-2 max-h-96 overflow-auto rounded-lg bg-gray-950 p-3 text-xs text-gray-100">{{ json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
    </div>
</div>
