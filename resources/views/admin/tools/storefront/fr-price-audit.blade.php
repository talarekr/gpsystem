<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>GPSwiss FR vs eBay.de — raport cen</title><style>body{font:15px/1.5 system-ui;margin:24px;color:#18283f}table{border-collapse:collapse;width:100%}th,td{text-align:left;padding:8px;border-bottom:1px solid #ddd}pre{white-space:pre-wrap;overflow-wrap:anywhere;background:#f3f5f8;padding:14px}.table{overflow:auto}a,button{margin-right:12px}</style></head><body>
<h1>GPSwiss.fr vs eBay.de — lokalny raport cen</h1><p><a href="{{ route('admin.tools.storefront.fr-translations.index') }}">← Narzędzia FR</a></p>
<p>Read-only: lokalne Part i marketplace_listings, bez eBay API, zapisów ani odświeżania NBP. Jarek jest wyłączony z tego raportu. Dostawa jest osobno, bez zmiany mapowania FR.</p>
<p><a href="{{ url()->current() }}?run=1">Uruchom raport globalny + dwa zgłoszone produkty</a><a href="{{ url()->current() }}?format=csv">Pobierz CSV wszystkich widocznych produktów</a><a href="{{ url()->current() }}?format=json">Raport JSON</a></p>
@if($report)
<h2>Podsumowanie</h2><pre>{{ json_encode($report['summary'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre>
<p>Źródło EUR: jednoznaczna aktywna lokalna cena eBay.de → Part EUR → PLN/NBP. Konflikt aktywnych cen DE blokuje wycenę. Cena z eBay FR nie jest źródłem ceny produktu; dostawa nadal korzysta z mapowania FR. parts.ebay_price to PLN, nie gotowe EUR.</p>
<h2>Dwa zgłoszone URL-e</h2><pre>{{ json_encode($report['examples'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) }}</pre>
<h2>Top 50 różnic bieżącej polityki</h2><pre>{{ json_encode($report['top_50_differences'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre>
<h2>Top 50 różnic wcześniejszej polityki FR</h2><pre>{{ json_encode($report['top_50_previous_policy_differences'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre>
<h2>Granice raportu</h2><pre>{{ json_encode(['nbp_cache'=>$report['nbp_cache'],'limitations'=>$report['limitations'],'generated_at'=>$report['generated_at']], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre>
@endif
</body></html>
