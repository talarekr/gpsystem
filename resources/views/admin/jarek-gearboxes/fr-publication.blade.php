<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Publikacja Jarka — GPSwiss FR</title><style>body{font:16px system-ui;margin:2rem;max-width:1200px}table{border-collapse:collapse;width:100%}td,th{border:1px solid #ddd;padding:.5rem;text-align:left}pre{white-space:pre-wrap}input,button{padding:.7rem;margin:.5rem 0}</style></head><body>
<h1>Skrzynie Jarka — publikacja wyłącznie gpswiss.fr</h1>
<p>Osobna ekspozycja FR, bez Part, kopiowania cen, zmiany stocku źródłowego i marketplace. Qty FR=1, dostawa 130 EUR, cena bazowa PLN +25% → EUR przez NBP. Brak tłumaczenia używa źródła. Tłumaczenia uruchamiaj osobno.</p>
@if($errors->any())<p role="alert">{{ implode(' ', $errors->all()) }}</p>@endif
<form method="post">@csrf<input type="hidden" name="action" value="dry-run"><button type="submit">1. Dry-run</button></form>
@if($report)
<h2>Raport {{ $report['report_id'] }}</h2><p>Ważny przez godzinę. Sprawdź kwoty i blokery przed apply. Zmiana źródeł/kursu wymaga nowego raportu.</p>
<a href="{{ request()->url() }}?report_id={{ $report['report_id'] }}">Zachowaj link do raportu</a>
<table>@foreach($report['summary'] as $key=>$value)<tr><th>{{ $key }}</th><td>{{ $value }}</td></tr>@endforeach</table>
<pre>{{ json_encode($report['nbp_rate'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
<details><summary>Wszystkie produkty i blokery</summary><table><tr><th>ID / SKU</th><th>Tytuł</th><th>Baza PLN</th><th>PLN +25%</th><th>EUR</th><th>Dostawa EUR</th><th>Fallback</th><th>Blokery</th></tr>@foreach($report['products'] as $p)<tr><td>{{ $p['jarek_gearbox_id'] }} / {{ $p['sku'] }}</td><td>{{ $p['title'] }}</td><td>{{ $p['price_source_pln'] ?? '—' }}</td><td>{{ $p['price_after_markup_pln'] ?? '—' }}</td><td>{{ $p['price_eur'] ?? '—' }}</td><td>130</td><td>{{ $p['uses_fallback_translation'] ? 'tak' : 'nie' }}</td><td>{{ implode(', ', $p['blockers']) }}</td></tr>@endforeach</table></details>
@if(!($report['apply_report'] ?? null))
<form method="post">@csrf<input type="hidden" name="action" value="apply"><input type="hidden" name="report_id" value="{{ $report['report_id'] }}"><label>2. Wpisz PUBLISH-JAREK-GEARBOXES-GPSWISS-FR<br><input name="confirm" autocomplete="off" required></label><br><button type="submit">3. Apply publikacji FR</button></form>
@endif
@endif
@if($result || ($report['apply_report'] ?? null))<h2>Wynik apply</h2><pre>{{ json_encode($result ?? $report['apply_report'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>@endif
<p>Po wdrożeniu i publikacji przejdź do <a href="{{ url('/admin/tools/storefront/fr-translations') }}">tłumaczeń FR → Skrzynie Jarka</a>. Apply nie uruchamia Google.</p>
</body></html>
