<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ovoko listing diagnose</title>
    <style>
        body{font-family:Inter,ui-sans-serif,system-ui,Arial,sans-serif;background:#f8fafc;color:#0f172a;margin:0;padding:24px}.wrap{max-width:1280px;margin:auto}.card{background:white;border:1px solid #e2e8f0;border-radius:14px;padding:18px;margin:0 0 16px;box-shadow:0 1px 2px #0000000d}.muted{color:#64748b}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}input{border:1px solid #cbd5e1;border-radius:10px;padding:10px;font:inherit}.btn{border:0;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer;background:#2563eb;color:white}.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.badge{display:inline-block;border-radius:999px;padding:3px 9px;background:#dcfce7;color:#166534;font-weight:700}pre{white-space:pre-wrap;word-break:break-word;background:#0f172a;color:#e2e8f0;border-radius:12px;padding:12px;max-height:720px;overflow:auto}code{background:#e2e8f0;border-radius:6px;padding:2px 5px}
    </style>
</head>
<body>
<div class="wrap">
    <h1>Ovoko listing diagnose</h1>
    <div class="card">
        <p><b>Read-only:</b> narzędzie nie publikuje, nie aktualizuje, nie usuwa i nie relinkuje ofert. GET służy tylko do diagnostyki.</p>
        <form method="get" action="{{ route('admin.tools.ovoko.listing-diagnose') }}">
            <label for="part_id"><b>part_id / ID produktu lokalnego</b></label>
            <div class="row" style="margin-top:10px">
                <input id="part_id" name="part_id" value="{{ $inputPartId }}" type="number" min="1" placeholder="np. 7498">
                <button class="btn" type="submit">Diagnozuj</button>
                @if($inputPartId !== '')
                    <a href="{{ route('admin.tools.ovoko.listing-diagnose', ['part_id' => $inputPartId, 'json' => 1]) }}" target="_blank" rel="noopener">Pokaż jako JSON</a>
                @endif
            </div>
        </form>
    </div>

    @if($diagnostics === null)
        <div class="card muted">Wpisz lokalny <b>part_id</b> i kliknij „Diagnozuj”. Przykład: <code>/admin/tools/ovoko/listing-diagnose?part_id=7498</code>.</div>
    @else
        <div class="card">
            <div class="row" style="justify-content:space-between">
                <h2>Diagnostyka dla part_id={{ $partId }}</h2>
                <span class="badge">GET read-only</span>
            </div>
            <pre>{{ json_encode($diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        </div>
    @endif
</div>
</body>
</html>
