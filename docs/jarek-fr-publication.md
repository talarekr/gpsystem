# Publikacja skrzyń Jarka na GPSwiss.fr

## Architektura i zasady

Opcja A: `jarek_fr_products` jest osobną ekspozycją FR wskazującą na `jarek_gearboxes`. Nie tworzymy `Part` i nie zmieniamy globalnego `Part::scopeStorefrontVisible()`. Źródłowa cena, ilość i status Allegro/eBay pozostają bez zmian. Produkt widoczny publicznie dopiero po świadomym apply operatora. Migracja sama niczego nie publikuje.

Katalog FR i strona główna pokazują osobną sekcję Jarka. Pełna lista: `https://gpswiss.fr/boites-jarek`; karta: `/boites-jarek/{id-ekspozycji}`. Kontroler publiczny wymaga hosta FR. PL nie pokazuje sekcji ani pozycji Jarka w koszyku; próba wejścia na publiczne adresy Jarka na PL zwraca 404. Nie dodajemy polskich alternatywnych adresów hreflang. Sitemap FR zawiera osobny segment Jarka.

Cena jest liczona przy odczycie, nigdy zapisywana w źródle ani `parts`:

```
PLN_po_narzucie = round(source_price_PLN * 1.25, 2)
EUR = round(PLN_po_narzucie / kurs_EURPLN_NBP, 2)
```

Oba zaokrąglenia: half-up. Kurs pochodzi z istniejącego `NbpExchangeRateService`; poprawny, niewygasły cache jest używany, pusty/niepoprawny/stary cache jest odświeżany zwykłą ścieżką NBP. Data tabeli nie może być przyszła ani starsza niż siedem dni, zgodnie z dotychczasową walidacją preview. Brak poprawnego kursu blokuje apply i checkout. NBP jest jedynym połączeniem z dostawcą danych dopuszczonym podczas obliczeń publikacji; apply nie wywołuje Google ani marketplace. Nie pobieramy zdjęć przez API; używamy istniejących lokalnych zdjęć, następnie zapisanych URL. Publiczny interfejs ceny pokazuje tylko EUR.

Stała dostawa Jarka wynosi 130 EUR. Dotychczasowe mapowania 55/70/130 EUR dla zwykłych `Part` pozostają bez zmian. Jedna pozycja i jedna sztuka w koszyku FR; produkty Jarka i zwykłe produkty nie mogą tworzyć mieszanego koszyka.

`jarek_gearbox_translations` w statusie translated/reviewed dostarcza tytuł/opis. Puste albo niegotowe pola używają źródła. Brak tłumaczenia nie blokuje publikacji. Zmiany tłumaczeń stają się widoczne przy kolejnym odczycie, bez ponownego apply.

Qty FR=1 jest wirtualną dostępnością oferty dostawcy, niezależną od `JarekGearbox.quantity` oraz statusu marketplace. Płatność nie zmniejsza tej ilości ani nie wyłącza oferty. Aktywna, niewygasła próba Stripe blokuje równoległe rozpoczęcie checkoutu tej samej ekspozycji; po płatności lub wygaśnięciu możliwe jest następne zamówienie. Dostępność i realizację dostawcy trzeba obsługiwać operacyjnie.

Stripe korzysta z dotychczasowego checkoutu FR. Zamówienie i próba płatności wskazują na `jarek_fr_product_id` zamiast `part_id`; pozycja ma `source_type=jarek_gearbox`, ID źródła, `gpswiss_fr`, zdjęcie i snapshot ceny/kursu/dostawy. Podpisany webhook sprawdza tożsamość produktu oraz dotychczasowe dane kwoty/waluty/płatności. Oznacza zamówienie jako opłacone, bez decrementu `Part`/Jarka i bez synchronizacji marketplace. Panel szczegółów zamówienia pokazuje `Źródło: Jarek gearbox / gpswiss_fr`. Tryb Stripe test zachowuje istniejącą blokadę realizacji testowej.

## Instrukcja operatora

1. Wdrożyć zatwierdzony PR oraz migracje, w tym `2026_10_14_000002_create_jarek_fr_exposure.php`. Wymagany jest wcześniejszy PR #1486 i jego migracja tłumaczeń. Nowa migracja dodaje dwie tabele oraz nullable FK Jarka w pozycjach zamówień i próbach Stripe; `stripe_fr_attempts.part_id` staje się nullable. Nie uruchamiać publikacji przed zakończeniem migracji.
2. Zalogować się na PL jako `owner_admin`, otworzyć `https://gpswiss.pl/admin/tools/jarek-gearboxes/fr-publication`.
3. Kliknąć **Dry-run**. Operacja zapisuje wyłącznie techniczny raport operatora, bez aktywacji produktów. NBP może odświeżyć swój zwykły cache. Raport ważny jedną godzinę; zapisać jego link.
4. Sprawdzić wymagane liczniki, listę produktów i blokery, cenę bazową PLN, PLN +25%, EUR, kurs/datę/tabelę NBP, zdjęcia, dostawę 130 EUR i fallback tłumaczeń. `pl_visibility_risk_count` musi wynosić 0. Ten licznik opisuje gwarancję nowej ekspozycji; nie jest audytem historycznych kopii produktów w `parts`.
5. Wpisać dokładnie `PUBLISH-JAREK-GEARBOXES-GPSWISS-FR`.
6. Kliknąć **Apply publikacji FR**, sprawdzić i zachować wynik: liczby utworzonych/aktywowanych/już aktywnych/zablokowanych, ID ekspozycji i czas apply. Apply blokuje przeterminowany raport, zmianę źródła/tłumaczeń/zdjęć/kursu/widoczności i brak kursu. W takim przypadku wykonać i przejrzeć nowy dry-run. Niepoprawne pojedyncze źródła są pomijane z opisem blokera. Powtórzenie tego samego apply zwraca zapisany wynik i nie tworzy duplikatów.
7. Sprawdzić katalog/kartę/koszyk FR i brak sekcji Jarka na PL. Przed płatnościami live użyć zatwierdzonego procesu weryfikacji Stripe w środowisku testowym. Nie wykonywać testowej płatności live.
8. Osobno uruchomić tłumaczenia w `https://gpswiss.pl/admin/tools/storefront/fr-translations` → **Skrzynie Jarka — tłumaczenia FR**. Publikacja nie uruchamia tłumaczeń ani Google automatycznie.

Nie wykonano deployu, produkcyjnego dry-run/apply, zmian źródłowych cen/stocku, połączeń Google/marketplace ani rzeczywistych płatności w ramach przygotowania tego PR. Testy działają na SQLite w pamięci, z mockami Stripe/NBP/poczty i blokadą nieprzewidzianych HTTP.

## Ryzyka i obsługa

- Oferta może nadal być aktywna na marketplace. Ten PR celowo nie rezerwuje ani nie synchronizuje stocku dostawcy; obsługa dostawcy jest konieczna przy realizacji.
- Historyczne kopie skrzyń w `parts` mogą już istnieć. Nie są tworzone, aktywowane, usuwane ani zmieniane przez ten PR; dry-run nie deklaruje ich braku.
- Gotowość zdjęć oznacza zapisany poprawny URL albo istniejący lokalny plik, bez sprawdzania dostępności zdalnej. Zapisany URL może wygasnąć. Lokalne zdjęcia mają pierwszeństwo.
- Oferta z brakującą ceną PLN, tytułem, zdjęciami lub kwotą poza zakresem płatności jest blokowana. Brak opisu/tłumaczenia nie blokuje. Nie konwertujemy cen w walutach innych niż PLN.
- Zmiana ceny/kursu po otwarciu checkoutu wymaga nowego podsumowania i klucza; uruchomiona sesja Stripe zachowuje niezmienny snapshot zaakceptowanej kwoty.
- Bez nowych ustawień VAT: narzut jest liczony od zapisanej ceny źródłowej dokładnie według zatwierdzonej reguły. PR nie ustala, czy źródło jest netto/brutto i nie dodaje VAT.
- FK chroni źródło przed usunięciem po utworzeniu ekspozycji. Przyszły import zastępujący źródła przez delete/truncate wymaga osobnego planu; zwykły upsert nie jest blokowany.
- Nie cofać migracji po powstaniu płatności/zamówień Jarka: rollback odmawia usunięcia powiązań. Wycofanie ekspozycji powinno dezaktywować `jarek_fr_products.is_active` z zachowaniem zamówień, prób i raportów, w osobno zatwierdzonym działaniu. Opłacenie wcześniej uruchomionej sesji nadal jest rozliczane po dezaktywacji oferty.

## Pliki

Nowe:

- `database/migrations/2026_10_14_000002_create_jarek_fr_exposure.php`
- `app/Models/JarekFrProduct.php`
- `app/Services/Storefront/JarekFrPresentation.php`
- `app/Services/Storefront/JarekFrCatalog.php`
- `app/Services/Storefront/JarekFrPublication.php`
- `app/Http/Controllers/Storefront/JarekFrProductController.php`
- `app/Http/Controllers/Admin/JarekGearboxes/JarekFrPublicationController.php`
- `resources/views/admin/jarek-gearboxes/fr-publication.blade.php`
- `resources/views/storefront/fr/jarek-card.blade.php`
- `resources/views/storefront/fr/jarek-listing.blade.php`
- `resources/views/storefront/fr/jarek-product.blade.php`
- `tests/Feature/JarekFrPublicationAndCheckoutTest.php`
- `docs/jarek-fr-publication.md`

Integracja:

- `app/Services/Marketplace/NbpExchangeRateService.php`
- `app/Services/Storefront/CartService.php`
- `app/Services/Storefront/FrenchCheckoutService.php`
- `app/Services/Storefront/StorefrontSeo.php`
- `app/Services/Payments/StripeFrCheckout.php`
- `app/Services/Payments/StripeFrWebhook.php`
- `app/Models/OrderItem.php`
- `app/Http/Controllers/Storefront/CatalogController.php`
- `app/Http/Controllers/Storefront/HomeController.php`
- `app/Http/Controllers/Storefront/CartController.php`
- `app/Http/Controllers/Storefront/FrenchSitemapController.php`
- `resources/views/storefront/home.blade.php`
- `resources/views/storefront/catalog/_content.blade.php`
- `resources/views/storefront/fr/cart.blade.php`
- `resources/views/filament/resources/orders/pages/view-order.blade.php`
- `routes/web.php`

## Weryfikacja PR

PHP 8.4.26 / Laravel 11.57.0. Nowy zestaw: 13 testów publikacji, izolacji domen, tłumaczeń, NBP, koszyka, panelu zamówień i Stripe. Regresje z dodatkową blokadą nieprzewidzianych HTTP: **275 testów, 3327 asercji — PASS**. Blokada w bootstrapie była lokalną osłoną wykonania, nie zmianą w PR; nowy plik testów sam blokuje nieprzewidziane HTTP.

Rozszerzony zestaw 289 testów ma 14 istniejących błędów. Ten sam zestaw przypadków nie przechodzi na bazowym main `ae06362ad2566e8f1a4cb7b7c9c2b182e521d4c9`, uruchomionym bez implementacji publikacji i bez nowej migracji: 276 testów / 14 błędów / 0 errors. Nie wystąpiły dodatkowe regresje. Wykluczone z zielonego zestawu są wyłącznie te 14 przypadków: test CSRF w `JarekGearboxEbayBulkPricePreviewTest`, 10 przypadków `StorefrontCatalogSearchTest`, etykiety kategorii w `StorefrontCategorySidebarTest` oraz dwa przypadki `StorefrontProductBreadcrumbTest`. Nie poprawiamy ich w tym PR.
