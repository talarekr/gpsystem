# Runner po #1512: zachowanie istniejącego checkpointu

Kod #1512 zamieniał wszystkie wyjątki obsługi fetch na `network_error`, ukrywając timeout i błąd parsowania. Nie ma produkcyjnych logów ani dostępu do checkpointu, więc same zgłoszone objawy nie rozstrzygają przyczyny zatrzymania przy 2528/79957. Nie uruchamiano produkcyjnego eksportu ani nowej próby.

Po wdrożeniu operator odświeża panel i klika **Wznów istniejący eksport** dla `8de6e27c-ad7c-470b-830b-b9ecee9446ba`. Nie używa Ponów snapshot. Run, attempt, granice i niezmienne porcje pozostają. Nowe porcje mają limit 5 rekordów; weryfikacja istniejących porcji zachowuje ich pierwotny rozmiar i sumy.

Przejściowe błędy transportu, timeout, malformed JSON oraz 408/429/500/502/503/504 prowadzą do backoff i odczytu statusu. Dopiero udany odczyt statusu pozwala ponowić tick. Jeśli poprzedni tick zapisał checkpoint mimo utraty odpowiedzi, pętla używa nowego kursora. Pięć kolejnych nieudanych ticków kończy pętlę z `retry_exhausted`; status poll ma osobny limit czterech odczytów. 401/419 wymagają loginu/reloadu, 403 i trwałe blokady nie są ponawiane. Nie są ponawiane automatycznie Start ani Retry snapshotu.

Panel pokazuje bieżącą diagnostykę i cztery ostatnie błędy: endpoint/action, HTTP, czas, otrzymane bajty, timeout, bezpieczny typ wyjątku, safe reason i kursor. Nie pokazuje body, nagłówków, CSRF, komunikatu wyjątku ani stacktrace. Fetch udostępnia zwykle tylko TypeError dla resetu połączenia, DNS, TLS i innych problemów transportu; bez dowodu przeglądarka oznacza je jako `unknown_network_error`, zamiast udawać rozpoznanie resetu.

Backend zapisuje prywatny `last-tick.json` przed snapshot tickiem i po zatwierdzeniu checkpointu: dataset, kursory, limit/attempted/completed, czas i klasę wyjątku bez message/SQL/stack. Rozpoznane timeouty zapytania i krytyczny limit PHP mają `backend_timeout`. Shutdown jest best effort; SIGKILL nie wykona handlera. Pozostawione `tick_running` z wolną blokadą wskazuje niedokończony zapis diagnostyki lub przerwanie procesu, bez rozstrzygania konkretnego limitu hostingu. Diagnostyka nie jest źródłem postępu i nie trafia do paczki migracyjnej.

Nieznany przejściowy wyjątek backendu zachowuje ostatni zatwierdzony stan do replay. Wykryta zmiana źródła/schematu/sumy nadal blokuje próbę; nie jest traktowana jako błąd sieci. JSON odpowiedzi snapshot_tick zawiera postęp i diagnostykę, bez listy wszystkich datasetów ani danych produktów. Źródłowe dane biznesowe, providerzy, integracje i architektura logicznego snapshotu pozostają bez zmian.
