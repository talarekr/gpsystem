<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>GPSwiss FR — diagnostyka kategorii</title><style>body{font:15px/1.5 system-ui;margin:24px;color:#18283f}table{border-collapse:collapse;width:100%}th,td{text-align:left;vertical-align:top;padding:10px;border-bottom:1px solid #ddd}code{overflow-wrap:anywhere}input{padding:8px;width:350px;max-width:100%}.table{overflow:auto}</style></head><body>
<h1>GPSwiss FR — diagnostyka kategorii</h1>
<p>Odczyt bieżącej bazy, bez Google API i zapisów. Gotowa nazwa FR wymaga statusu translated/reviewed oraz niepustego pola name. Pozostałe przypadki pokazują fallback źródłowy. Sprawdź pełną ścieżkę i ID — nazwy mogą się powtarzać.</p>
<p><a href="{{ route('admin.tools.storefront.fr-translations.index') }}">← Tłumaczenia katalogu: Produkty i kategorie GPSwiss.fr</a></p>
@if(!$schemaReady)<p role="alert">Brak tabeli category_translations.</p>@endif
<form method="get"><label>Nazwa lub ścieżka <input name="q" value="{{ $q }}"></label><button type="submit">Szukaj</button><a href="{{ url()->current() }}">Wszystkie kategorie</a></form>
<p>{{ $categories->total() }} kategorii pasuje do wyszukiwania. Dane nie pochodzą z cache drzewa.</p>
<div class="table"><table><thead><tr><th>ID / parent / source / widoczność</th><th>Źródłowa nazwa i ścieżka</th><th>Tłumaczenie FR</th><th>Status i wynik odczytu</th></tr></thead><tbody>
@foreach($rows as $row)
<tr><td>{{ $row['category']->id }} / {{ $row['category']->parent_id ?? '—' }} / {{ $row['category']->source_system ?? '—' }} / {{ $row['category']->is_visible ? 'widoczna' : 'ukryta' }}</td><td>{{ $row['category']->name }}<br><code>{{ $row['category']->full_slug_path }}</code></td><td>{{ $row['translation']?->name ?? '—' }}<br>Opis FR gotowy: {{ $row['description_ready'] ? 'tak' : 'nie' }}</td><td>{{ $row['translation']?->status ?? '—' }}<br><code>{{ $row['reason'] }}</code><br>Nazwa publiczna FR: {{ $row['public_name'] }}</td></tr>
@endforeach
</tbody></table></div>
{{ $categories->links() }}
<p>missing_translation / nonpublic_status / empty_fr_name oznacza brak gotowej nazwy FR. W istniejącym narzędziu użyj preview sekcji Produkty i kategorie GPSwiss.fr. Sprawdź liczby i koszt przed osobnym zatwierdzonym startem. Rekordy reviewed są chronione; pustą nazwę reviewed trzeba poprawić w procesie ręcznej weryfikacji, nie uruchamiać masowego nadpisania.</p>
</body></html>
