# Jarek FR — ponowienie failed bez pełnego katalogu

## Przyczyna

Po #1494 marker ZXQ… był wysyłany w Google v2 `format=text`. Provider traktował go jak tekst,
więc mógł usuwać Q, pomijać marker między sąsiednimi lub zmieniać jego zapis. Dodatkowo cięcie bardzo długiego
tekstu mogło przeciąć marker. Kontrola prawidłowo odrzucała takie odpowiedzi.
Recovery #1494 obejmowało tylko technical_code_changed z ostatniego runu; google_translate_failed nie było objęte.
Zgłoszone 39+1 błędów i ID pochodzą od operatora, nie z odczytu produkcyjnej bazy w Codexie.

## Ochrona techniczna v3

Tylko Jarek używa Google v2 HTML. Każdy opaque marker jest opakowany w
`<span class="notranslate" translate="no">ZXQ…</span>`. Źródłowy tekst jest escapowany;
oryginalne wartości techniczne pozostają lokalne i są przywracane dopiero po walidacji.
Żadne źródłowe HTML/CDATA nie jest wykonywane. Zwykłe produkty/kategorie nadal używają format=text.
CDATA w trybie text nie chroniłoby wartości, dlatego nie zastosowano tego wariantu.

HTML jest dekodowany raz przez DOM z wyłączonym dostępem sieciowym. Usunięty, zmieniony, zduplikowany lub
obcy marker odrzuca całe tłumaczenie pola; nie zgadujemy brakującego markera, nie akceptujemy ZX zamiast ZXQ.
Granice segmentów nie przecinają markerów. Wersja 2 zapisanych diagnostyk pozostaje czytelna.
Google nie był uruchamiany na produkcji w Codexie — poprawność protokołu i przypadki uszkodzeń zweryfikowano na atrapach.
Jeśli provider mimo HTML zmieni marker, rekord pozostanie failed do ręcznej kontroli.

100 jest zachowywane konserwatywnie jako liczba (może być parametrem, a nie kodem), I20 i B8Skoda
jako identyfikatory z cyframi. Nie rozdzielamy B8Skoda. Kangoo jest rozpoznawanym modelem, także bez sąsiadującej marki.
Zwykłe polskie słowa opisowe nie są maskowane; same liczby/kody w polu nie wymagają Google.
Nie poluzowano ochrony w celu akceptowania uszkodzonych odpowiedzi.

## Panel i zakres

Na gpswiss.pl/admin/tools/storefront/fr-translations jest sekcja „Jarek — brakujące / błędne tłumaczenia”.
Dotyczy wyłącznie zapisanych failed FR Jarka; brak wiersza/missing/queued/needs_update nie jest ukradkiem dołączany.
Translated/reviewed nigdy nie są wybierane. GET i podgląd nie wywołują Google, nie tworzą tłumaczeń, cache preview ani runu.
Zapytanie selekcjonuje failed w bazie, nie wykonuje dry-run po 1518 produktach.

1. Wybierz wszystkie failed albo tylko technical_code_changed / tylko google_translate_failed.
2. Kliknij „Sprawdź brakujące tłumaczenia Jarka”: liczba pozostałych, breakdown kodów, wybrane SKU/ID i ostatni bezpieczny błąd.
3. Wpisz TRANSLATE-MISSING-JAREK-FR i kliknij „Przetłumacz tylko brakujące/błędne”. Apply może naliczać koszt Google.
4. Zostaw stronę otwartą. Jeden tick przetwarza jeden wybrany rekord przez dotychczasowy runner i service.
   Pauza/Stop pozostają dostępne podczas ticka. Po powrocie kliknij Wznów recovery dla jego małego checkpointu.
5. Naprawione znikają z failed. Nieudane pozostają na liście. Liczniki pokazują pozostałe failed, naprawione w tym retry,
   nieudane retry/do kontroli. Po zakończeniu wszystkie pozostające failed wymagają kontroli; można ponowić świeży podgląd i świadomy apply.

Podgląd ma podpisany/szyfrowany identyfikator przypisany do administratora, ważny godzinę.
Apply porównuje snapshot błędnych rekordów, source hash i numer próby; wykorzystany podgląd nie może ponownie uruchomić tej samej partii.
Zmiana statusu na translated/reviewed między preview i apply wymaga nowego preview; po starcie powoduje skip bez Google.
Zmiana próby/statusu jest ponownie sprawdzana atomowo pod blokadą tłumaczenia przed żądaniem.
Zmiana źródła jest jawnie oznaczona i wymaga ręcznej kontroli; nie nadpisujemy źródła.

## Checkpoint i koszty

Recovery ma własny mały scope jarek_failed w istniejącej tabeli storefront_fr_translation_runs, bez migracji.
Nie nadpisuje run_id, kursora, processed ani snapshotu pełnego runu Jarka.
Historyczne liczniki failed pełnego runu pozostają historią; bieżąca lista nowej sekcji czyta aktualne statusy w bazie. Oba korzystają ze wspólnego lease Jarka
oraz blokady rekordu, więc nie tłumaczą tego samego rekordu równocześnie.
Nowa sekcja korzysta z mechanizmu technical retry #1494 zamiast własnej kopii logiki tłumaczenia.
Wznów w starym pełnym runie obejmuje teraz też google_translate_failed; nie trzeba go używać dla nowej sekcji.
Pojedynczy technical_code_changed/google_translate_failed nie zatrzymuje reszty. Wyłączenie konfiguracji, niepewna przerwana próba
lub błędy HTTP providera zachowują dotychczasowe zabezpieczenia i ograniczone retry/backoff.
Nowa sekcja ma osobny limiter 40/min, aby polling innych sekcji nie zużywał jej limitu.

Nie zmieniano cen, źródeł JarekGearbox, zdjęć, stocku, marketplace, płatności ani URL-i. Nie wykonano deployu ani produkcyjnego apply.
