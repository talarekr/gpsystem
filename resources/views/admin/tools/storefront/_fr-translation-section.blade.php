    <div id="{{ $prefix }}action-message" class="messages" role="status" aria-live="polite" aria-atomic="true" style="position:sticky;top:12px;z-index:2;background:#fff;border-radius:8px"></div>

    <section class="card" aria-labelledby="{{ $prefix }}setup-title">
        <h2 id="{{ $prefix }}setup-title">Gotowość narzędzia</h2>
        <div class="row">
            <span class="badge {{ ($overview['enabled'] ?? false) ? 'good' : 'bad' }}">Tłumaczenia FR: {{ ($overview['enabled'] ?? false) ? 'włączone' : 'wyłączone' }}</span>
            <span class="badge {{ ($overview['provider_ready'] ?? false) ? 'good' : 'bad' }}">Google Translate: {{ ($overview['provider_ready'] ?? false) ? 'gotowy' : 'niegotowy' }}</span>
            <span class="badge {{ ($overview['schema_ready'] ?? false) ? 'good' : 'bad' }}">Tabele tłumaczeń: {{ ($overview['schema_ready'] ?? false) ? 'gotowe' : 'brak migracji' }}</span>
        </div>
        @if (! ($overview['enabled'] ?? false))
            <p>Przed tłumaczeniem administrator musi ustawić <code>GPSWISS_FR_TRANSLATIONS_ENABLED=true</code> (<code>storefront-translations.fr_enabled</code>) i odświeżyć konfigurację aplikacji.</p>
        @endif
        @if (! ($overview['provider_ready'] ?? false))
            <p>Przed tłumaczeniem skonfiguruj Google Translate: API {{ data_get($overview, 'provider.api_enabled') ? 'włączone' : 'wyłączone' }}, klucz {{ data_get($overview, 'provider.api_key_configured') ? 'skonfigurowany' : 'nieskonfigurowany' }}, tryb {{ data_get($overview, 'provider.api_mode') === 'dry_run' ? 'dry_run' : 'poza dry_run' }}. Wartość klucza nie jest pokazywana.</p>
        @endif
        @if (! ($overview['schema_ready'] ?? false))
            <p>Brakuje tabel tłumaczeń. Administrator musi uruchomić migracje przed dry-run lub tłumaczeniem.</p>
        @endif
        <p class="muted">Wejście na tę stronę i dry-run nie wywołują Google API. Po zmianie konfiguracji <a href="{{ route('admin.tools.storefront.fr-translations.index') }}">odśwież stronę</a>.</p>
    </section>

    <section class="card" aria-labelledby="{{ $prefix }}catalog-title">
        <h2 id="{{ $prefix }}catalog-title">Katalog — zakres tłumaczeń</h2>
        <p class="muted" id="{{ $prefix }}catalog-snapshot">Stan z chwili otwarcia strony. Dry-run odświeży te liczniki.</p>
        <div class="table-wrap">
            <table>
                <caption class="sr-only">Liczba produktów, kategorii, pól i znaków do tłumaczenia</caption>
                <thead><tr><th scope="col">Typ</th><th scope="col">Łącznie (total)</th><th scope="col">Do tłumaczenia (eligible)</th><th scope="col">Pola (fields)</th><th scope="col">Szacowane znaki</th><th scope="col">Pomijane</th></tr></thead>
                <tbody>
                    @foreach ($kinds as $kind => $label)
                        <tr><th scope="row">{{ $label }}</th>@foreach (['total', 'eligible', 'fields', 'estimated_characters', 'skipped'] as $key)<td id="{{ $prefix }}catalog-{{ $kind }}-{{ $key }}">{{ data_get($overview, 'catalog.'.$kind.'.'.$key, 0) }}</td>@endforeach</tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <h3>Status zapisanych tłumaczeń</h3>
        <div class="grid">
            @foreach (['translated' => 'Przetłumaczone / reviewed', 'missing' => 'Brakujące (missing)', 'needs_update' => 'Do aktualizacji', 'failed' => 'Błędy (failed)', 'skipped' => 'Pomijane w tym zakresie', 'queued' => 'Oczekujące'] as $key => $label)
                <div class="metric"><span>{{ $label }}</span><strong id="{{ $prefix }}inventory-{{ $key }}">{{ data_get($overview, 'catalog.inventory.'.$key, 0) }}</strong></div>
            @endforeach
        </div>
        <p>Szacowana liczba znaków do tłumaczenia: <strong id="{{ $prefix }}catalog-characters">{{ data_get($overview, 'catalog.estimated_characters', 0) }}</strong>.</p>
    </section>

    <section class="card notice" aria-labelledby="{{ $prefix }}dry-run-title">
        <div class="row between">
            <h2 id="{{ $prefix }}dry-run-title">1. Sprawdź / Dry-run</h2>
            <button id="{{ $prefix }}dry-run" class="button primary" type="button">Sprawdź / Dry-run</button>
        </div>
        <p>Dry-run sprawdza cały katalog bez Google API. Dry-run nie zapisuje tłumaczeń ani katalogu; zapisywany jest tylko wynik podglądu, potrzebny do potwierdzenia startu.</p>
        <p id="{{ $prefix }}dry-run-state" class="muted">Nie wykonano jeszcze dry-run.</p>
        <div id="{{ $prefix }}dry-run-result" hidden>
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Ostatni wynik dry-run</caption>
                    <thead><tr><th scope="col">Typ</th><th scope="col">Total</th><th scope="col">Eligible</th><th scope="col">Fields</th><th scope="col">Estimated characters</th><th scope="col">Skipped</th></tr></thead>
                    <tbody id="{{ $prefix }}dry-run-counts"></tbody>
                </table>
            </div>
            <details><summary>Powody pominięcia</summary><pre id="{{ $prefix }}skip-reasons"></pre></details>
            <details><summary>Przykładowe rekordy</summary><pre id="{{ $prefix }}record-examples"></pre></details>
        </div>
    </section>

    <section class="card warning" aria-labelledby="{{ $prefix }}start-title">
        <h2 id="{{ $prefix }}start-title">2. {{ $startLabel }}</h2>
        <p><strong>Tłumaczenie wywołuje Google API i może generować koszt.</strong> {{ $scopeDescription }}, tylko brakujące tłumaczenia, po jednym rekordzie na request w trybie panelowym. Aktualne oraz reviewed są chronione. Rekordy failed i needs_update są pomijane.</p>
        <p>Start wymaga nowego dry-run z ostatniej godziny, włączonej flagi i gotowego providera. Jeden raport można wykorzystać do startu tylko raz.</p>
        <form id="{{ $prefix }}start-form">
            <label for="{{ $prefix }}confirm-token">Wpisz dokładnie <code>{{ $confirmToken }}</code></label>
            <input id="{{ $prefix }}confirm-token" name="confirm" type="text" autocomplete="off" spellcheck="false" autocapitalize="off" aria-describedby="{{ $prefix }}start-blocked" placeholder="{{ $confirmToken }}">
            <div><button id="{{ $prefix }}start" class="button primary" type="submit" disabled>{{ $startLabel }}</button></div>
            <p id="{{ $prefix }}start-blocked" class="muted">Wykonaj dry-run i wpisz token potwierdzenia.</p>
        </form>
    </section>

    <section class="card"><h3>Ponowienie błędów kodów technicznych</h3><p>Wznów zachowuje run_id, główny kursor i processed. Dla Jarka jednorazowo ponawia technical_code_changed z tego runu po poprawce tokenów, bez ponawiania translated/reviewed. Błąd pojedynczego rekordu nie zatrzymuje pozostałych; błędy providera lub konfiguracji nadal wymagają kontroli.</p><div class="grid">
    @foreach (['technical_retry_total' => 'Retry total', 'technical_retry_processed' => 'Retry processed', 'technical_retry_remaining' => 'Retry remaining', 'technical_retry_translated' => 'Retry translated', 'technical_retry_failed' => 'Retry failed', 'technical_retry_skipped' => 'Retry skipped'] as $key => $label)
        <div class="metric"><span>{{ $label }}</span><strong id="{{ $prefix }}run-{{ $key }}">{{ $status[$key] ?? 0 }}</strong></div>
    @endforeach
    </div></section>

    <section class="card" aria-labelledby="{{ $prefix }}run-title">
        <div class="row between">
            <h2 id="{{ $prefix }}run-title">3. Postęp / ostatni wynik apply <span id="{{ $prefix }}run-status" class="badge">idle</span></h2>
            <button id="{{ $prefix }}refresh" class="button" type="button">Odśwież status</button>
        </div>
        <p>Domyślny tryb panelowy nie wymaga workera ani shella. Start lub Wznów uruchamia kolejne małe partie przez AJAX. <strong>Zostaw stronę otwartą.</strong> Po zamknięciu nie są wysyłane kolejne partie; rozpoczęty rekord może jeszcze zakończyć się na serwerze.</p>
        <p>Po powrocie odśwież status i kliknij <strong>Wznów</strong> dla istniejącego runu. Nie klikaj ponownie Start. Pauza wstrzymuje wykonanie, Stop kończy run.</p>
        <p id="{{ $prefix }}lease-state" role="status" class="muted">Odczyt lease…</p>
        <pre id="{{ $prefix }}lease-details">—</pre>
        <p id="{{ $prefix }}recovery-state" role="status" class="muted">Odczyt trwałego checkpointu…</p>
        <p id="{{ $prefix }}worker-state" role="status" class="muted">Tryb wykonania jest wskazany w statusie.</p>
        <label for="{{ $prefix }}run-progress" class="sr-only">Postęp tłumaczenia</label>
        <progress id="{{ $prefix }}run-progress" class="progress" max="100" value="0">0%</progress>
        <p id="{{ $prefix }}progress-text">Brak uruchomionego zadania.</p>
        <div class="grid">
            @foreach (['total' => 'Łącznie (total)', 'processed' => 'Przetworzone', 'translated' => 'Przetłumaczone', 'skipped' => 'Pomijane', 'failed' => 'Błędy', 'remaining' => 'Pozostałe', 'chars_translated' => 'Przetłumaczone znaki'] as $key => $label)
                <div class="metric"><span>{{ $label }}</span><strong id="{{ $prefix }}run-{{ $key }}">0</strong></div>
            @endforeach
        </div>
        <dl class="status-grid">
            @foreach (['run_id' => 'run_id', 'runner_mode' => 'Tryb wykonania', 'checkpoint_source' => 'Źródło checkpointu', 'started_at' => 'Rozpoczęto', 'updated_at' => 'Ostatnia aktualizacja zadania', 'current_type' => 'Aktualny typ', 'current_id' => 'Aktualny rekord', 'next_batch' => 'Następna partia', 'active_timer' => 'Timer w przeglądarce', 'last_dispatch_at' => 'Ostatni dispatch', 'waiting_for_worker' => 'Partia zlecona / oczekuje na worker', 'recovery_available' => 'Dostępne wznowienie istniejącego runu', 'runner_hint' => 'Runner / recovery'] as $key => $label)
                <div><dt>{{ $label }}</dt><dd id="{{ $prefix }}run-{{ $key }}">—</dd></div>
            @endforeach
        </dl>
        <p id="{{ $prefix }}poll-state" class="muted" role="status">Status wczytany ze strony.</p>
        <div class="row">
            <button id="{{ $prefix }}pause" class="button" type="button" disabled>Pauza</button>
            <button id="{{ $prefix }}resume" class="button primary" type="button" disabled>Wznów</button>
            <button id="{{ $prefix }}stop" class="button danger" type="button" disabled>Stop</button>
        </div>
        <p class="muted">Pauza i Stop obowiązują od najbliższego bezpiecznego punktu. Wznów kontynuuje istniejące zadanie; przy statusie running może odzyskać jego wykonanie. Stop kończy zadanie.</p>
        <h3>Ostatni sukces</h3><pre id="{{ $prefix }}last-success">Brak.</pre>
        <h3>Ostatni błąd</h3><pre id="{{ $prefix }}last-error">Brak.</pre>
        <details><summary>Przykłady błędów (ID, SKU, kod, bezpieczny opis)</summary><pre id="{{ $prefix }}failed-examples">Brak.</pre></details>
    </section>
    <noscript><p class="card warning">Do dry-run i sterowania zadaniem potrzebny jest JavaScript. Włącz go i odśwież stronę.</p></noscript>
