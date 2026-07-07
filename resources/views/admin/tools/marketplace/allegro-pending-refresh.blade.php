<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Odśwież pending Allegro</title>
    <style>
        body{font-family:Inter,ui-sans-serif,system-ui,Arial,sans-serif;background:#f8fafc;color:#0f172a;margin:0;padding:24px}.wrap{max-width:1180px;margin:auto}.card{background:white;border:1px solid #e2e8f0;border-radius:14px;padding:18px;margin:0 0 16px;box-shadow:0 1px 2px #0000000d}.muted{color:#64748b}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}.btn{border:0;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer;background:#2563eb;color:white}input{border:1px solid #cbd5e1;border-radius:10px;padding:10px;font:inherit}table{width:100%;border-collapse:collapse;margin-top:10px}td,th{border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top;padding:8px;font-size:13px}pre{white-space:pre-wrap;word-break:break-word;background:#0f172a;color:#e2e8f0;border-radius:12px;padding:12px;max-height:520px;overflow:auto}.safe{background:#ecfdf5;border-color:#bbf7d0}.warn{background:#fef3c7;border-color:#fde68a}
    </style>
</head>
<body>
<div class="wrap">
    <h1>Odśwież pending Allegro</h1>
    <div class="card safe">
        <p><b>GET jest read-only preview.</b> POST + CSRF + <span class="mono">apply=1</span> odświeża tylko lokalne pola status/last_api_status/last_error listingów Allegro.</p>
        <p class="muted">Narzędzie nie publikuje, nie kończy ofert, nie usuwa linków i nie zmienia statusu części. Kandydaci: <span class="mono">marketplace=allegro</span>, <span class="mono">status=publication_pending</span>, <span class="mono">external_offer_id</span> niepusty.</p>
    </div>
    <div class="card">
        <form method="get" action="{{ route('admin.tools.marketplace.allegro-diagnose.refresh-pending') }}">
            <label>Starsze niż minuty <input name="older_than_minutes" type="number" min="0" value="{{ $filters['older_than_minutes'] }}"></label>
            <label>Limit <input name="limit" type="number" min="1" max="100" value="{{ $filters['limit'] }}"></label>
            <button class="btn" type="submit">Preview</button>
            <a href="{{ route('admin.tools.marketplace.allegro-diagnose') }}">Wróć do diagnose</a>
        </form>
        <form method="post" action="{{ route('admin.tools.marketplace.allegro-diagnose.refresh-pending') }}" style="margin-top:12px">
            @csrf
            <input type="hidden" name="apply" value="1">
            <input type="hidden" name="older_than_minutes" value="{{ $filters['older_than_minutes'] }}">
            <input type="hidden" name="limit" value="{{ $filters['limit'] }}">
            <button class="btn" type="submit" style="background:#16a34a">POST apply: Odśwież pending Allegro</button>
        </form>
    </div>
    <div class="card {{ $mode === 'apply' ? 'warn' : '' }}">
        <h2>{{ $mode === 'apply' ? 'Wynik apply' : 'Preview' }} ({{ $count }})</h2>
        <table>
            <thead><tr><th>listing_id</th><th>part_id</th><th>offer_id</th><th>status</th><th>last_api_status</th><th>updated_at</th></tr></thead>
            <tbody>
            @forelse($rows as $row)
                <tr>
                    <td class="mono">{{ $row['listing_id'] }}</td>
                    <td class="mono">{{ $row['part_id'] }}</td>
                    <td class="mono">{{ $row['offer_id'] }}</td>
                    <td class="mono">{{ $row['status'] }}</td>
                    <td class="mono">{{ $row['last_api_status'] ?? 'null' }}</td>
                    <td class="mono">{{ $row['updated_at'] }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">Brak kandydatów.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($applied)
        <div class="card"><h2>Applied JSON</h2><pre>{{ json_encode($applied, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></div>
    @endif
</div>
</body>
</html>
