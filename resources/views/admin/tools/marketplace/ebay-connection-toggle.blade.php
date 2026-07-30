<!doctype html>
<html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>eBay connection toggle</title>
<style>body{font-family:system-ui;background:#f3f4f6;color:#111827;margin:0}.card{max-width:760px;margin:48px auto;background:white;padding:32px;border-radius:14px;box-shadow:0 4px 20px #0001}.status{padding:16px;border-radius:8px;font-size:20px;font-weight:700}.on{background:#dcfce7;color:#166534}.off{background:#fee2e2;color:#991b1b}.notice{background:#eff6ff;padding:14px;border-left:4px solid #2563eb}.flash{padding:12px;background:#fef3c7;margin-bottom:16px}button{border:0;border-radius:7px;padding:12px 18px;font-weight:700;cursor:pointer}.disable{background:#b91c1c;color:white}.enable{background:#15803d;color:white}li{margin:7px 0}small{color:#4b5563}</style></head>
<body><main class="card"><h1>Przełącznik połączenia eBay</h1>
@if(session('success') || session('error'))<div class="flash">{{ session('success') ?: session('error') }}</div>@endif
<div class="status {{ $enabled ? 'on' : 'off' }}">eBay jest {{ $enabled ? 'WŁĄCZONY' : 'WYŁĄCZONY' }}</div>
<p><small>Ostatnia zmiana: {{ $setting?->updated_at?->format('Y-m-d H:i:s') ?? 'brak — wartość domyślna' }} · Operator: {{ $setting?->updatedBy?->email ?? 'brak' }}</small></p>
<div class="notice"><strong>Tokeny, OAuth i konfiguracja .env nie są usuwane ani zmieniane.</strong> Przełącznik blokuje wyłącznie aplikacyjne połączenie eBay.</div>
<h2>Skutki wyłączenia</h2><ul>@foreach($effects as $effect)<li>{{ $effect }}</li>@endforeach</ul>
<p>Lokalny preview bez requestu do API nadal może działać. Pozostałe marketplace oraz DHL i PayU pozostają bez zmian.</p>
<form method="post" action="{{ route('admin.tools.marketplace.ebay-connection-toggle.update') }}" onsubmit="return confirm('Czy na pewno zmienić stan integracji eBay?')">@csrf
@if($enabled)<input type="hidden" name="confirm" value="disable-ebay"><button class="disable">Wyłącz eBay</button>@else<input type="hidden" name="confirm" value="enable-ebay"><button class="enable">Włącz eBay</button>@endif
</form></main></body></html>
