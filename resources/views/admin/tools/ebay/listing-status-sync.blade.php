<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title>eBay listing status sync dry-run</title>
    <style>body{font-family:system-ui,sans-serif;margin:24px}.ok{color:#166534}.error{color:#991b1b}.panel{border:1px solid #ddd;padding:16px;margin:16px 0}label{display:block;margin:8px 0}button{padding:8px 12px;margin-top:6px}pre{background:#f3f4f6;padding:16px;overflow:auto}</style>
</head>
<body>
<div data-marker="{{ $pageMarker }}">
    <h1>eBay listing status sync dry-run</h1>
    @if(session('runner_message'))<div class="ok">{{ session('runner_message') }}</div>@endif
    @if(session('runner_error'))<div class="error">{{ session('runner_error') }}</div>@endif
    <div class="panel">
        <h2>Aktualny status</h2>
        <pre>{{ json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        <p><a href="{{ route('admin.tools.ebay.listing-status-sync.diagnose', ['json' => 1]) }}">Diagnostyka read-only JSON</a></p>
    </div>
    <div class="panel">
        <h2>Akcje runnera</h2>
        <form method="POST" action="{{ route('admin.tools.ebay.listing-status-sync.start') }}" onsubmit="return confirm('Uruchomić eBay listing status dry-run?');">
            @csrf
            <input type="hidden" name="confirm" value="start-ebay-listing-status-sync">
            <input type="hidden" name="scope" value="products_with_ebay_item_id">
            <input type="hidden" name="dry_run" value="1">
            <label>Batch size <input name="batch_size" value="10" type="number" min="1" max="20"></label>
            <label>Delay seconds <input name="delay_seconds" value="5" type="number" min="5"></label>
            <button type="submit">Start dry-run</button>
        </form>
        <form method="POST" action="{{ route('admin.tools.ebay.listing-status-sync.run-next-batch') }}" onsubmit="return confirm('Uruchomić następny batch dry-run?');">
            @csrf
            <input type="hidden" name="confirm" value="run-next-ebay-listing-status-sync-batch">
            <button type="submit">Uruchom następny batch</button>
        </form>
        <form method="POST" action="{{ route('admin.tools.ebay.listing-status-sync.stop') }}" onsubmit="return confirm('Zatrzymać runner?');">
            @csrf
            <input type="hidden" name="confirm" value="stop-ebay-listing-status-sync">
            <button type="submit">Stop</button>
        </form>
    </div>
</div>
</body>
</html>
