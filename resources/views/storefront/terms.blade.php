@extends('layouts.storefront')

@section('content')
<div class="sf-container sf-page sf-static-page">
    @include('storefront.partials.breadcrumbs')

    <article class="sf-static-card sf-terms">
        <h1>REGULAMIN SKLEPU INTERNETOWEGO</h1>

        <h2>1. Postanowienia ogólne</h2>
        <p>Niniejszy Regulamin określa zasady korzystania ze sklepu internetowego dostępnego pod adresem internetowym sklepu (dalej: „Sklep”).</p>
        <p>Właścicielem oraz administratorem Sklepu jest:</p>
        <p>GREGOR swiss GRZEGORZ PACIOREK<br>ul. Milanowska 137<br>08-460 Sobolew<br>NIP: 8262157853<br>REGON: 368948917<br>e-mail: <a href="mailto:biuro@gpswiss.pl">biuro@gpswiss.pl</a></p>
        <p>Sklep prowadzi sprzedaż części samochodowych oraz akcesoriów.</p>
        <p>Każdy użytkownik zobowiązany jest do zapoznania się z niniejszym Regulaminem przed dokonaniem zakupu.</p>

        <h2>2. Definicje</h2>
        <p>Klient – osoba fizyczna, prawna lub jednostka organizacyjna dokonująca zakupu w Sklepie</p>
        <p>Konsument – osoba fizyczna dokonująca zakupu niezwiązanego z działalnością gospodarczą</p>
        <p>Produkt – towar dostępny w ofercie Sklepu</p>
        <p>Umowa sprzedaży – umowa zawarta pomiędzy Sprzedawcą a Klientem</p>

        <h2>3. Składanie zamówień</h2>
        <p>Zamówienia można składać:</p>
        <ul><li>przez stronę internetową Sklepu</li></ul>
        <p>W celu złożenia zamówienia należy:</p>
        <ul><li>wybrać produkt</li><li>dodać go do koszyka</li><li>uzupełnić dane</li><li>wybrać formę dostawy i płatności</li><li>potwierdzić zamówienie</li></ul>
        <p>Złożenie zamówienia oznacza zawarcie umowy sprzedaży.</p>
        <p>Po złożeniu zamówienia Klient otrzymuje potwierdzenie na adres e-mail.</p>

        <h2>4. Ceny i płatności</h2>
        <p>Wszystkie ceny podane w Sklepie są cenami brutto (zawierają podatek VAT).</p>
        <p>Dostępne formy płatności:</p>
        <ul><li>płatności online poprzez operatora PayU</li><li>przelew tradycyjny (jeśli dostępny)</li></ul>
        <p>Realizacja zamówienia rozpoczyna się po zaksięgowaniu płatności.</p>

        <h2>5. Dostawa</h2>
        <p>Dostawa realizowana jest za pośrednictwem firm kurierskich.</p>
        <p>Koszt dostawy jest wskazany podczas składania zamówienia.</p>
        <p>Czas realizacji zamówienia zależy od dostępności produktu i wynosi zazwyczaj od 1 do kilku dni roboczych.</p>

        <h2>6. Zwroty</h2>
        <p>Konsument ma prawo odstąpić od umowy w terminie 14 dni od dnia otrzymania towaru.</p>
        <p>Zwrot jest możliwy pod warunkiem, że:</p>
        <ul><li>produkt nie był używany ani montowany</li><li>jest kompletny</li><li>posiada nienaruszone plomby</li><li>jest odpowiednio zabezpieczony</li></ul>
        <p>Koszt zwrotu ponosi Klient.</p>
        <p>Zwrot środków następuje po otrzymaniu i weryfikacji towaru.</p>
        <p>Po upływie 14 dni zwrot nie przysługuje.</p>

        <h2>7. Wymiana towaru</h2>
        <p>Wymiana produktu możliwa jest w terminie 14 dni od otrzymania przesyłki.</p>
        <p>Dotyczy zarówno konsumentów, jak i przedsiębiorców.</p>
        <p>Warunki jak przy zwrocie.</p>
        <p>Koszty wymiany ponosi Klient (zwrot + ponowna wysyłka).</p>
        <p>Wymiana uzależniona jest od dostępności produktu.</p>

        <h2>8. Reklamacje i gwarancja</h2>
        <p>Produkty oferowane w Sklepie objęte są gwarancją sprzedawcy.</p>
        <p>Okres gwarancji określony jest w opisie produktu.</p>
        <p>Klient ma prawo złożyć reklamację w przypadku:</p>
        <ul><li>wady produktu</li><li>niezgodności towaru z umową</li></ul>
        <p>Reklamacje należy zgłaszać drogą mailową:</p>
        <p><a href="mailto:biuro@gpswiss.pl">biuro@gpswiss.pl</a></p>
        <p>W przypadku uznania reklamacji:</p>
        <ul><li>produkt zostanie naprawiony lub wymieniony</li><li>lub nastąpi zwrot środków</li></ul>
        <p>W stosunku do przedsiębiorców rękojmia może zostać wyłączona w zakresie dopuszczalnym przez prawo.</p>

        <h2>9. Uszkodzenia w transporcie</h2>
        <p>Przy odbiorze przesyłki zaleca się sprawdzenie jej w obecności kuriera.</p>
        <p>W przypadku uszkodzenia należy sporządzić protokół szkody.</p>
        <p>Brak protokołu może utrudnić proces reklamacji.</p>

        <h2>10. Konto użytkownika</h2>
        <p>Klient może założyć konto w Sklepie.</p>
        <p>Konto umożliwia:</p>
        <ul><li>śledzenie zamówień</li><li>dostęp do historii zakupów</li></ul>
        <p>Klient zobowiązany jest do podawania prawdziwych danych.</p>

        <h2>11. Odpowiedzialność</h2>
        <p>Sklep nie ponosi odpowiedzialności za:</p>
        <ul><li>nieprawidłowy montaż części</li><li>błędny dobór części przez Klienta</li></ul>
        <p>Produkty powinny być montowane przez wykwalifikowane warsztaty.</p>

        <h2>12. Ochrona danych osobowych</h2>
        <p>Dane osobowe przetwarzane są zgodnie z Polityką Prywatności.</p>
        <p>Szczegółowe informacje znajdują się w odrębnym dokumencie.</p>

        <h2>13. Postanowienia końcowe</h2>
        <p>Regulamin może ulec zmianie.</p>
        <p>Do umów zawartych stosuje się prawo polskie.</p>
        <p>Spory rozstrzygane będą przez właściwe sądy.</p>
    </article>
</div>
@endsection
