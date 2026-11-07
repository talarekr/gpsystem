# Zdjęcia w osobnym archiwum z serwera

W panelu eksportu zatrzymaj lokalną pętlę. Zaznacz „Zdjęcia dostarczę osobno”,
kliknij „Nie kopiuj zdjęć — dostarczę osobny ZIP”, a następnie „Wznów istniejący
eksport”. Wybór dotyczy tego runu i pozostaje po odświeżeniu. Można go dokonać
podczas snapshotu, kontroli relacji, przygotowania metadanych zdjęć lub samego
kopiowania; po rozpoczęciu pakowania danych zmiana jest zablokowana.

Zachowujemy run, attempt, granice snapshotu, operator ACCEPT i wszystkie zapisane
dane. Trwałe pliki częściowego kopiowania nie są usuwane; w tym trybie nie są
dołączane do archiwów. Nie wznawiamy kopiowania ani retry zdjęć. Eksport nadal
sprawdza zapisane relacje, tworzy metadane zdjęć i archiwa danych pełne/próbne.
Nie uruchamia importu, publikowania, synchronizacji ani zmian w bazie.

## Jak przygotować ZIP

`photo-manifest.csv` / `photo-manifest.jsonl` zawierają lokalne ID części lub auta,
ID zdjęcia, rolę, oryginalną nazwę, kolejność i zdjęcie główne.
`external_relative_path` jest ścieżką względem **zawartości publicznego storage**.
Przykład: dla `parts/photos/abc.jpg` plik w osobnym ZIP ma mieć ścieżkę
`parts/photos/abc.jpg`, bez przemianowania. Zachowaj podkatalogi, w tym zdjęcia
samochodów i zdjęcia kodów części; pełną listę wyznacza manifest. Jeśli narzędzie
hostingu dodaje nadrzędny katalog `storage/`, po rozpakowaniu wskaż jego zawartość
jako katalog główny zdjęć.

Spakuj odpowiednie oryginały z publicznego storage przez menedżer plików hostingu.
Nie pakuj całego projektu, `.env`, logów, prywatnych dokumentów lub konfiguracji.
Nie zastępuj brakujących oryginałów miniaturami. Gdy hosting ma kilka fizycznych
katalogów storage, uwzględnij te, które zawierają ścieżki z manifestu; sprzecznych
kopii nie nadpisuj automatycznie. Zapisz datę wykonania ZIP. GPSwiss zachowuje swoje
oryginały jako niezależną kopię. Archiwa przekazuj prywatnie.

## Jawne ograniczenia

W trybie `external_archive` pliki zdjęć **nie są odczytywane, kopiowane, hashowane
ani sprawdzane pod kątem istnienia** przez eksporter. Manifest oznacza je
`EXTERNAL_ARCHIVE_NOT_VERIFIED` / `export_status=external`; `package_path`,
`bytes`, `sha256` i `mime` są NULL. `external_path_status` opisuje wyłącznie
poprawność referencji, nie dostępność pliku. Błędna ścieżka lub zdalny URL wymaga
osobnego wyjaśnienia; eksporter nie pobiera URL-i.

`missing-originals.jsonl` nadal służy do potwierdzonych problemów kopiowania w
trybie zwykłym. Pusty raport w trybie zewnętrznym **nie dowodzi**, że oryginały
istnieją. Finalny manifest zawsze ma `originals_complete=false` w tym trybie,
nawet jeśli nie ma żadnych referencji do zdjęć. Gotowość paczki technicznej
oznacza wyłącznie gotowe dane i metadane; ZIP z oryginałami trzeba dostarczyć i
zweryfikować osobno (ścieżki, liczba plików, dostępność, rozmiary i SHA-256).

Dane pochodzą z istniejącego snapshotu, a ZIP z późniejszej kopii filesystemu.
Nie jest to wspólny snapshot punktowy: zdjęcia dodane, zmienione lub usunięte
pomiędzy tymi operacjami trzeba wykryć podczas weryfikacji po stronie docelowej.
Powiązania w próbce i pełnym eksporcie mają ten sam kontrakt ścieżek.
