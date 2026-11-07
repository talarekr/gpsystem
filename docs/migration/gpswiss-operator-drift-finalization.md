# Operator-accepted parts drift: finalizacja istniejących artefaktów

Mechanizm jest dostępny **wyłącznie** dla runu `8de6e27c-ad7c-470b-830b-b9ecee9446ba`, attemptu `9cb33c92-3f1e-4db0-b08d-052715aa5f4c` i zalogowanego właściciela z rolą OwnerAdmin. Nie jest polityką dla przyszłych eksportów. Nie uruchomiono produkcyjnego ACCEPT, eksportu zdjęć, wdrożenia ani importu.

Operator potwierdził, że stan biznesowy jest wystarczający i akceptuje deklarowany drift dwóch części. Dodatkowo jawnie wybrał: **sprawdzić integralność zapisanych plików i relacje, pominąć dalszy odczyt źródła i zapisać ten zakres w manifeście**. Akceptacja nie oznacza, że zmierzyliśmy cały drift ani że inne datasety są atomowym obrazem bazy. Pozostała weryfikacja źródła również jest pomijana, a jej poprzedni stan pozostaje w audycie.

## Akcja w panelu

1. Zatrzymać lokalną pętlę oraz pozostałe karty tego runu. Trwający tick chroni dotychczasowy flock; ACCEPT nie przerywa requestu w połowie.
2. Zaznaczyć checkbox opisujący zakres pominiętej weryfikacji i wpisać dokładnie `ACCEPT-PARTS-DRIFT-AND-FINALIZE`.
3. Kliknąć **Akceptuję znany drift części i finalizuję eksport**.
4. Pętla wykonuje bounded kontrolę artefaktów i istniejący audyt relacji, następnie inventory zdjęć. W tej otwartej stronie zatrzymuje się przed pierwszym kopiowaniem zdjęcia, pokazując „Zdjęcia gotowe”.
5. **Wznów istniejący eksport** rozpoczyna/wznawia kopiowanie zdjęć i dalsze pakowanie. Po reloadzie nic nie uruchamia się automatycznie; Wznów świadomie kontynuuje aktualny etap.

Backend wymaga właściwego runu/attemptu, zgodnej tożsamości źródła, kompletnej listy datasetów oraz `processed=total` i sumy zatwierdzonych rows=processed dla każdego datasetu. Kontekst musi wskazywać znany konflikt części lub rozpoczęte recovery. Nie akceptuje corruption, innego datasetu, niepełnego eksportu, innej próby/źródła ani więcej niż dwóch zapisanych konfliktowych ID. CSRF, własność i rola pozostają wymagane.

## Audyt, granice i idempotencja

Najpierw zapisuje się intent w logicznym checkpointcie, potem stan runu. Powtórzony ACCEPT nie zmienia accepted_at ani nie cofa zdjęć/archiwów. Przerwana publikacja root może być wznowiona w tym samym intencie. Nie powstaje nowy run/attempt/generation, nie są kasowane stare shardy, relacje, sumy, cursor eksportu ani metadata granic. Trwały wynik recovery to `accepted_known_drift`.

`consistency_exception` występuje w pełnym i próbnym manifeście oraz końcowym katalogu pakietu. Przykład znanego stanu:

```json
{
  "accepted_by_operator": true,
  "dataset": "parts",
  "reason": "known_post_cutoff_mutation",
  "recovery_skipped": true,
  "manual_correction_required": true,
  "outcome": "accepted_known_drift",
  "known_count_from_operator": 2,
  "recorded_ids": ["2262"],
  "remaining_operator_declared_without_recorded_id": 1
}
```

Dodatkowo zapisujemy accepted_at, ID operatora bez nazwy/emaila, run/attempt, istniejącą generation/boundary i pozycję recovery w momencie akceptacji. ID pochodzą wyłącznie z source_conflict parts i dokładnych dirty_ids shardów, nie z wszystkich ID odczytanej strony. Nie zgadujemy drugiego ID. Jeśli w checkpointcie nie ma ID, deklaracja operatora nadal jest osobno oznaczona, a recorded_ids nie jest uzupełniane na sztywno. Liczba operatora **nie jest wynikiem pełnej inwentaryzacji zmian**.

`operator_finalization.source_verification_at_acceptance` zachowuje verified/total/done każdego datasetu. Liczniki zweryfikowanych źródłowo rekordów nie są podnoszone do total. `live_source_verification_skipped=true`, tryb `stored_artifact_integrity_then_stored_relations_only` i limitations jawnie opisują wyjątek. Bieżąca nazwa database_consistency jawnie zawiera operator_accepted_parts_drift/artifact_integrity_and_relations; poprzednia nazwa jest zachowana w audycie. Historyczne cutoffy, per-dataset boundaries oraz kontrakty obserwacji mutable pozostają, ale nie deklarujemy spełnienia pominiętej weryfikacji.

## Kontrola artefaktów zamiast reconciliation

Do pięciu shardów i około sekundy pracy plikowej na request, z nowym checkpointem po każdej partii. Kontrola obejmuje właściwy attempt/dataset w ścieżce, istnienie i czytelność plików, SHA-256 i rozmiary JSON/CSV, format, zapisany nagłówek/schema, klucze i liczbę wierszy, checksum chain oraz zgodność z processed. Integralność jest weryfikowana od początku zapisanych plików, ale **bez odczytu żywych rekordów**, liczenia nowych dirty rows czy przepisywania shardów. Błąd integralności nadal blokuje finalizację; ACCEPT nie jest force-overwrite/force-complete uszkodzonych danych.

Jeżeli wcześniej poprawnie rozpoczęta runda mutable zdążyła przepisać dirty shard, jej aggregate checksum może jeszcze opisywać bazę sprzed rundy. Wyłącznie dla parts z pending dirty IDs dopuszczamy osobny sealed current chain: uprzednio sprawdzamy hash i schemat prywatnej zarchiwizowanej definicji bazowej (limit 4 MiB), jej oryginalny chain, zgodność liczby shardów oraz generation/boundary zmienionych shardów. Stare checksumy pozostają bez zmian, a chain_validation_exceptions jawnie opisuje różnicę. Brak/uszkodzenie bazy albo nieudokumentowana różnica nadal blokuje ACCEPT. Każdy bieżący plik i sealed chain są następnie sprawdzane w bounded finalizacji.

Po tej kontroli istniejący BrowserMigrationRelations bada wyłącznie relacje między zamrożonymi plikami, w bounded porcjach. Braki relacji trafiają do relation-issues.jsonl; nie są naprawiane w DB ani ukrywane. Dopiero potem inventory czyta zapisane metadane zdjęć. Techniczny cursor finalizacji pokazuje dataset_index/shard/artifacts_checked, oddzielnie od zachowanego cursora eksportu.

## Oryginały zdjęć i retry

Inventory obejmuje Part galleries, Part code_photo_path, Car galleries i legacy Car main_photo_path. Alias głównego zdjęcia samochodu, który wskazuje już ujęty plik galerii, nie tworzy drugiej kopii zadania. Car bez przypisanych części nadal jest eksportowany wraz ze swoimi zapisanymi zdjęciami. Części i samochody mają osobne przestrzenie nazw: `images/<part_id>/image-<image_id>/<original_name>` i `images/cars/<car_id>/image-<image_id>/<original_name>`. Zachowujemy kolejność, primary, role, entity_type/entity_id, part_id albo car_id i oryginalną ścieżkę logiczną. Nazwy nie są zamieniane na miniatury; wrażliwe/niebezpieczne nazwy są jawnie raportowane przez istniejącą politykę.

Istniejący silnik czyta domyślnie 512 KiB, maksymalnie 2 MiB na krok. Request wykonuje do czterech kroków w budżecie około 1,5 s. SHA-256 jest inkrementalny, z małym serializowalnym kontekstem; źródło i cel są weryfikowane, a plik nie jest wczytywany w całości do RAM. Uchwyty są zamykane w finally. Limit pojedynczego oryginału pozostaje 100 MiB: większy dostaje OVERSIZE_ORIGINAL w raporcie, bez podstawienia miniatury. URL-e dostają REMOTE_NOT_FETCHED; nie dodano pobierania od providerów.

Photo checkpoint zawiera task_offset, copy_index, phase, offset bajtowy i stan SHA. Receipt opublikowanego zdjęcia i obcięcie journala do zatwierdzonych bajtów zapobiegają duplikacji po crash/lost response. Poprawny istniejący cel jest sprawdzany i używany bez ponownego zapisu. Jawny failed-only retry stosuje dotychczasowe fingerprinty i canonical overlay; brakujące pliki bez pierwotnego fingerprintu lub SOURCE_CHANGED nie są po cichu zastępowane nowym źródłem. Źródłowe zdjęcia pozostają nietknięte.

Native `status` pozostaje kompatybilny. `export_status` normalizuje copied/missing/unreadable/checksum_failed/skipped, a skipped_reason/failure_reason wyjaśnia problem. Manifest zawiera MIME, rozszerzenie, rozmiar i SHA, jeśli mogły zostać ustalone. Pojedynczy missing/unreadable nie zatrzymuje całego pipeline; trafia do missing-originals.jsonl.

Compact status pokazuje postęp bajtów zdjęcia oraz offset fragmentu/volumes archiwum. Wspólny mechanizm #1520 rozpoznaje postęp mimo utraty odpowiedzi, także gdy liczba ukończonych zdjęć nadal wynosi zero. Pauza/wznowienie i zabezpieczony KICK obejmują zdjęcia i archiwa, bez zmiany attemptu, z zachowaniem źródłowej tożsamości i blokady runu.

## Pakiet i pobieranie

Istniejący silnik buduje osobne sample/full ZIP volumes: do 8 MiB payloadu i maksymalnie 20 wpisów na tick, bez jednego wielkiego requestu. Duże logiczne pliki są fragmentowane. Każdy ZIP ma __gpswiss_volume_manifest.json; sample-archive-manifest.json i archive-manifest.json wskazują **wszystkie** wolumeny, SHA, rozmiary i sposób odtworzenia plików.

Po archiwach bounded etap archive_catalog kopiuje indeks checksum po 128 KiB na tick. Powstają:

- migration-manifest.json oraz sample/manifest.json z jawnym accepted drift;
- wszystkie sample/full ZIP volumes i oba archive manifests;
- photo-manifest.csv / photo-manifest.jsonl i missing-originals.jsonl;
- checksums.jsonl: ścieżki logicznych artefaktów, rozmiary i SHA;
- migration-package-manifest.json: końcowy katalog wskazujący wszystkie wolumeny i manifesty z hashami oraz checksum index.

Końcowy katalog znajduje się poza ZIP-ami, żeby nie tworzyć cyklicznej zależności hashów archiwum zawierającego własny manifest. Jego własny SHA jest w prywatnym deskryptorze pobierania. Wszystkie linki wymagają zalogowanego właściciela, są private/no-store; nie ma publicznego URL. Paczka nadal zawiera dozwolone dane zamówień/klientów, więc musi pozostać prywatna.

`cutover_ready=false` pozostaje zachowane. Tylko końcowy katalog po ukończeniu sample/full ma `technical_package_ready=true`, jawnie w znaczeniu gotowych artefaktów z zaakceptowanymi wyjątkami i raportami. `originals_complete=false`, jeśli są nieskopiowane zdjęcia. Nie oznacza to atomowości, braku innych zmian, gotowego bezobsługowego importu ani publikacji. Automatic import/publish/synchronization są false.

## Weryfikacja

Nowe lokalne fixture obejmują pełną ścieżkę 5 części, zamówienia, samochodu bez części, galerii/legacy main, missing/unreadable, manifestów/archiwów/SHA i zerowej liczby zapytań SQL po ACCEPT. Osobny test 79957/79957 sprawdza wyłącznie dokładne liczniki i zachowanie intentu; to jawna projekcja techniczna, nie produkcyjny eksport ani dowód odczytu brakujących plików. Są negatywne testy niepełnego eksportu, checksum, source/attempt/owner/CSRF i deklaracji >2 ID; przerwanie publikacji ACCEPT oraz byte-checkpoint, pause/reload/resume/KICK dużego zdjęcia. Chromium testuje checkbox/token, lost response, idempotencję, zatrzymanie przed zdjęciami, wznowienie zdjęć i fragmentów archiwum.

Zero zmian Part/Listing/Order/stock, provider calls, marketplace writes, wiadomości, zatrzymywania sprzedaży/integracji i importu. Eksporter zapisuje wyłącznie własne prywatne checkpointy, kopie, raporty, manifesty i archiwa.
