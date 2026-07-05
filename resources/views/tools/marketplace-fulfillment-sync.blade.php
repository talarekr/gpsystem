<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Marketplace fulfillment/status sync</title><style>body{font-family:system-ui;margin:2rem;max-width:980px}.card{border:1px solid #ddd;border-radius:10px;padding:1rem;margin:1rem 0}label{display:block;margin:.5rem 0}button{padding:.6rem 1rem;margin-right:.5rem}.danger{background:#b91c1c;color:white}.muted{color:#666}pre{background:#111;color:#eee;padding:1rem;overflow:auto}</style></head><body>
<h1>Admin tools: fulfillment/status sync marketplace</h1>
<p class="muted">Dry-run i apply przez przeglądarkę. Wynik pokazuje payload, blokady bezpieczeństwa oraz błędy API. Dostęp: tylko Owner/Admin.</p>
<form method="post">@csrf
<div class="card"><label>ID zamówienia: <input type="number" name="order_id" value="{{ $orderId }}" required></label>
<button type="submit" name="mode" value="dry-run">Sprawdź / dry-run</button>
<label>Potwierdzenie apply: <input name="confirm" value="{{ $confirm }}" placeholder="sync-fulfillment" style="width:220px"></label>
<button class="danger" type="submit" name="mode" value="apply" @disabled(!($result['ok'] ?? false))>Apply fulfillment/status sync</button>
<p class="muted">Apply wymaga tekstu <code>sync-fulfillment</code> i używa <code>MarketplaceOrderFulfillmentSyncService</code>.</p></div>
</form>
@if($result)<div class="card"><h2>Wynik</h2><p><strong>{{ $result['message'] ?? '' }}</strong></p>
@if(!empty($result['guards']))<h3>Blokady</h3><ul>@foreach($result['guards'] as $guard)<li>{{ $guard }}</li>@endforeach</ul>@endif
@if(!empty($result['response']))<h3>Odpowiedź API</h3><pre>{{ json_encode($result['response'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) }}</pre>@endif
<pre>{{ json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) }}</pre></div>@endif
</body></html>
