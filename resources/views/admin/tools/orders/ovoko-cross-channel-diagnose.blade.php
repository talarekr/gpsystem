<!doctype html>
<html lang="pl">
<head><meta charset="utf-8"><title>Ovoko cross-channel diagnose</title><style>body{font-family:Arial,sans-serif;margin:24px;color:#172033}.safe{background:#ecfdf5;border:1px solid #10b981;padding:12px;border-radius:8px}.warn{background:#fff7ed;border:1px solid #fb923c;padding:12px;border-radius:8px}pre{white-space:pre-wrap;background:#0f172a;color:#e2e8f0;padding:16px;border-radius:8px;overflow:auto}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.card{border:1px solid #d6dae1;border-radius:8px;padding:12px}.k{font-size:12px;color:#64748b}.v{font-size:20px;font-weight:700}.yes{color:#047857}.no{color:#b91c1c}</style></head>
<body>
<h1>Ovoko cross-channel diagnose</h1>
<p class="safe">Read-only: to narzędzie nie kończy aukcji, nie usuwa linków, nie relistuje eBay, nie zmienia Ovoko i nie zapisuje zmian w częściach.</p>
<form method="get"><label>part_id <input name="part_id" value="{{ request('part_id') }}"></label> <button type="submit">Diagnozuj</button> <a href="{{ request()->fullUrlWithQuery(['format' => 'json']) }}">JSON</a></form>
@if(isset($payload['summary']))
    <h2>Podsumowanie</h2>
    <div class="grid">
    @foreach($payload['summary'] as $key => $value)
        <div class="card"><div class="k">{{ $key }}</div><div class="v {{ is_bool($value) ? ($value ? 'yes' : 'no') : '' }}">{{ is_bool($value) ? ($value ? 'true' : 'false') : ($value ?? '—') }}</div></div>
    @endforeach
    </div>
@endif
<h2>Pełny raport</h2>
<pre>{{ json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
</body>
</html>
