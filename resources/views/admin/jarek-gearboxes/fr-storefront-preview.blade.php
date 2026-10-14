<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Skrzynie Jarka — preview gpswiss.fr</title>
    <style>
        body { font: 16px system-ui, sans-serif; margin: 2rem; color: #17212b; background: #f7f8fa; }
        table { border-collapse: collapse; width: 100%; background: white; }
        th, td { padding: .7rem; border: 1px solid #ddd; text-align: left; vertical-align: top; }
        .table { overflow: auto; } pre { white-space: pre-wrap; overflow-wrap: anywhere; }
        .notice { padding: 1rem; background: #fff0d1; } a { color: #154da5; }
    </style>
</head>
<body>
<h1>Skrzynie Jarka — preview gpswiss.fr</h1>
<p>Wyłącznie odczyt. Bez importu, publikacji, zmian cen i stocku. Bez wywołań providerów. Apply nie jest dostępny.</p>
<p class="notice"><strong>blocked</strong> oznacza brak gotowości do apply w obecnym kodzie.
    <strong>data_ready</strong> oznacza kompletność danych sprawdzanych lokalnie, ale nie rozwiązuje widoczności PL,
    stocku ani ceny w checkout. <strong>eligible</strong> wymaga braku wszystkich blockerów.
    Kurs pochodzi wyłącznie z istniejącego cache NBP; preview go nie odświeża.</p>
<nav>
    <a href="{{ request()->fullUrlWithQuery(['format' => 'json']) }}">JSON tej strony</a> ·
    <a href="{{ request()->fullUrlWithQuery(['format' => 'json', 'download' => 1]) }}">Pobierz JSON tej strony</a>
</nav>
<h2>Podsumowanie całego katalogu</h2>
<table><tbody>
@foreach ($preview['summary'] as $name => $count)
    <tr><th>{{ $name }}</th><td>{{ $count }}</td></tr>
@endforeach
</tbody></table>
<h2>Kurs NBP i blokery</h2>
<pre>{{ json_encode(['nbp_rate' => $preview['nbp_rate'] ?? null, 'blocker_counts' => $preview['blocker_counts'] ?? [], 'blockers' => $preview['blockers'] ?? []], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
<h2>Próbka: offset {{ $preview['offset'] ?? 0 }}, limit {{ $preview['limit'] ?? 0 }}</h2>
<p>Qty FR = 1 niezależnie od ilości źródłowej. Dane zdjęć sprawdzane lokalnie, bez HTTP. Historyczna cena eBay nie jest potwierdzeniem bieżącej ceny.</p>
<div class="table"><table>
<thead><tr><th>Produkt</th><th>Cena +50% (+25%, następnie +20%)</th><th>Dostawa FR</th><th>Status</th><th>Pełne dane preview</th></tr></thead>
<tbody>
@forelse ($preview['sample_products'] as $row)
<tr>
    <td>{{ $row['sku'] }}<br>{{ $row['title'] }}<br>Jarek ID: {{ $row['jarek_gearbox_id'] }}</td>
    <td>{{ $row['base_price'] ?? '—' }} {{ $row['base_currency'] }}<br>+50% (+25%, następnie +20%): {{ $row['price_after_markup_pln'] ?? '—' }} PLN<br>{{ $row['price_eur'] ?? '—' }} EUR</td>
    <td>{{ $row['proposed_fr_delivery']['amount_eur'] ?? '—' }} EUR<br>{{ $row['proposed_fr_delivery']['source'] ?? $row['proposed_fr_delivery']['reason'] ?? '—' }}</td>
    <td>{{ $row['status'] }}<br>data_ready: {{ $row['data_ready'] ? 'tak' : 'nie' }}<br>{{ implode(', ', $row['blockers']) }}</td>
    <td><details><summary>Dane i decyzje</summary><pre>{{ json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></details></td>
</tr>
@empty
<tr><td colspan="5">Brak rekordów w tej próbce.</td></tr>
@endforelse
</tbody></table></div>
@if (($preview['next_offset'] ?? 0) < $preview['summary']['total_jarek_gearboxes'])
<p><a href="{{ request()->fullUrlWithQuery(['offset' => $preview['next_offset'], 'download' => 0]) }}">Następna próbka</a></p>
@endif
</body>
</html>
