@extends('layouts.storefront')

@section('content')
<div class="sf-container sf-page sf-static-page">
    @include('storefront.partials.breadcrumbs')
    <section class="sf-static-card">
        <h1>REGULAMIN SKLEPU INTERNETOWEGO</h1>

        <h2>1. Postanowienia ogólne</h2>
        <p>Niniejszy Regulamin określa zasady korzystania ze sklepu internetowego dostępnego pod adresem <a href="https://gpswiss.pl">https://gpswiss.pl</a>, zwanego dalej „Sklepem”.</p>
        <p>Właścicielem oraz administratorem Sklepu jest:</p>
        <p><strong>GREGOR swiss GRZEGORZ PACIOREK</strong><br>
            ul. Milanowska 137<br>
            08-460 Sobolew<br>
            NIP: 8262157853<br>
            REGON: 368948917<br>
            e-mail: <a href="mailto:biuro@gpswiss.pl">biuro@gpswiss.pl</a></p>
        <p>Sklep prowadzi sprzedaż części samochodowych oraz akcesoriów.</p>
        <p>Każdy użytkownik zobowiązany jest do zapoznania się z niniejszym Regulaminem przed dokonaniem zakupu.</p>

        <h2>2. Definicje</h2>
        <p><strong>Klient</strong> – osoba fizyczna, osoba prawna lub jednostka organizacyjna dokonująca zakupu w Sklepie.</p>
        <p><strong>Konsument</strong> – osoba fizyczna dokonująca zakupu niezwiązanego bezpośrednio z jej działalnością gospodarczą lub zawodową.</p>
        <p><strong>Produkt</strong> – towar dostępny w ofercie Sklepu.</p>
        <p><strong>Sprzedawca</strong> – GREGOR swiss GRZEGORZ PACIOREK.</p>
        <p><strong>Umowa sprzedaży</strong> – umowa zawarta pomiędzy Sprzedawcą a Klientem za pośrednictwem Sklepu.</p>

        <h2>3. Składanie zamówień</h2>
        <p>Zamówienia można składać przez stronę internetową Sklepu.</p>
        <p>W celu złożenia zamówienia Klient powinien:</p>
        <ul>
            <li>wybrać Produkt,</li>
            <li>dodać Produkt do koszyka,</li>
            <li>uzupełnić wymagane dane,</li>
            <li>wybrać formę dostawy i płatności,</li>
            <li>potwierdzić zamówienie.</li>
        </ul>
        <p>Złożenie zamówienia oznacza zawarcie umowy sprzedaży.</p>
        <p>Po złożeniu zamówienia Klient otrzymuje potwierdzenie na podany adres e-mail.</p>

        <h2>4. Ceny i płatności</h2>
        <p>Wszystkie ceny podane w Sklepie są cenami brutto i zawierają podatek VAT, o ile przy danym Produkcie nie wskazano inaczej.</p>
        <p>Dostępne formy płatności:</p>
        <ul>
            <li>płatności online poprzez operatora PayU,</li>
            <li>przelew tradycyjny, jeżeli jest dostępny,</li>
            <li>inne formy płatności widoczne podczas składania zamówienia.</li>
        </ul>
        <p>Realizacja zamówienia rozpoczyna się po zaksięgowaniu płatności lub po potwierdzeniu zamówienia, jeżeli wybrana forma płatności na to pozwala.</p>

        <h2>5. Dostawa</h2>
        <p>Dostawa realizowana jest za pośrednictwem firm kurierskich lub innych form dostawy dostępnych w Sklepie.</p>
        <p>Koszt dostawy jest wskazany podczas składania zamówienia.</p>
        <p>Czas realizacji zamówienia zależy od dostępności Produktu i wynosi zazwyczaj od 1 do kilku dni roboczych.</p>

        <h2>6. Zwroty</h2>
        <p>Klient będący konsumentem ma prawo odstąpić od umowy w terminie 14 dni od momentu otrzymania przesyłki.</p>
        <p>Aby zwrot został przyjęty, Produkt powinien spełniać następujące warunki:</p>
        <ul>
            <li>być w stanie niezmienionym, bez śladów użytkowania lub montażu,</li>
            <li>być kompletny,</li>
            <li>posiadać nienaruszone plomby zabezpieczające, jeżeli były zastosowane,</li>
            <li>być odpowiednio zabezpieczony na czas transportu,</li>
            <li>zawierać dowód zakupu, np. paragon lub fakturę.</li>
        </ul>
        <p>Koszt odesłania towaru ponosi Klient i nie podlega on zwrotowi, chyba że obowiązujące przepisy stanowią inaczej.</p>
        <p>Zwrot środków realizowany jest po otrzymaniu i weryfikacji towaru.</p>
        <p>Po upływie 14 dni zwrot nie przysługuje. W wyjątkowych sytuacjach możliwa jest odpłatna wymiana wyłącznie po wcześniejszym uzgodnieniu ze Sprzedawcą.</p>

        <h2>7. Wymiana towaru</h2>
        <p>Istnieje możliwość wymiany zakupionych części w terminie 14 dni od daty odbioru przesyłki. Dotyczy to zarówno klientów indywidualnych, jak i firm.</p>
        <p>Warunki wymiany:</p>
        <ul>
            <li>Produkt musi być kompletny i nieuszkodzony,</li>
            <li>Produkt nie może nosić śladów montażu ani użytkowania,</li>
            <li>Produkt musi posiadać nienaruszone plomby, jeżeli były zastosowane,</li>
            <li>przesyłka musi być odpowiednio zabezpieczona.</li>
        </ul>
        <p>Wszystkie koszty związane z wymianą pokrywa Klient, w tym:</p>
        <ul>
            <li>koszt odesłania towaru do Sklepu,</li>
            <li>koszt ponownej wysyłki nowej części.</li>
        </ul>
        <p>Realizacja wymiany uzależniona jest od dostępności Produktu.</p>
        <p>W przypadku braku towaru Klient zostanie poinformowany, a wpłacone środki zostaną zwrócone na wskazane konto.</p>

        <h2>8. Reklamacje i gwarancja</h2>
        <p>Produkty oferowane w Sklepie objęte są gwarancją Sprzedawcy.</p>
        <p>Okres gwarancji jest określony indywidualnie dla każdego Produktu i dostępny na jego stronie.</p>
        <p>Klient ma prawo złożyć reklamację w przypadku:</p>
        <ul>
            <li>wady Produktu,</li>
            <li>niezgodności towaru z umową.</li>
        </ul>
        <p>Reklamacje oraz wszelkie informacje dotyczące ich przebiegu obsługiwane są drogą mailową:</p>
        <p><a href="mailto:biuro@gpswiss.pl">biuro@gpswiss.pl</a></p>
        <p>W przypadku uznania reklamacji:</p>
        <ul>
            <li>Produkt zostanie naprawiony lub wymieniony na sprawny,</li>
            <li>a jeżeli nie będzie to możliwe, nastąpi zwrot środków.</li>
        </ul>
        <p>W zakresie dopuszczalnym przez przepisy prawa rękojmia wobec przedsiębiorców może zostać ograniczona lub wyłączona.</p>

        <h2>9. Uszkodzenia w transporcie</h2>
        <p>Przy odbiorze przesyłki zaleca się sprawdzenie jej stanu w obecności kuriera.</p>
        <p>W przypadku zauważenia uszkodzeń należy:</p>
        <ul>
            <li>sporządzić protokół szkody razem z kurierem,</li>
            <li>niezwłocznie poinformować Sklep.</li>
        </ul>
        <p>Reklamowany towar należy odesłać wraz z:</p>
        <ul>
            <li>dowodem zakupu,</li>
            <li>protokołem szkody, jeżeli został sporządzony,</li>
            <li>krótkim opisem problemu.</li>
        </ul>
        <p>Brak protokołu szkody może znacząco utrudnić rozpatrzenie reklamacji dotyczącej uszkodzenia w transporcie.</p>

        <h2>10. Konto użytkownika</h2>
        <p>Klient może założyć konto w Sklepie, jeżeli taka funkcja jest dostępna.</p>
        <p>Konto umożliwia w szczególności:</p>
        <ul>
            <li>śledzenie zamówień,</li>
            <li>dostęp do historii zakupów,</li>
            <li>łatwiejsze składanie kolejnych zamówień.</li>
        </ul>
        <p>Klient zobowiązany jest do podawania prawdziwych i aktualnych danych.</p>

        <h2>11. Odpowiedzialność</h2>
        <p>Sklep nie ponosi odpowiedzialności za:</p>
        <ul>
            <li>nieprawidłowy montaż części,</li>
            <li>błędny dobór części przez Klienta,</li>
            <li>użycie Produktu niezgodnie z jego przeznaczeniem.</li>
        </ul>
        <p>Oferowane części przeznaczone są do montażu w profesjonalnych warsztatach.</p>
        <p>Sklep nie przyjmuje przesyłek wysłanych za pobraniem, chyba że Sprzedawca wcześniej wyraził na to zgodę.</p>

        <h2>12. Ochrona danych osobowych</h2>
        <p>Dane osobowe Klientów przetwarzane są zgodnie z Polityką Prywatności dostępną pod adresem:</p>
        <p><a href="{{ route('storefront.privacy-policy') }}">https://gpswiss.pl/polityka-prywatnosci</a></p>

        <h2>13. Postanowienia końcowe</h2>
        <p>Regulamin może ulec zmianie.</p>
        <p>Do umów zawartych za pośrednictwem Sklepu stosuje się prawo polskie.</p>
        <p>Spory rozstrzygane będą przez właściwe sądy zgodnie z obowiązującymi przepisami prawa.</p>
    </section>
</div>
@endsection
