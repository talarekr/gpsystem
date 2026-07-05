<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Marketplace orders purge</title><style>body{font-family:system-ui;margin:2rem;max-width:980px}.card{border:1px solid #ddd;border-radius:10px;padding:1rem;margin:1rem 0}label{display:block;margin:.5rem 0}button{padding:.6rem 1rem;margin-right:.5rem}.danger{background:#b91c1c;color:white}.muted{color:#666}pre{background:#111;color:#eee;padding:1rem;overflow:auto}.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:.75rem}.metric{background:#f8fafc;padding:.75rem;border-radius:8px}</style></head><body>
<h1>Admin tools: czyszczenie zamówień marketplace</h1>
<p class="muted">Narzędzie używa tego samego serwisu co komenda <code>marketplace:orders:purge</code>. Dostęp: tylko Owner/Admin.</p>
<form method="post">@csrf
<div class="card"><h2>Zakres</h2>
@foreach(['allegro','ebay','ovoko'] as $marketplace)
<label><input type="checkbox" name="marketplaces[]" value="{{ $marketplace }}" @checked(in_array($marketplace, $selectedMarketplaces ?? [], true))> {{ $marketplace }}</label>
@endforeach
<label><input type="checkbox" name="only_test_import" value="1" @checked($onlyTestImport)> only-test-import</label>
</div>
<div class="card"><h2>Akcje</h2>
<button type="submit" name="mode" value="dry-run">Sprawdź / dry-run</button>
<label>Potwierdzenie dla usunięcia: <input name="confirm" value="{{ $confirm }}" placeholder="purge-marketplace-orders" style="width:280px"></label>
<button class="danger" type="submit" name="mode" value="apply" @disabled(!($result['ok'] ?? false))>Usuń</button>
<p class="muted">Przycisk „Usuń” jest aktywny dopiero po poprawnym dry-run w tej sesji widoku i nadal wymaga tekstu <code>purge-marketplace-orders</code>.</p>
</div></form>
@if($result)
<div class="card"><h2>Wynik</h2><p><strong>{{ $result['message'] ?? '' }}</strong></p>
@if(isset($result['summary']))<div class="grid">
@foreach(['orders'=>'orders zostanie usuniętych','order_items'=>'order_items','shipments_detached'=>'shipments odpiętych','marketplace_sync_logs_detached'=>'marketplace_sync_logs odpiętych'] as $key=>$label)
<div class="metric"><strong>{{ $result['summary'][$key] ?? 0 }}</strong><br>{{ $label }}</div>
@endforeach</div>@endif
@if(isset($result['export_path']))<p>Backup/export JSON: <code>{{ $result['export_path'] }}</code></p>@endif
<pre>{{ json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) }}</pre></div>
@endif
</body></html>
