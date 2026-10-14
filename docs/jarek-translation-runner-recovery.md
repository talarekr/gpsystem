# Jarek: ciągły runner tłumaczeń i retry kodów technicznych

## Przyczyna

Każdy failed rekord, także `technical_code_changed`, przełączał cały run na stopped_on_error. Browser timer prawidłowo zatrzymywał się na tym statusie, więc operator musiał wznawiać po każdym takim produkcie. Status recovery był dodatkowo wyłączany przy aktywnym lease, mimo że istniejący running browser run wymagał tylko ponownego uruchomienia lokalnego timera.

Stare znaczniki zawierały słowo JAREK, hash i podkreślenia; ich zmiana przez dostawcę powodowała odrzucenie całego tłumaczenia. Bez surowych odpowiedzi produkcyjnych nie potwierdzono konkretnej transformacji dla pięciu zgłoszonych produktów.

## Poprawka

- Jarek kontynuuje po błędach rekordu `technical_code_changed`, `translated_name_too_long`, `source_changed`, zapisując błąd. Błędy providera/konfiguracji, niepewne przerwanie lub wyczerpane transient retries nadal zatrzymują koszty.
- Running browser Resume włącza lokalny timer bez zwalniania cudzego live worker lease. Tick pozostaje chroniony tym samym lease. Pozostałe scope i queue zachowują dotychczasową kontrolę.
- `resume_available` wskazuje niedokończony zakres oraz jednorazowe odzyskanie błędów z poprzedniej wersji tokenizacji. Pause/Stop/GET nie uruchamiają tłumaczeń.
- Neutralne krótkie alfanumeryczne znaczniki zastępują stare długie markery z podkreśleniami. Wyłącznie wielkość liter znacznika może się zmienić. Brak, duplikat, zmieniony identyfikator lub dodatkowy obcy marker są odrzucane. Ochrona nie została wyłączona.
- Ochrona obejmuje numery/OEM/kody, engine strings, proporcje, parametry marki/modelu i rozpoznawalne marki/model po marce. Przykłady RGA/QQG/QMF/20XT02/A9063502500/51/10/1.2TSI/1.6TDI/1.2THP są przetestowane. Tłumacz nadal otrzymuje opisowe słowa, np. Regenerowana/Tylny Most/Bliźniak.
- Diagnostyka błędu pokazuje pole, oczekiwany marker, kod źródłowy, liczbę wystąpień i wybrane marker/code fragments odpowiedzi. Nie zapisuje całego opisu/odpowiedzi API; długie same liczby są redagowane jako potencjalne dane kontaktowe. Historyczne komunikaty pozostają nieufne i nie są wyświetlane wprost.

## Istniejący run i bezpieczny retry

Po wdrożeniu sprawdź sekcję **Skrzynie Jarka — tłumaczenia FR** na `/admin/tools/storefront/fr-translations`. Dla zgłoszonego runu `295dd611-17df-46ee-9646-4c941f7e497f` kliknij **Wznów**, nie Start. Nie twórz nowego runu i nie czyść cache.

Przy pierwszym Resume po zmianie tokenizacji powstaje w istniejącym checkpointcie ograniczona lista wcześniejszych failed technical_code_changed z tego runu, za jego głównym kursorem. Rekord musi nadal mieć ten błąd, pasujący source_hash i niezmieniony attempt. Retry przechodzi przed pozostałym głównym zakresem; `processed`, główny kursor i main remaining nie są cofane. Udany retry przenosi wynik z failed do translated, zaś osobne retry metrics pokazują retry total/processed/remaining/translated/failed/skipped.

Ponowne Resume nie odtwarza tej listy. Ochrona reviewed/current i zmiana rekordu/attemptu nadal blokują nieautoryzowane powtórzenie. Jeden repair pass na wersję tokenizacji zapobiega zapętleniu kosztów. Zakończony run z wcześniejszymi błędami też może wykonać ten pojedynczy pass; Stop pozostaje terminalny. Kolejne nieusunięte błędy wymagają kontroli i ewentualnej nowej poprawki — brak nieskończonego automatycznego retry.

Live lease może jeszcze blokować tick przez maksymalnie dotychczasowe 600 s. Wznów nie usuwa tej blokady; pozostaw kartę otwartą. Po reloadzie lokalny timer jest wyłączony, a istniejący checkpoint zachowany. Kliknij Wznów raz. Utrata sesji/uprawnień lub sieci zatrzymuje lokalne zlecanie; po zalogowaniu odśwież status i Wznów.

Nie odczytano produkcyjnego checkpointu w Codexie; identyczny scenariusz i zachowanie skryptu przeglądarki zostały sprawdzone offline. Produkcyjny przycisk Wznów wywołuje istniejącego Google providera i zapisuje tłumaczenia, tak jak wcześniej; w Codexie nie uruchamiano realnego Google API, produkcyjnych zapisów ani deployu.

## Walidacja

62 testy PHP / 862 asercje dotyczące Jarka, browser runnera, trwałego recovery i standardowego katalogu — OK. `node tests/Browser/fr-translation-runner.cjs` wykonuje rzeczywisty współdzielony skrypt Blade offline: Start/Resume timer, kolejne ticki po failed record, Pauza, Resume, reload bez samoczynnego API work i completed. Bez zmian źródłowego JarekGearbox, cen, stocku, publikacji, checkoutu, Stripe/PayU ani marketplace.

Najpierw wdrożyć mały PR naprawiający migrację obrazów (#1493), aby odblokować deploy po #1492. Ten PR nie zmienia migracji.
