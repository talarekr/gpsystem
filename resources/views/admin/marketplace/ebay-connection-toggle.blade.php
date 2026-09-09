<!doctype html>
<html lang="pl">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>eBay write connection</title><style>body{font:16px system-ui;margin:2rem;max-width:850px;line-height:1.5}.ok{color:#176b2c}.off{color:#9b1c1c}.box{border:1px solid #bbb;padding:1rem;margin:1rem 0}input,button{padding:.6rem}code{background:#eee;padding:.15rem}</style></head>
<body>
<h1>Global eBay write connection</h1>
@if (session('status')) <p class="ok"><strong>{{ session('status') }}</strong></p> @endif
<div class="box">
    <p>Status: <strong class="{{ $status['write_enabled'] ? 'ok' : 'off' }}">{{ $status['write_enabled'] ? 'ENABLED' : 'DISABLED' }}</strong></p>
    <p>Konto <code>ebay_de</code>: {{ $status['account_configured'] ? 'skonfigurowane' : 'brak' }}; API: {{ $status['account_api_enabled'] ? 'enabled' : 'disabled' }}; źródło ustawienia: <code>{{ $status['setting_source'] }}</code>.</p>
</div>
<p>Ta strona zmienia wyłącznie lokalny globalny bezpiecznik zapisu. Nie łączy się z eBay i nie uruchamia synchronizacji, publikacji ani zmiany ofert, cen lub stocku.</p>
@if ($status['account_configured'])
<form method="post" action="{{ route('admin.tools.marketplace.ebay-connection-toggle.update') }}" class="box">
    @csrf
    <input type="hidden" name="enabled" value="{{ $status['write_enabled'] ? '0' : '1' }}">
    <label>Wpisz <code>{{ $status['write_enabled'] ? 'DISABLE_EBAY_WRITE_CONNECTION' : 'ENABLE_EBAY_WRITE_CONNECTION' }}</code>:<br>
        <input name="confirm" autocomplete="off" size="40" required>
    </label>
    <button type="submit">{{ $status['write_enabled'] ? 'Wyłącz' : 'Włącz' }} eBay write connection</button>
</form>
@endif
<p><a href="{{ route('admin.tools.jarek-gearboxes.ebay-bulk-price-increase-runner') }}">Wróć do Jarek Gearboxes eBay canary runner</a></p>
</body></html>
