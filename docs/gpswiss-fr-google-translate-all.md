# Etap 2B — cały katalog PL → FR

Komenda korzysta z istniejącego `GoogleTranslateService`. Zapisuje wyłącznie
`part_translations` i `category_translations`, dla `locale=fr` i
`provider=google_translate`. Pola źródłowe PL, SKU, slugi, ceny i stan magazynowy
pozostają bez zmian. Nie uruchamia procesów marketplace.

## Zakres i wybór rekordów

`--type=all` obejmuje wszystkie produkty i kategorie w bazie, także niewidoczne,
bez rekordów usuniętych przez soft delete. To szerszy zakres niż dotychczasowa
komenda `storefront:fr-translations-preview`, która liczy katalog widoczny.
Tłumaczone pola są zgodne z `translationSourceFields()` Etapu 2A:

- produkt: `name`, `short_description`, `description`, `condition_notes`;
- kategoria: `name`, `description`.

Puste pola nie wywołują API i mają `null` w nowym tłumaczeniu. Hash jest dokładnie
hashem Etapu 2A, także dla pustych pól. Długie teksty dzielone są na fragmenty
do 4500 znaków Unicode przed wywołaniem istniejącego providera.

| Stan | Zachowanie |
| --- | --- |
| Brak wiersza / `missing` | Tłumacz |
| Aktualny `translated` | Pomiń, bez API i zapisu |
| `reviewed`, także ze starym hashem | Zawsze pomiń; brak opcji nadpisania |
| `failed` | Pomiń; ponów wyłącznie z `--include-failed` |
| Inny hash / `needs_update` | Pomiń; tłumacz z `--include-needs-update` |
| `queued` z aktualnym hashem | Wznów, także z `--only-missing`, jeśli blokada zwolniona |

Bez `--only-missing` uwzględniane są też inne niegotowe rekordy z aktualnym hashem.
`--only-missing` wybiera brakujące rekordy i przerwane `queued`; jawne
`--include-failed` / `--include-needs-update` rozszerzają ten wybór. Przy apply
pominięty nieaktualny `translated` jest oznaczany `needs_update`, aby storefront
stosował fallback Etapu 2A. Wiersze reviewed nie są modyfikowane.

## Deploy i konfiguracja

Po zatwierdzeniu PR wdrożyć kod standardową procedurą. Muszą istnieć tabele
z migracji Etapu 2A. Domyślna flaga:

```dotenv
GPSWISS_FR_TRANSLATIONS_ENABLED=false
```

Config: `storefront-translations.fr_enabled`. Apply wymaga dodatkowo poprawnej
konfiguracji **istniejącego** providera na serwerze:

```dotenv
GOOGLE_TRANSLATE_ENABLED=true
GOOGLE_TRANSLATE_MODE=dry_run
GOOGLE_TRANSLATE_API_KEY=<istniejący klucz na serwerze>
```

Nazwa trybu `dry_run` jest istniejącym wymaganiem `GoogleTranslateService`;
jego `translate()` w tym trybie rzeczywiście wywołuje API. Nie zmieniamy serwisu
marketplace ani tej semantyki. Nie dodajemy DeepL ani nowej integracji.
Nie umieszczać kluczy w komendach, raportach ani logach.

Jeśli konfiguracja jest cache'owana, po zmianie `.env` wykonać standardowe
odświeżenie `php artisan config:cache`. Przypisanie zmiennej przed komendą nie
nadpisuje już cache'owanego configu. Dla wywołań jednorazowych można wykonać
`php artisan config:clear`, po czym ustawiać flagę w środowisku procesu.

## Uruchomienie całego katalogu na serwerze

Najpierw uruchomić odczyt; nie wymaga włączenia flagi i nie rozwiązuje providera:

```bash
php artisan storefront:fr-translations-translate-all --dry-run --type=all
```

Przykładowy wynik na fikcyjnych danych (nie pomiar produkcji):

```text
DRY-RUN: read-only; no Google API calls.
parts {"total":1,"eligible":1,"skipped":0,"fields":4,"estimated_characters":19}
categories {"total":1,"eligible":1,"skipped":0,"fields":2,"estimated_characters":16}
```

Oszacowanie dotyczy pól wybranych do tłumaczenia; pomija rekordy chronione
i niewybrane. Aby policzyć także odświeżenia i błędy, użyć tych samych opcji
selekcji w dry-run, które będą użyte w apply.

Po sprawdzeniu wyniku przez operatora, w konfiguracji serwera włączyć
`GPSWISS_FR_TRANSLATIONS_ENABLED=true` i odświeżyć config cache, a następnie:

```bash
php artisan storefront:fr-translations-translate-all --apply --type=all --only-missing --chunk=100 --confirm=TRANSLATE-ALL-GPSWISS-FR-CATALOG
```

Wariant jednorazowy, **przy niecache'owanej konfiguracji**:

```bash
GPSWISS_FR_TRANSLATIONS_ENABLED=true php artisan storefront:fr-translations-translate-all --apply --type=all --only-missing --chunk=100 --confirm=TRANSLATE-ALL-GPSWISS-FR-CATALOG
```

Jeśli dry-run wykazał również nieaktualne lub failed rekordy, po ocenie przez
operatora użyć rozszerzonego przebiegu:

```bash
php artisan storefront:fr-translations-translate-all --dry-run --type=all --only-missing --include-needs-update --include-failed
php artisan storefront:fr-translations-translate-all --apply --type=all --only-missing --include-needs-update --include-failed --chunk=100 --confirm=TRANSLATE-ALL-GPSWISS-FR-CATALOG
```

Po zakończeniu wyłączyć flagę i odświeżyć konfigurację. Zrestartować długotrwałe
workery kolejki po zmianach konfiguracji, jeśli są używane do tych jobów.
Ten PR nie wykonuje produkcyjnego apply ani zmiany konfiguracji produkcji.

## Postęp, przerwanie i retry

Komenda przetwarza rekordy synchronicznie w chunkach (domyślnie 100; 1–1000).
Każdy produkt/kategoria zapisuje własny wynik; nie ma transakcji obejmującej
cały katalog ani transakcji podczas HTTP. Postęp JSON po każdym rekordzie zawiera
`type`, `total`, `processed`, `translated`, `skipped`, `failed`,
`chars_translated`, `current_id`, `elapsed_time` (sekundy).
`chars_translated` liczy znaki źródła żądań zakończonych sukcesem, także przy
ponowieniach i odrzuconym wyniku; to nie jest dokładny rachunek Google.
Przy równoczesnych zmianach katalogu początkowe `total` jest oszacowaniem.

Ponowne uruchomienie pomija aktualne translated/reviewed. Przerwany `queued`
można wznowić po wygaśnięciu blokady (do 600 s). Failed wymagają `--include-failed`.
Rekord zmieniony podczas HTTP otrzymuje `needs_update`, a stary wynik nie jest
publikowany. Wiersz zmieniony przez review lub nowszą próbę nie jest nadpisywany.
Nieaktualny reviewed wymaga osobnej decyzji redakcyjnej poza tą komendą.

HTTP 429 i 5xx: maksymalnie 4 próby z backoff 30, 120, 300 s. Inne błędy nie
są automatycznie ponawiane. Komenda kontynuuje po błędzie rekordu i zwraca kod 1,
jeśli którykolwiek rekord zakończył się błędem; odmowa konfiguracji również 1.
Kod 0 oznacza ukończony przebieg bez błędów, z możliwymi pominięciami.

Joby `TranslatePartToFrenchJob` / `TranslateCategoryToFrenchJob` używają
`afterCommit()`, tego samego serwisu i backoff kolejki. Przekazują ID i opcje,
nie tekst źródłowy lub sekrety. Mają osobne połączenie `storefront-translations`
i kolejkę `storefront-fr-translations` na istniejącej tabeli `jobs`. Timeout joba:
540 s, `retry_after`: 660 s. Domyślna kolejka i workery marketplace nie są zmieniane.
Opcjonalny worker dla tych jobów:

```bash
php artisan queue:work storefront-translations --queue=storefront-fr-translations --timeout=540 --tries=4
```

Używać współdzielonego cache z obsługą atomic locks (database/Redis) na wszystkich
workerach i CLI; lokalny array/file cache nie koordynuje wielu serwerów.
Komenda masowa nie wymaga workera kolejki.

Błędy zapisują tylko kontrolowane kody i komunikaty; surowe wyjątki, odpowiedzi,
treść źródłowa i klucze providera nie trafiają do tych logów. Event logowania:
`storefront.fr_catalog_translation`, z identyfikatorem, SKU, locale, provider,
hashem, statusem, liczbą znaków, powodem skip, kodem błędu i attempts.

## Sprawdzenie po tłumaczeniu

```bash
php artisan storefront:fr-translations-translate-all --dry-run --type=all --only-missing
php artisan storefront:fr-translations-preview
```

Sprawdzić przykładowe produkty i kategorie na gpswiss.fr; Etap 2A czyta gotowe FR
i stosuje fallback. gpswiss.pl nadal czyta polskie pola. Nie modyfikujemy checkoutu,
PayU, Stripe, stocku, zamówień, Allegro/eBay/Ovoko, DNS/TLS i regulaminów.
