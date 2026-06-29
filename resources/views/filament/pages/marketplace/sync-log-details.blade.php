@php
    $payload = $log->payload ?? [];
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
    <div>
        <strong>Sanitized request summary</strong>
        <pre class="mt-2 max-h-80 overflow-auto rounded-lg bg-gray-950 p-3 text-xs text-gray-100">{{ json_encode($payload['request'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
    </div>
    <div>
        <strong>Sanitized response/error summary</strong>
        <pre class="mt-2 max-h-80 overflow-auto rounded-lg bg-gray-950 p-3 text-xs text-gray-100">{{ json_encode(['response' => $payload['response'] ?? null, 'error' => $payload['error'] ?? null], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
    </div>
</div>
