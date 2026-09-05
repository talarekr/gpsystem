# Audyt techniczny nawigacji panelu administracyjnego GPSwiss

**Data audytu:** 2026-09-05  
**Zakres:** statyczna, tylko odczytowa analiza kodu repozytorium  
**Środowisko uruchomieniowe:** aplikacja nie była uruchamiana; nie wykonywano żądań HTTP ani operacji marketplace

## 1. Podsumowanie w 5–10 punktach

1. Panel jest panelem **Filament 3** na **Laravel 11**. `composer.json` deklaruje `filament/filament:^3.2` i `laravel/framework:^11.0`; brak `composer.lock` i `vendor/` uniemożliwia potwierdzenie dokładnych wersji Filament, Laravel, Livewire i Alpine. **Nie udało się potwierdzić z kodu** dokładnych wersji patch/minor faktycznie zainstalowanych.
2. Panel `admin` jest rejestrowany przez `App\Providers\Filament\AdminPanelProvider`, ma prefiks `/admin`, logowanie Filament i domyślnie włączony tryb SPA (`->spa()`), sterowany przez `FILAMENT_SPA_ENABLED` (domyślnie `true`).
3. Nawigacja między zgodnymi ekranami panelu odbywa się przez **Livewire Navigate**, a nie Turbo/PJAX. Filament generuje zwykłe adresy URL, ale przechwytuje kliknięcia i pobiera dokument aplikacyjnie; customowe linki mają jawne `wire:navigate`.
4. Jest lista wyjątków SPA (logowanie/wylogowanie, OAuth, download/export, JSON, dry-run/apply, runner/sync/deploy/label, `/tools/*`, `/storage/*`). Dla nich pozostaje klasyczna nawigacja przeglądarki. Wyłączenie flagi SPA również przywraca pełne przeładowania.
5. Sidebar i podstawowy layout pochodzą z Filament. GPSwiss dodaje własny topbar, CSS i JS do zwijania sidebara, wyszukiwarki części, obsługi modali oraz ponownej inicjalizacji po `livewire:navigated`.
6. Główny listing części nie korzysta z deklaracji `Filament\Tables` podczas renderowania. Jest customową stroną Filament/Livewire (`ListParts extends Page`) z własnym Blade, Eloquent query, `WithPagination` i partialem kart `parts-card-list.blade.php`.
7. Wyszukiwanie, filtry, sortowanie i rozmiar strony listingu są publicznym stanem Livewire powiązanym przez `#[Url]` z query string. `wire:model.live` wysyła aktualizacje Livewire (wyszukiwanie/pola liczbowe z debounce 500 ms); paginacja używa `wire:click`.
8. Kliknięcie „Edytuj” używa normalnego URL wygenerowanego przez `PartResource::getUrl('edit', ...)`, ale z `wire:navigate`, więc przy aktywnym SPA jest przejściem aplikacyjnym. Formularz edycji jest standardową stroną `EditRecord` i formularzem Filament działającym jako komponent Livewire.
9. W kodzie nie ma Turbo ani PJAX i nie ma własnego ogólnego routera SPA. **Wniosek z kodu:** wrażenie SPA zapewnia standardowy mechanizm Filament/Livewire Navigate, a nie autorska warstwa routingu GPSwiss.
10. Nie znaleziono Filament Tabs w audytowanym kodzie. Znaleziony przykład kart (`ShopEvents`) jest customowym komponentem Livewire: `setTab()`, `wire:click.prevent` i `#[Url(history: true)]`.

## 2. Użyty stack panelu admina

| Warstwa | Potwierdzone z repozytorium | Poziom pewności |
|---|---|---|
| PHP | `^8.3` | constraint, nie wersja runtime |
| Laravel | `laravel/framework:^11.0` | Laravel 11.x dopuszczony; dokładna wersja niepotwierdzona |
| Filament | `filament/filament:^3.2` | Filament 3.x, minimum 3.2; dokładna wersja niepotwierdzona |
| Livewire | zależność przechodnia Filament, namespace `Livewire`, dyrektywy `wire:*` | użycie potwierdzone; dokładna wersja niepotwierdzona |
| Alpine | składnia `x-data`, `x-show`, `x-on`, `$wire`; bundle zapewnia Filament | użycie potwierdzone; dokładna wersja niepotwierdzona |
| JS/CSS build | brak `package.json`, brak `resources/js` i `resources/css`; statyczne pliki `public/js` i `public/css` | brak własnego Vite dla admina |

Nie istnieje `config/filament.php`. Panel konfiguruje provider. `config/livewire.php` nadpisuje wyłącznie ustawienia uploadu tymczasowego; endpoint i assety Livewire pozostają konwencją pakietu. **Nie udało się potwierdzić z kodu** dokładnych URL-i assetów i dokładnej ścieżki endpointu update wygenerowanych przez konkretną zainstalowaną wersję, ponieważ nie ma lockfile ani vendor.

## 3. Routing i panel provider

`bootstrap/providers.php` rejestruje `AdminPanelProvider`. Provider:

- ustawia `id('admin')`, `path('admin')`, `login()` i panel domyślny;
- odkrywa zasoby z `app/Filament/Resources`;
- jawnie rejestruje strony z `app/Filament/Pages`;
- tworzy grupy nawigacji i dwa dodatkowe `NavigationItem`;
- włącza SPA warunkowo;
- dodaje render hooks do `<head>` i początku `<body>`.

Trasy zasobów nie są ręcznie zapisane w `routes/web.php`: Filament buduje je z `PartResource::getPages()`. Dla części są to indeks `/admin/parts`, create, `to-list`, `sold-listing`, `sold`, `needs-review`, view i edit `/{record}/edit`. Są to normalne routowalne URL-e; SPA zmienia sposób ich pobrania, nie model routingu serwerowego.

Middleware panelu, w kolejności providera: `EncryptCookies`, `AddQueuedCookiesToResponse`, `StartSession`, `AuthenticateSession`, `ShareErrorsFromSession`, `VerifyCsrfToken`, `SubstituteBindings`, `DisableBladeIconComponents`, `DispatchServingFilamentEvent`; osobny auth middleware to `Filament\Http\Middleware\Authenticate`.

Customowe trasy `/admin/...` w `routes/web.php` są zwykłymi trasami Laravel pod `Illuminate\Auth\Middleware\Authenticate`. Część dostaje również alias `admin.panel`, wskazujący na `EnsureAdminPanelAccess`, który wykonuje `user()->canAccessPanel(Filament::getPanel('admin'))`. Należy zauważyć, że nie każda customowa trasa w grupie z linii 2493 ma jawnie `admin.panel`; wszystkie mają natomiast `Authenticate`.

## 4. Mechanizm nawigacji między stronami

### Kliknięcie pozycji menu lub linku `wire:navigate`

1. Filament/Resource generuje prawidłowy `href` do zwykłej trasy Laravel.
2. Przy aktywnym `->spa()` Livewire Navigate przechwytuje kwalifikujące się kliknięcie.
3. Zamiast tradycyjnego przeładowania dokumentu wykonuje żądanie GET na docelowy URL w trybie navigate, odbiera HTML następnej strony, aktualizuje historię/URL i wymienia zawartość dokumentu zarządzaną przez mechanizm nawigacji.
4. Uruchamia lifecycle nawigacji; kod GPSwiss nasłuchuje co najmniej `livewire:navigating` i `livewire:navigated`.
5. Nowa strona Filament zostaje zamontowana jako nowy komponent Livewire; jej `mount`/hydracja i zapytania danych wykonują się dla ekranu docelowego.

**Wniosek z kodu:** URL zmienia się bez klasycznego pełnego reloadu przy domyślnej konfiguracji. To potwierdzają równocześnie `->spa()`, jawne `wire:navigate` i obsługa `livewire:navigated`. **Nie udało się potwierdzić z kodu** konkretnego nagłówka oraz dokładnego zakresu elementów DOM wymienianych przez faktycznie zainstalowany build Livewire; wymagałoby to `composer.lock`/vendor albo inspekcji Network/DOM.

Nie znaleziono `wire:persist` w aplikacji. Dlatego nie należy zakładać, że customowy topbar/sidebar pozostaje fizycznie tym samym węzłem DOM. Skrypty są napisane defensywnie pod ponowną inicjalizację (`livewire:navigated`, atrybuty `data-gps-spa-bound`, singleton `window.GpsAdminTopbar`). **Wniosek z kodu:** wizualnie shell pozostaje ciągły, ale trwałości konkretnych węzłów DOM nie udało się potwierdzić z kodu aplikacji.

### Kiedy występuje pełny reload

- `FILAMENT_SPA_ENABLED=false`;
- URL pasuje do `spaUrlExceptions()`;
- link nie zostanie objęty mechanizmem Navigate (np. zewnętrzny URL, download, brak integracji na customowej stronie);
- kod jawnie użyje `window.location.reload()` — taki przypadek istnieje po customowym zapisie sprzedaży lokalnej na dashboardzie.

Nie znaleziono `turbo:load`, biblioteki Turbo ani PJAX.

## 5. Rola Filament

Filament zapewnia panel shell, sidebar/nawigację, rejestrację i routing Resources/Pages, uwierzytelnianie panelu, layout, asset bundle, akcje, formularze, powiadomienia oraz integrację stron jako komponentów Livewire. `NavigationGroup` i `NavigationItem` w providerze konfigurują hierarchię, aktywny stan i URL. `PartResource::getNavigationItems()` customizuje pozycje Części, dodaje liczniki oraz osobne linki „Do wystawienia” i „Sprzedane”.

`PartResource::table()` zawiera standardową definicję Filament Table (kolumny, filtry i akcje), ale `ListParts`, `PartsToList` i `ListSoldParts` dziedziczą po customowym `Page`, nie `ListRecords`, i ich Blade nie renderuje `$this->table`. **Wniosek z kodu:** bieżący kartowy listing tych trzech ekranów korzysta z własnego query/UI, a deklaracja `table()` pozostaje wykorzystywalna przez strony `ListRecords` (np. `PartsNeedsReview`) lub jest pozostałością/alternatywną konfiguracją. Dokładnego pokrycia runtime nie udało się potwierdzić bez uruchomienia.

## 6. Rola Livewire

Każda strona Filament jest komponentem Livewire. Livewire obsługuje:

- nawigację dokumentową SPA;
- publiczny stan `ListParts` i `SoldParts`;
- synchronizację `#[Url]`;
- reaktywne pola `wire:model.live`;
- debounce wyszukiwania i zakresów;
- akcje `wire:click`, `wire:change`, `wire:submit.prevent`;
- `WithPagination` (`previousPage`, `gotoPage`, `nextPage`);
- formularze `CreateRecord`/`EditRecord` oraz ich walidację, hydrację i zapis;
- morphing odpowiedzi HTML do istniejącego DOM po requestach komponentu.

Przy zmianie filtra nie jest pobierany cały dokument strony. Livewire wysyła stan/snapshot komponentu i wywołania/zmiany do endpointu update, serwer ponownie renderuje komponent, a klient aplikuje różnicę DOM. Typowo dla Livewire 3 jest to request POST do endpointu update (zwykle `/livewire/update`), lecz **nie udało się potwierdzić z kodu** ostatecznego URL tej instalacji.

## 7. Rola Alpine/JS

Alpine jest używany bezpośrednio w Blade m.in. przez `x-data`, `x-show`, `x-cloak`, `x-transition`, `$wire` oraz obsługę drawerów i galerii. Dla listingu części Alpine utrzymuje wyłącznie lokalny stan `filtersOpen`; faktyczne wartości filtrów należą do Livewire. Ponieważ `filtersOpen` nie jest URL-em ani localStorage, po ponownym montażu strony wraca do `false`.

Customowe assety admina są ładowane hookiem `panels::head.end`:

- `public/css/filament-admin.css`;
- `public/js/filament-admin-topbar.js`;
- `public/js/filament-admin-dashboard.js`;
- inline `resources/views/filament/admin-csrf-session-guard.blade.php`.

`filament-admin-topbar.js` przechowuje zwinięcie sidebara w `localStorage`, zamyka sidebar mobilny po kliknięciu linku, reaguje na `livewire:navigated` i wykonuje fetch do `/admin/search/parts`. `filament-admin-dashboard.js` zamyka transient modal przy `livewire:navigating`, inicjalizuje elementy ponownie po `livewire:navigated` i po zapisie lokalnej sprzedaży wymusza pełny reload. Guard CSRF okresowo wykonuje bezpieczny GET tokenu, aktualizuje token Livewire i przekierowuje do logowania po 419.

Nie ma `package.json`, deklaracji `@vite`, własnego routera JS, Turbo ani PJAX. Filament ładuje własne Livewire/Alpine i bundle panelu poprzez swój layout — nie przez layout aplikacji znajdujący się w repozytorium.

## 8. Jak działa listing części

### Ekrany i klasy

| Ekran | Klasa | Widok / mechanizm |
|---|---|---|
| Części | `PartResource\Pages\ListParts` | `filament.resources.parts.pages.list-parts` + partial kart |
| Do wystawienia | `PartResource\Pages\PartsToList` | dziedziczy cały widok/state po `ListParts`; zmienia base query |
| Sprzedane (listing kart) | `PartResource\Pages\ListSoldParts` | dziedziczy po `ListParts`; query `adminSoldPartsQuery()` |
| Sprzedane części (historia) | `PartResource\Pages\SoldParts` | osobny `sold-parts.blade.php`, kolekcje sprzedaży + własna paginacja |
| Edycja | `PartResource\Pages\EditPart extends EditRecord` | standardowy layout/form Filament z `PartResource::form()` |
| Utworzenie | `PartResource\Pages\CreatePart extends CreateRecord` | standardowy form Filament |
| Podgląd | `PartResource\Pages\ViewPart extends ViewRecord` | standardowy view record Filament |

`list-parts.blade.php` pobiera computed property `$this->parts`, renderuje toolbar i includuje `filament.resources.parts.partials.parts-card-list`. Partial jest więc renderowany **wewnątrz komponentu Livewire strony** i zostaje ponownie wyrenderowany/morfowany po zmianie stanu.

Query startuje od:

- `adminAllPartsQuery()`: części na sprzedaż, nieoczekujące na wystawienie i niewymagające wyjaśnienia;
- `adminPartsToListQuery()`: `needs_listing=true`;
- `adminSoldPartsQuery()`: scope `sold()`.

Następnie eager-loaduje zdjęcia, listingi marketplace, lokalizację, kategorię i samochód; nakłada wyszukiwanie, filtry, zakresy, filtr luk marketplace oraz sortowanie. Paginacja jest wykonywana w bazie przez `paginate(...)->withQueryString()`, poza opcją `all`, która pobiera całość i opakowuje kolekcję w paginator.

## 9. Jak działają filtry/sortowanie/paginacja

- Stan: publiczne properties `ListParts`.
- URL: każde ważne pole ma `#[Url(as: ...)]`; sort i `per_page` również.
- Historia: atrybuty listingu nie ustawiają `history: true`. **Wniosek z kodu:** URL jest synchronizowany, ale kod aplikacji nie wymusza osobnego wpisu historii dla każdej zmiany; dokładne zachowanie replace/push zależy od Livewire 3.
- Wyszukiwanie i tekst/liczby: `wire:model.live.debounce.500ms`.
- Selecty: `wire:model.live`, czyli request po zmianie.
- Reset: `wire:click="resetFilters"`.
- Paginacja: customowy `gps-polish.blade.php` wywołuje metody `WithPagination` przez `wire:click`.
- Zmiana dowolnego property poza `page` uruchamia hook `updating()` i `resetPage()`.
- Sort: `applySort()` mapuje wartość UI na jawne `orderBy`.

Żadna z tych operacji nie wymaga pełnego reloadu dokumentu: są to requesty aktualizacyjne Livewire i morph fragmentu strony. Parametry w URL pozwalają odtworzyć filtry po otwarciu skopiowanego URL i po zwykłym ponownym wejściu na ten URL. Nie ma własnego zapisu filtrów w sesji/localStorage.

**Scroll:** nie ma `scrollTo`, `wire:scroll`, `wire:persist` ani customowego zapisu scrolla dla części. **Nie udało się potwierdzić z kodu** zachowania scroll przy update i back/forward w konkretnym buildzie Livewire.  
**Stan filtrów po Edytuj → Wstecz:** query string strony listy istnieje w historii bieżącego URL, ale link Edit nie przekazuje jawnego `returnUrl`. **Wniosek z kodu:** browser Back może odtworzyć URL filtrów, natomiast przy wejściu w listę z menu wartości wrócą do domyślnych. Dokładny snapshot/scroll wymaga testu przeglądarkowego.

## 10. Jak działają edycje/formularze

`EditPart` dziedziczy po `Filament\Resources\Pages\EditRecord`. Schema formularza znajduje się w `PartResource::form()`. Filament/Livewire hydratuje `data`, wywołuje lifecycle (`mutateFormDataBeforeFill`, następnie przy zapisie `mutateFormDataBeforeSave`, właściwy zapis i `afterSave`) i zwraca aktualizację komponentu oraz powiadomienia.

Z perspektywy sieci:

- wejście w edycję: GET przez Livewire Navigate przy SPA;
- reaktywne zależności/form fields: request update Livewire, gdy dyrektywa/komponent tego wymaga;
- zapis: request POST Livewire z akcją formularza i serialized component state/snapshot; serwer wykonuje lokalny zapis i hooki;
- upload: osobny mechanizm tymczasowych uploadów Livewire, skonfigurowany do 100 MB;
- po zapisie Filament może pozostać na ekranie lub przekierować zgodnie z zachowaniem bazowej klasy/overrides. **Nie udało się potwierdzić z kodu** finalnego redirectu bazowej klasy bez źródeł zainstalowanej wersji Filament.

Ważne: `EditPart::afterSave()` może uruchamiać synchronizację cen marketplace przez `PartPriceSyncService`. Nie uruchomiono jej podczas audytu. Tego fragmentu nie należy kopiować jako ogólnej części nawigacji.

## 11. Czy jest pełny reload czy aplikacyjne przejście

**Odpowiedź:** domyślnie przejście między stronami panelu jest aplikacyjne, oparte o Filament SPA + Livewire Navigate. Nie jest to klasyczne SPA z klientowym routerem i JSON API; serwer nadal routuje zwykłe URL-e i renderuje Blade/Livewire. Nie jest to też zwykły request aktualizacji pojedynczego komponentu: nawigacja stron pobiera docelowy dokument GET, podczas gdy filtr/paginacja/formularz używa requestu aktualizacyjnego komponentu.

| Akcja | Request | Odpowiedź/efekt |
|---|---|---|
| menu / Edytuj / link `wire:navigate` | GET docelowego URL w trybie Navigate | zmiana URL i aplikacyjna wymiana strony |
| filtr / sort / per-page | POST update Livewire | serialized state + rerender/morph komponentu |
| paginacja | POST update Livewire z wywołaniem metody | zmiana `page`, query i fragmentu DOM |
| zapis formularza Filament | POST update Livewire/akcja | walidacja, zapis, rerender/redirect/notification |
| URL z listy wyjątków | klasyczny request przeglądarki | pełna odpowiedź/download/JSON/redirect |
| wyszukiwarka topbara | customowy GET JSON `/admin/search/parts?q=...` | dropdown JS, bez rerenderu Livewire |

## 12. Które pliki odpowiadają za ten mechanizm

- `composer.json` — constraints stacku.
- `bootstrap/providers.php` — rejestracja panel providera.
- `app/Providers/Filament/AdminPanelProvider.php` — panel, SPA, wyjątki, middleware, grupy i custom navigation.
- `config/product-hub.php`, `.env.example` — flaga SPA.
- `bootstrap/app.php` — routing Laravel, alias `admin.panel`, obsługa 419 dla Livewire.
- `app/Http/Middleware/EnsureAdminPanelAccess.php` — kontrola dostępu do customowych tras admin.
- `routes/web.php` — customowe endpointy admin (CSRF, search, tools, mapper); nie główne resource routes Filament.
- `app/Filament/Resources/PartResource.php` — form/table, queries, navigation, `getPages()`.
- `app/Filament/Resources/PartResource/Pages/ListParts.php` — state/query/filter/sort/pagination.
- `PartsToList.php`, `ListSoldParts.php`, `SoldParts.php`, `EditPart.php`, `CreatePart.php`, `ViewPart.php` — warianty stron części.
- `resources/views/filament/resources/parts/pages/list-parts.blade.php` — reaktywny toolbar i composition listingu.
- `resources/views/filament/resources/parts/partials/parts-card-list.blade.php` — karty, link Edit, akcje Livewire.
- `resources/views/vendor/pagination/gps-polish.blade.php` — paginacja Livewire.
- `resources/views/filament/admin-topbar.blade.php` — custom topbar i linki navigate.
- `resources/views/filament/admin-ui-refinements.blade.php` — ładowanie custom assetów.
- `public/js/filament-admin-topbar.js`, `public/js/filament-admin-dashboard.js` — zachowanie custom UI w lifecycle SPA.
- `resources/views/filament/admin-csrf-session-guard.blade.php` — refresh CSRF i hook request 419.
- `app/Livewire/Admin/ShopEvents.php` i `resources/views/livewire/admin/shop-events.blade.php` — customowe karty jako przykład Livewire state + URL history.

## 13. Co jest standardem frameworka, a co customem GPSwiss

### Standard Filament/Livewire

- PanelProvider, Resource/Page, resource routing i generowanie URL.
- Sidebar oraz rendering pozycji `NavigationItem`.
- `->spa()`/Livewire Navigate i wyjątki URL.
- page/form lifecycle, `CreateRecord`, `EditRecord`, `ViewRecord`.
- Livewire state, `#[Url]`, `WithPagination`, `wire:model`, `wire:click`.
- Alpine i bazowe assety/UI Filament.

### Custom GPSwiss

- flaga `FILAMENT_SPA_ENABLED` i szeroka lista bezpiecznych wyjątków;
- topbar marki, wyszukiwarka części i licznik zamówień;
- customowe CSS sidebara/listingów;
- JS sidebara, localStorage i re-inicjalizacja po eventach navigate;
- guard odświeżający CSRF co 10 minut i reakcja na 419;
- customowe `PartResource::getNavigationItems()` z licznikami;
- kartowy listing części, jego Eloquent query, filtry i polska paginacja;
- customowe tabs `ShopEvents` (nie Filament Tabs);
- domenowe akcje części i integracje marketplace.

## 14. Jak wdrożyć podobny mechanizm w innym sklepie

1. Utworzyć Laravel 11 i zainstalować zgodny Filament 3; zachować lockfile.
2. Utworzyć panel provider z `->path('admin')->login()->spa()`.
3. Dodać wyjątki SPA dla downloadów, OAuth/callbacków, odpowiedzi JSON, zewnętrznych URL-i i endpointów narzędziowych. Nigdy nie projektować write-capable GET tylko dlatego, że navigate/prefetch może wykonać GET.
4. Modelować ekrany jako Filament Resources/Pages. Pozostawić normalne URL-e; linki customowe oznaczać `wire:navigate`, jeżeli cel jest zgodną stroną panelu.
5. Dla standardowego CRUD zacząć od `ListRecords`, Filament Table, `CreateRecord`, `EditRecord` — to najmniej kodu i najlepsza zgodność z frameworkiem.
6. Jeśli potrzebne są karty produktów, utworzyć custom `Page` + `WithPagination`; publiczne pola filtrów opisać `#[Url]`; inputy spiąć `wire:model.live.debounce`, selecty `wire:model.live`.
7. Budować query serwerowo i eager-loadować relacje. Nie serializować modeli ani wielkich kolekcji jako publiczny state; wyliczać je jako computed property.
8. JS związany z elementami strony inicjalizować także po `livewire:navigated`, zabezpieczając się przed wielokrotnym bindingiem. Stan trwały UI przechowywać świadomie w URL/localStorage albo użyć wspieranego mechanizmu persist.
9. Testować menu, back/forward, deep links, query string, kilka kart przeglądarki, wygasłą sesję, modale i uploady.

### Co można przenieść 1:1 jako wzorzec

- ideę feature flagi SPA i listy wyjątków (po dostosowaniu ścieżek);
- strukturę `Page + #[Url] + WithPagination + wire:model.live`;
- debounce search, reset strony przy zmianie filtra;
- wzorzec eventów `livewire:navigating/navigated`;
- normalne `href` jako progressive fallback;
- customowy widok paginacji oparty o metody Livewire.

### Czego nie kopiować 1:1

- zapytań i statusów `Part`, liczników wykonywanych w nawigacji, nazw ról oraz marketplace queries;
- endpointów OAuth/tools i ich wildcardów bez analizy własnego sklepu;
- `EditPart::afterSave()` oraz synchronizacji cen/listingów;
- ręcznych styli w `<style>` listingu (lepiej zbudować theme/assets pipeline);
- opcji „Wszystkie” bez limitu dla dużych tabel;
- założenia, że każdy customowy link bez `wire:navigate` będzie automatycznie SPA poza panelem Filament.

## 15. Minimalny przykład architektury dla nowego sklepu

```php
// app/Providers/Filament/AdminPanelProvider.php
public function panel(Panel $panel): Panel
{
    return $panel
        ->id('admin')
        ->path('admin')
        ->login()
        ->spa()
        ->spaUrlExceptions([
            url('/admin/logout'),
            url('/admin/oauth/*'),
            url('/admin/exports/*'),
        ])
        ->discoverResources(
            in: app_path('Filament/Resources'),
            for: 'App\\Filament\\Resources',
        );
}
```

```php
final class ListProducts extends Page
{
    use WithPagination;

    protected static string $view = 'filament.resources.products.pages.list-products';

    #[Url(as: 'search')]
    public string $search = '';

    #[Url(as: 'sort')]
    public string $sort = 'updated_desc';

    public function updating(string $property): void
    {
        if ($property !== 'page') {
            $this->resetPage();
        }
    }

    public function getProductsProperty(): LengthAwarePaginator
    {
        return Product::query()
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->when($this->sort === 'updated_desc', fn ($q) => $q->latest('updated_at'))
            ->paginate(24)
            ->withQueryString();
    }
}
```

```blade
<x-filament-panels::page>
    <input type="search" wire:model.live.debounce.400ms="search">
    <select wire:model.live="sort">…</select>

    @foreach ($this->products as $product)
        <article wire:key="product-{{ $product->id }}">
            <a href="{{ ProductResource::getUrl('edit', ['record' => $product]) }}" wire:navigate>
                {{ $product->name }}
            </a>
        </article>
    @endforeach

    {{ $this->products->links() }}
</x-filament-panels::page>
```

Dla większości nowych sklepów rekomendowany start to jednak standardowy Filament Table; custom cards należy wprowadzać dopiero, gdy UX rzeczywiście tego wymaga.

## 16. Ryzyka i pułapki

1. **Brak lockfile:** nie da się odtworzyć ani audytować dokładnego zachowania wersji. Dodać i utrzymywać `composer.lock` w aplikacji wdrażanej.
2. **Lifecycle SPA:** `DOMContentLoaded` uruchamia się tylko raz. Custom JS musi reagować na `livewire:navigated` i unikać podwójnych listenerów.
3. **Nieutrwalony Alpine state:** `filtersOpen` i otwarte modale znikają po remount/nawigacji; to poprawne tylko, jeśli jest decyzją UX.
4. **Back/forward:** świadomie wybierać `#[Url(history: true)]` dla tabów/filtrów, gdzie każda zmiana ma być krokiem historii. Inaczej Back może nie cofać kolejnych filtrów.
5. **Scroll:** ustalić wymaganie i dodać testy; nie polegać na domyślnym zachowaniu bez sprawdzenia konkretnej wersji Livewire.
6. **Wielkie query:** liczniki menu wykonują `COUNT` przy budowaniu nawigacji; cachować lub optymalizować przy dużych tabelach. Opcja `all` może zużyć dużo pamięci i wygenerować ogromny DOM.
7. **Race conditions:** live search powinien mieć debounce; nadal należy testować wolne odpowiedzi i szybkie zmiany stanu.
8. **Query string:** walidować/normalizować wartości pochodzące z URL; `normalizedPerPage()` robi to dla limitu, ale analogiczną ochronę warto stosować szerzej.
9. **Modale i transient DOM:** zamykać przy `livewire:navigating`; cleanup timerów, AbortController i globalnych listenerów jest konieczny.
10. **CSRF/session:** requesty Livewire mogą dostać 419 bez klasycznego redirectu; GPSwiss ma dedykowany hook i refresh tokenu.
11. **Prefetch i semantyka GET:** każdy GET musi pozostać bezpieczny/read-only. Write endpoints muszą używać POST/PATCH/DELETE i być wyłączone z podejrzanych ścieżek navigate.
12. **Form dirty state:** przed nawigacją z niezapisanej edycji potrzebny może być guard; nie znaleziono customowego guardu w GPSwiss.
13. **Powrót do listy:** warto przenosić `returnUrl` albo przechowywać stan listy, jeżeli Back nie jest wystarczającym kontraktem UX.
14. **Dostępność:** link powinien nadal mieć `href`, taby powinny mieć właściwe role/ARIA, a focus po morph/navigate wymaga testów.

## 17. Rekomendacje

1. Zachować architekturę Filament SPA + Livewire; nie dodawać Turbo/PJAX ani drugiego routera.
2. Dla nowego sklepu przypiąć dokładne wersje przez `composer.lock` i udokumentować wynik `composer show filament/filament laravel/framework livewire/livewire`.
3. Napisać test E2E (Playwright/Cypress) mierzący `performance.getEntriesByType('navigation')`, Network, zachowanie URL, back/forward, focus i scroll dla: menu → lista → filtr → strona 2 → edycja → Back.
4. Rozważyć `history: true` tylko dla tych filtrów/tabów, których zmiany użytkownik oczekuje cofać przyciskiem Back; obecny `ShopEvents` pokazuje taki świadomy wzorzec.
5. Dodać jawny mechanizm powrotu (`returnUrl`) dla edycji, jeśli zachowanie filtrów/scrolla jest wymaganiem biznesowym.
6. Ograniczyć lub usunąć „Wszystkie” przy dużych zbiorach; użyć wirtualizacji/większych stron/eksportu.
7. Przenieść inline CSS kart do wersjonowanego theme/admin CSS w nowym projekcie.
8. Zachować wyjątki SPA i zasadę bezpiecznych GET, ale tworzyć listę na podstawie rzeczywistych tras nowego sklepu.
9. Przed skopiowaniem topbara poprawić lifecycle wyszukiwarki: singleton skryptu powoduje, że jego początkowe referencje DOM mogą wymagać szczegółowego testu po wymianie head/body. **Wniosek z kodu:** listener `livewire:navigated` synchronizuje sidebar, ale cały moduł topbar/search nie wykonuje pełnego ponownego bindu po każdym navigate.
10. Nie przenosić żadnych domenowych hooków marketplace razem z mechanizmem nawigacji.

## Rejestr bezpieczeństwa audytu

- **Czy zmieniano kod aplikacji:** nie.
- **Zmienione pliki:** wyłącznie ten raport dokumentacyjny: `docs/audyt-nawigacji-panelu-admina-gpswiss.md`.
- **Czy wykonano jakiekolwiek requesty do marketplace:** nie.
- **Czy wykonano jakikolwiek write lokalny:** tak — utworzono wyłącznie plik raportu i commit Git wymagany dla dostarczenia audytu; nie modyfikowano danych aplikacji, konfiguracji ani `.env`.
- **Czy wykonano jakikolwiek marketplace write:** nie.
- **Uruchomienie aplikacji / requesty HTTP:** nie.

