# Ponowienie browserowego deployu po #1492

## Przyczyna

Migracja `2026_10_14_000004_create_jarek_image_runner_checkpoints.php` tworzyła bezwarunkowo `storefront_jarek_image_runs` oraz `storefront_jarek_image_items`. MySQL nie wycofuje całej migracji DDL po przerwaniu; tabele/kolumny/indeksy mogą istnieć, mimo że Laravel nie zdążył dopisać wpisu w `migrations`. Browserowy deploy może ponowić tę samą migrację i zatrzymać się na `1050 table already exists`.

Dodatkowo automatyczna nazwa unique indeksu `storefront_jarek_image_items_run_id_source_id_image_number_unique` ma **65 znaków**, przekraczając limit MySQL **64**. To niezależny błąd DDL, który mógł spowodować pierwsze przerwanie. Nie odczytano pierwotnego logu produkcyjnego ani schema produkcyjnego; konkretny etap pierwotnej awarii nie jest potwierdzony.

## Poprawka

- Tworzy każdą tabelę tylko wtedy, gdy jej nie ma.
- Na istniejącej tabeli dodaje wyłącznie brakujące kolumny, bez zmiany istniejących wartości/typów.
- Brakujące zwykłe kolumny na starszych rekordach są nullable: nie wymyśla run_id, checkpointu, operatora ani statusu. Nie przywraca brakującej historycznej treści checkpointu.
- Sprawdza indeksy według kolumn i wymaganej unikalności, także gdy istnieją pod innymi nazwami. Nowe nazwy są krótkie, poniżej limitu MySQL. Kolizja samej nazwy z innym indeksem dostaje bezpieczny deterministyczny sufiks.
- FK tworzy dla nowych tabel; zachowuje istniejące FK, nie narzuca nowych ograniczeń na niezweryfikowane starsze rekordy.
- `down()` celowo nie usuwa tabel/kolumn/indeksów ani danych. Nie można ustalić, czy istniejąca tabela powstała w tym wykonaniu migracji. Rollback nie cofa technicznego schema; ponowne up jest bezpieczne.

Nie usuwa duplikatów, aby wymusić unique. Jeśli stare dane naruszają unikalność run_id albo `(run_id, source_id, image_number)`, migracja pozostawi dane i zgłosi problem zamiast je kasować. Zgłoszony błąd 1050 sam nie dowodzi takich duplikatów.

## Operator

Po merge i udostępnieniu poprawionego kodu **ponów deploy browserowy**. Nie usuwaj tabel ani wpisów migrations i nie wykonuj ręcznego SQL; nie potrzebujesz shella. Dotychczasowy mechanizm deployu ponowi oczekującą migrację, rozpozna istniejące obiekty i uzupełni brakujące schema.

Po udanym deployu otwórz panel lokalnych zdjęć Jarka i sprawdź zapisany postęp. Nowy GET nie rozpoczyna przygotowania obrazów; istniejący poprawny checkpoint powinien zostać zachowany.

Zmiana dotyczy wyłącznie technicznej migracji. Bez zmian cen, kategorii, tłumaczeń, stocku, marketplace, Stripe/PayU lub checkoutu. W sesji Codex nie uruchomiono migracji/deployu na produkcji ani providerów.
