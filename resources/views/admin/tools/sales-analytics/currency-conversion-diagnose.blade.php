<!doctype html>
<html lang="pl">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Diagnostyka przewalutowania sprzedaży</title>
<style>body{font:14px system-ui;margin:2rem;color:#172033}table{border-collapse:collapse;width:100%;margin-top:1.5rem}th,td{padding:.55rem;border:1px solid #d8dee9;text-align:left}th{background:#f1f5f9}.ok{color:#166534}.bad{color:#b91c1c}form{display:flex;gap:1rem;align-items:end;flex-wrap:wrap}label{display:grid;gap:.25rem}input{padding:.45rem}.notice{padding:1rem;background:#fff7ed;border:1px solid #fdba74;margin:1rem 0}</style></head>
<body>
<h1>Diagnostyka przewalutowania analityki sprzedaży</h1>
<p>Raport tylko do odczytu. Źródło kwoty analitycznej: <code>orders.total</code>; data kursu: <code>ordered_at</code>, a gdy jej brak — <code>created_at</code>. Nie wykonuje synchronizacji ani zapisu do marketplace.</p>
<form method="get">
    <label>ID / numer zamówienia<input name="order_id" value="{{ $filters['order_id'] ?? '' }}"></label>
    <label>Waluta<input name="currency" maxlength="3" value="{{ $filters['currency'] ?? '' }}"></label>
    <label>Data<input type="date" name="date" value="{{ $filters['date'] ?? '' }}"></label>
    <button type="submit">Filtruj</button>
</form>
@if ($potentialOneToOneCount)
<div class="notice" role="alert"><strong>Blocker:</strong> {{ $potentialOneToOneCount }} zamówień walutowych nie ma przeliczenia i jest pomijanych w agregacji PLN (nigdy 1:1).</div>
@endif
<p>Wyniki: {{ $rows->count() }} · bez kursu: {{ $unconvertedCount }}</p>
<table><thead><tr><th>Zamówienie / data</th><th>Waluta obca?</th><th>Kwota źródłowa</th><th>Kurs NBP / jednostka</th><th>Data / tabela</th><th>Kwota PLN analityki</th><th>Status</th></tr></thead><tbody>
@forelse ($rows as $row)
<tr>
    <td>#{{ $row['order']->id }} · {{ $row['order']->order_number }}<br>{{ optional($row['order']->ordered_at ?: $row['order']->created_at)->format('Y-m-d H:i') }}</td>
    <td>{{ $row['is_foreign_currency'] ? 'tak' : 'nie' }}</td>
    <td>{{ number_format((float) $row['original_amount'], 2, ',', ' ') }} {{ $row['original_currency'] }}<br><small>{{ $row['analytics_amount_source'] }}</small></td>
    <td>@if ($row['exchange_rate'] !== null){{ $row['exchange_rate'] }} PLN / {{ $row['exchange_rate_unit'] }} {{ $row['original_currency'] }}@else—@endif</td>
    <td>{{ $row['exchange_rate_effective_date'] ?? '—' }}<br>{{ $row['exchange_rate_table_no'] ?? '—' }}</td>
    <td>{{ $row['converted_amount_pln'] === null ? 'POMINIĘTE' : number_format($row['converted_amount_pln'], 2, ',', ' ').' PLN' }}</td>
    <td class="{{ $row['conversion_status'] === 'unconverted' ? 'bad' : 'ok' }}">{{ $row['conversion_status'] }}<br><small>{{ $row['conversion_source'] }} {{ $row['warning'] }}</small></td>
</tr>
@empty<tr><td colspan="7">Brak zamówień dla podanych filtrów.</td></tr>@endforelse
</tbody></table>
</body></html>
