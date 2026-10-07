<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>GPSwiss FR — tłumaczenia katalogu</title>
    <style>
        :root{color-scheme:light}*{box-sizing:border-box}body{margin:0;background:#f4f6f9;color:#18283f;font:15px/1.5 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}main{max-width:1240px;margin:auto;padding:28px 20px 48px}a{color:#285b94}h1{font-size:clamp(24px,4vw,32px);margin:14px 0 8px}h2{font-size:20px;margin:0 0 14px}h3{font-size:16px;margin:18px 0 10px}p{margin:8px 0 14px}.muted{color:#526278}.card{background:#fff;border:1px solid #dce3ec;border-radius:14px;padding:22px;margin-top:18px}.notice{background:#edf4ff;border-color:#bdcfe8}.warning{background:#fff8e7;border-color:#efd087}.row{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.between{justify-content:space-between}.badge{font-size:13px;font-weight:700;padding:5px 10px;border-radius:20px;background:#e9edf2;display:inline-block}.good{background:#e2f3e8;color:#17603a}.bad{background:#fce7e7;color:#9c2929}.pending{background:#e7effd;color:#285b94}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:10px}.metric{background:#f7f9fc;border:1px solid #e4e9f1;border-radius:10px;padding:12px}.metric span{display:block;font-size:13px;color:#526278}.metric strong{display:block;font-size:23px;margin-top:4px;overflow-wrap:anywhere}.button{display:inline-block;border:1px solid transparent;border-radius:8px;padding:10px 15px;font:600 14px/1.4 system-ui;cursor:pointer;background:#e8edf4;color:#203650}.primary{background:#244d7f;color:#fff}.danger{background:#a82c32;color:#fff}button:disabled{opacity:.5;cursor:not-allowed}button:focus-visible,input:focus-visible,a:focus-visible,summary:focus-visible{outline:3px solid #739fe0;outline-offset:3px}input{font:inherit;width:min(100%,450px);border:1px solid #9babc0;border-radius:8px;padding:10px 12px;margin:8px 0 12px}label{display:block;font-weight:600}.mono,code{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;overflow-wrap:anywhere}code{font-size:.9em;background:#eef1f5;padding:2px 4px;border-radius:3px}.table-wrap{overflow-x:auto}table{border-collapse:collapse;width:100%;font-size:14px}th,td{border-bottom:1px solid #e4e9f1;text-align:left;padding:9px 12px;vertical-align:top}th{color:#526278;font-weight:600}td:first-child,th:first-child{padding-left:0}details{margin-top:16px}summary{cursor:pointer;font-weight:600}pre{white-space:pre-wrap;overflow-wrap:anywhere;max-height:320px;overflow:auto;font:13px/1.6 ui-monospace,SFMono-Regular,Consolas,monospace;background:#f4f6fa;border-radius:8px;padding:14px}.progress{width:100%;height:18px;accent-color:#244d7f}.status-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-top:16px}.status-grid dt{font-size:13px;color:#526278}.status-grid dd{margin:4px 0 0;font-weight:600;overflow-wrap:anywhere}.messages{min-height:24px;margin-top:16px}.messages.error{color:#a1252e}.messages.success{color:#17603a}.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}[hidden]{display:none!important}@media(max-width:600px){main{padding:18px 12px 32px}.card{padding:16px}.grid{grid-template-columns:repeat(2,minmax(0,1fr))}input{font-size:13px}.row>.button{flex-grow:1}}
    </style>
</head>
<body>
<main>
    <a href="{{ url('/admin') }}">← Wróć do panelu admina</a>
    <div class="row between">
        <h1>GPSwiss FR — tłumaczenia katalogu</h1>
        <span class="badge">Uruchamianie ręczne</span>
    </div>
    <p class="muted">Sprawdź zakres tłumaczeń, a następnie uruchom zadanie i obserwuj postęp.</p>
    <div id="action-message" class="messages" role="status" aria-live="polite" aria-atomic="true" style="position:sticky;top:12px;z-index:2;background:#fff;border-radius:8px"></div>

    <section class="card" aria-labelledby="setup-title">
        <h2 id="setup-title">Gotowość narzędzia</h2>
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

    <section class="card" aria-labelledby="catalog-title">
        <h2 id="catalog-title">Katalog — zakres tłumaczeń</h2>
        <p class="muted" id="catalog-snapshot">Stan z chwili otwarcia strony. Dry-run odświeży te liczniki.</p>
        <div class="table-wrap">
            <table>
                <caption class="sr-only">Liczba produktów, kategorii, pól i znaków do tłumaczenia</caption>
                <thead><tr><th scope="col">Typ</th><th scope="col">Łącznie (total)</th><th scope="col">Do tłumaczenia (eligible)</th><th scope="col">Pola (fields)</th><th scope="col">Szacowane znaki</th><th scope="col">Pomijane</th></tr></thead>
                <tbody>
                    @foreach (['products' => 'Produkty', 'categories' => 'Kategorie'] as $kind => $label)
                        <tr><th scope="row">{{ $label }}</th>@foreach (['total', 'eligible', 'fields', 'estimated_characters', 'skipped'] as $key)<td id="catalog-{{ $kind }}-{{ $key }}">{{ data_get($overview, 'catalog.'.$kind.'.'.$key, 0) }}</td>@endforeach</tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <h3>Status zapisanych tłumaczeń</h3>
        <div class="grid">
            @foreach (['translated' => 'Przetłumaczone / reviewed', 'missing' => 'Brakujące (missing)', 'needs_update' => 'Do aktualizacji', 'failed' => 'Błędy (failed)', 'skipped' => 'Pomijane w tym zakresie', 'queued' => 'Oczekujące'] as $key => $label)
                <div class="metric"><span>{{ $label }}</span><strong id="inventory-{{ $key }}">{{ data_get($overview, 'catalog.inventory.'.$key, 0) }}</strong></div>
            @endforeach
        </div>
        <p>Szacowana liczba znaków do tłumaczenia: <strong id="catalog-characters">{{ data_get($overview, 'catalog.estimated_characters', 0) }}</strong>.</p>
    </section>

    <section class="card notice" aria-labelledby="dry-run-title">
        <div class="row between">
            <h2 id="dry-run-title">1. Sprawdź / Dry-run</h2>
            <button id="dry-run" class="button primary" type="button">Sprawdź / Dry-run</button>
        </div>
        <p>Dry-run sprawdza cały katalog bez Google API. Dry-run nie zapisuje tłumaczeń ani katalogu; zapisywany jest tylko wynik podglądu, potrzebny do potwierdzenia startu.</p>
        <p id="dry-run-state" class="muted">Nie wykonano jeszcze dry-run.</p>
        <div id="dry-run-result" hidden>
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Ostatni wynik dry-run</caption>
                    <thead><tr><th scope="col">Typ</th><th scope="col">Total</th><th scope="col">Eligible</th><th scope="col">Fields</th><th scope="col">Estimated characters</th><th scope="col">Skipped</th></tr></thead>
                    <tbody id="dry-run-counts"></tbody>
                </table>
            </div>
            <details><summary>Powody pominięcia</summary><pre id="skip-reasons"></pre></details>
            <details><summary>Przykładowe rekordy</summary><pre id="record-examples"></pre></details>
        </div>
    </section>

    <section class="card warning" aria-labelledby="start-title">
        <h2 id="start-title">2. Przetłumacz cały katalog</h2>
        <p><strong>Tłumaczenie wywołuje Google API i może generować koszt.</strong> Zakres obejmuje produkty i kategorie, tylko brakujące tłumaczenia, po 100 rekordów w partii. Aktualne oraz reviewed są chronione. Rekordy failed i needs_update są pomijane.</p>
        <p>Start wymaga nowego dry-run z ostatniej godziny, włączonej flagi i gotowego providera. Jeden raport można wykorzystać do startu tylko raz.</p>
        <form id="start-form">
            <label for="confirm-token">Wpisz dokładnie <code>TRANSLATE-ALL-GPSWISS-FR-CATALOG</code></label>
            <input id="confirm-token" name="confirm" type="text" autocomplete="off" spellcheck="false" autocapitalize="off" aria-describedby="start-blocked" placeholder="TRANSLATE-ALL-GPSWISS-FR-CATALOG">
            <div><button id="start" class="button primary" type="submit" disabled>Przetłumacz cały katalog</button></div>
            <p id="start-blocked" class="muted">Wykonaj dry-run i wpisz token potwierdzenia.</p>
        </form>
    </section>

    <section class="card" aria-labelledby="run-title">
        <div class="row between">
            <h2 id="run-title">3. Postęp / ostatni wynik apply <span id="run-status" class="badge">idle</span></h2>
            <button id="refresh" class="button" type="button">Odśwież status</button>
        </div>
        <p class="muted">Zadanie działa w tle. Zamknięcie lub odświeżenie strony nie przerywa pracy. Status jest pobierany co 3 sekundy; wejście na stronę nie uruchamia ani nie wznawia zadania.</p>
        <p class="muted">Jeśli licznik przetworzonych rekordów nie rośnie, zgłoś administratorowi sprawdzenie procesu obsługującego tłumaczenia FR. Nie uruchamiaj ponownie całego katalogu.</p>
        <label for="run-progress" class="sr-only">Postęp tłumaczenia</label>
        <progress id="run-progress" class="progress" max="100" value="0">0%</progress>
        <p id="progress-text">Brak uruchomionego zadania.</p>
        <div class="grid">
            @foreach (['total' => 'Łącznie (total)', 'processed' => 'Przetworzone', 'translated' => 'Przetłumaczone', 'skipped' => 'Pomijane', 'failed' => 'Błędy', 'remaining' => 'Pozostałe', 'chars_translated' => 'Przetłumaczone znaki'] as $key => $label)
                <div class="metric"><span>{{ $label }}</span><strong id="run-{{ $key }}">0</strong></div>
            @endforeach
        </div>
        <dl class="status-grid">
            @foreach (['run_id' => 'run_id', 'started_at' => 'Rozpoczęto', 'updated_at' => 'Ostatnia aktualizacja zadania', 'current_type' => 'Aktualny typ', 'current_id' => 'Aktualny rekord', 'next_batch' => 'Następna partia', 'active_timer' => 'Timer w przeglądarce'] as $key => $label)
                <div><dt>{{ $label }}</dt><dd id="run-{{ $key }}">—</dd></div>
            @endforeach
        </dl>
        <p id="poll-state" class="muted" role="status">Status wczytany ze strony.</p>
        <div class="row">
            <button id="pause" class="button" type="button" disabled>Pauza</button>
            <button id="resume" class="button primary" type="button" disabled>Wznów</button>
            <button id="stop" class="button danger" type="button" disabled>Stop</button>
        </div>
        <p class="muted">Pauza i Stop obowiązują od najbliższego bezpiecznego punktu. Wznów kontynuuje istniejące zadanie; przy statusie running może odzyskać jego wykonanie. Stop kończy zadanie.</p>
        <h3>Ostatni sukces</h3><pre id="last-success">Brak.</pre>
        <h3>Ostatni błąd</h3><pre id="last-error">Brak.</pre>
        <details><summary>Przykłady błędów (ID, SKU, kod, bezpieczny opis)</summary><pre id="failed-examples">Brak.</pre></details>
    </section>
    <noscript><p class="card warning">Do dry-run i sterowania zadaniem potrzebny jest JavaScript. Włącz go i odśwież stronę.</p></noscript>
</main>
<script>
(() => {
    'use strict';
    const overview = @json($overview);
    const initialStatus = @json($status);
    const urls = {
        status: @json(route('admin.tools.storefront.fr-translations.status', [], false)),
        dryRun: @json(route('admin.tools.storefront.fr-translations.dry-run', [], false)),
        start: @json(route('admin.tools.storefront.fr-translations.start', [], false)),
        pause: @json(route('admin.tools.storefront.fr-translations.pause', [], false)),
        resume: @json(route('admin.tools.storefront.fr-translations.resume', [], false)),
        stop: @json(route('admin.tools.storefront.fr-translations.stop', [], false)),
    };
    const token = document.querySelector('meta[name="csrf-token"]').content;
    const confirmToken = 'TRANSLATE-ALL-GPSWISS-FR-CATALOG';
    const activeStatuses = ['running', 'paused', 'failed', 'stopped_on_error'];
    const numberKeys = ['total', 'processed', 'translated', 'skipped', 'failed', 'remaining', 'chars_translated'];
    let run = initialStatus;
    let dryRun = overview.latest_dry_run || null;
    let catalog = overview.catalog || {};
    let busy = false;
    let polling = false;
    let authorizationLost = false;
    let statusFresh = true;
    let failedExamples = [];
    let actionVersion = 0;
    const element = id => document.getElementById(id);
    const text = (id, value) => { element(id).textContent = value === null || value === undefined || value === '' ? '—' : String(value); };
    const count = value => Number.isFinite(Number(value)) ? Number(value) : 0;
    const date = value => { const parsed = new Date(value); return value && !Number.isNaN(parsed.getTime()) ? parsed.toLocaleString('pl-PL') : '—'; };
    const safeObject = value => value ? JSON.stringify(value, null, 2) : 'Brak.';
    const activeRun = () => Boolean(run.run_id) && activeStatuses.includes(run.status);
    const freshDryRun = () => {
        if (!dryRun || dryRun.consumed || !dryRun.dry_run_id) return false;
        const created = Date.parse(dryRun.created_at), now = Date.now();
        if (!Number.isFinite(created) || created > now || now - created > 3600000) return false;
        return !run.run_id || !['completed', 'stopped', 'failed'].includes(run.status) || created >= Date.parse(run.updated_at);
    };

    function message(value, error = false) {
        const target = element('action-message');
        target.textContent = value;
        target.className = 'messages ' + (error ? 'error' : 'success');
        target.setAttribute('role', error ? 'alert' : 'status');
    }

    function renderButtons() {
        let blocked = '';
        if (authorizationLost) blocked = 'Sesja lub uprawnienia wygasły. Odśwież stronę i zaloguj się ponownie.';
        else if (!statusFresh) blocked = 'Nie udało się pobrać statusu. Odśwież status przed startem.';
        else if (activeRun()) blocked = 'Zadanie już istnieje. Użyj jego przycisków Pauza, Wznów lub Stop.';
        else if (!overview.schema_ready) blocked = 'Brakuje tabel tłumaczeń. Administrator musi uruchomić migracje.';
        else if (!overview.enabled) blocked = 'Włącz GPSWISS_FR_TRANSLATIONS_ENABLED i odśwież stronę.';
        else if (!overview.provider_ready) blocked = 'Skonfiguruj Google Translate i odśwież stronę.';
        else if (!freshDryRun()) blocked = 'Wykonaj nowy dry-run. Raport jest ważny przez godzinę i do jednego startu.';
        else if (element('confirm-token').value !== confirmToken) blocked = 'Wpisz dokładny token potwierdzenia.';
        else blocked = 'Gotowe do ręcznego startu. Google API może naliczyć opłaty.';
        const canStart = !authorizationLost && statusFresh && !activeRun() && overview.schema_ready && overview.enabled && overview.provider_ready && freshDryRun() && element('confirm-token').value === confirmToken;
        element('start').disabled = busy || !canStart;
        element('dry-run').disabled = busy || authorizationLost || !overview.schema_ready || activeRun();
        element('refresh').disabled = busy || polling || authorizationLost;
        element('pause').disabled = busy || !statusFresh || authorizationLost || !run.run_id || run.status !== 'running';
        element('resume').disabled = busy || !statusFresh || authorizationLost || !activeRun();
        element('stop').disabled = busy || !statusFresh || authorizationLost || !activeRun();
        element('confirm-token').disabled = busy || authorizationLost || activeRun();
        text('start-blocked', blocked);
    }

    function renderCatalog(report) {
        catalog = report || {};
        for (const kind of ['products', 'categories']) {
            for (const key of ['total', 'eligible', 'fields', 'estimated_characters', 'skipped']) text(`catalog-${kind}-${key}`, count(catalog[kind]?.[key]));
        }
        for (const key of ['translated', 'missing', 'needs_update', 'failed', 'skipped', 'queued']) text(`inventory-${key}`, count(catalog.inventory?.[key]));
        text('catalog-characters', count(catalog.estimated_characters));
        renderFailedExamples();
    }

    function renderDryRun() {
        element('dry-run-result').hidden = !dryRun;
        if (!dryRun) { text('dry-run-state', 'Nie wykonano jeszcze dry-run.'); renderButtons(); return; }
        const usable = freshDryRun();
        text('dry-run-state', `Ostatni dry-run: ${date(dryRun.created_at)} · ${dryRun.consumed ? 'wykorzystany do startu' : (usable ? 'gotowy do startu' : 'wygasł')} · ID: ${dryRun.dry_run_id}`);
        const rows = element('dry-run-counts');
        rows.replaceChildren();
        for (const [kind, label] of [['products', 'Produkty'], ['categories', 'Kategorie']]) {
            const row = document.createElement('tr');
            const name = document.createElement('th');
            name.scope = 'row'; name.textContent = label; row.append(name);
            for (const key of ['total', 'eligible', 'fields', 'estimated_characters', 'skipped']) {
                const cell = document.createElement('td'); cell.textContent = count(dryRun[kind]?.[key]); row.append(cell);
            }
            rows.append(row);
        }
        text('skip-reasons', safeObject({products: dryRun.products?.skipped_reasons || {}, categories: dryRun.categories?.skipped_reasons || {}}));
        text('record-examples', safeObject({products: dryRun.products?.examples || [], categories: dryRun.categories?.examples || []}));
        renderButtons();
    }

    function renderFailedExamples() {
        const examples = [...failedExamples, ...(catalog.failed_examples || [])];
        const unique = examples.filter((item, index, items) => items.findIndex(other => JSON.stringify(other) === JSON.stringify(item)) === index).slice(0, 40);
        text('failed-examples', unique.length ? safeObject(unique) : 'Brak przykładów błędów.');
    }

    function renderRun(data) {
        run = data || {status: 'idle'};
        const status = run.status || 'idle';
        text('run-status', status);
        element('run-status').className = 'badge ' + (status === 'completed' ? 'good' : (['failed', 'stopped_on_error'].includes(status) ? 'bad' : (status === 'running' ? 'pending' : '')));
        for (const key of numberKeys) text(`run-${key}`, count(run[key]));
        for (const key of ['run_id', 'current_type', 'current_id', 'active_timer']) text(`run-${key}`, run[key] ?? (key === 'active_timer' ? false : null));
        for (const key of ['started_at', 'updated_at', 'next_batch']) text(`run-${key}`, date(run[key]));
        const total = count(run.total), processed = count(run.processed);
        const percent = total > 0 ? Math.min(100, Math.round(processed / total * 100)) : (status === 'completed' ? 100 : 0);
        element('run-progress').value = percent;
        text('progress-text', run.run_id ? `${processed} / ${total} przetworzonych (${percent}%) · Pozostało: ${count(run.remaining)}` : 'Brak uruchomionego zadania.');
        text('last-success', safeObject(run.last_success));
        text('last-error', safeObject(run.last_error));
        failedExamples = run.failed_examples || [];
        renderFailedExamples();
        if (dryRun && (run.dry_run_id === dryRun.dry_run_id || run.dry_run?.dry_run_id === dryRun.dry_run_id)) dryRun.consumed = true;
        renderDryRun();
    }

    async function request(url, body) {
        const options = {method: body === undefined ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store', headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}};
        if (body !== undefined) { options.headers['Content-Type'] = 'application/json'; options.headers['X-CSRF-TOKEN'] = token; options.body = JSON.stringify(body); }
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), body === undefined ? 20000 : 120000);
        options.signal = controller.signal;
        try {
            const response = await fetch(url, options);
            if ([401, 403, 419].includes(response.status) || response.redirected) {
                authorizationLost = true;
                throw new Error('Sesja lub uprawnienia wygasły. Odśwież stronę i zaloguj się ponownie.');
            }
            let data;
            try { data = await response.json(); } catch (_) { throw new Error('Serwer zwrócił nieprawidłową odpowiedź. Odśwież status.'); }
            if (!response.ok || data.ok === false) {
                const validation = data.errors ? Object.values(data.errors).flat().join(' ') : null;
                throw new Error(data.error || validation || `Operacja nie powiodła się (HTTP ${response.status}). Odśwież status.`);
            }
            return data;
        } catch (error) {
            if (error.name === 'AbortError') throw new Error('Przekroczono czas oczekiwania. Odśwież status przed ponowną próbą.');
            if (error instanceof TypeError) throw new Error('Brak połączenia z serwerem. Odśwież status przed ponowną próbą.');
            throw error;
        } finally { window.clearTimeout(timeout); }
    }

    async function refresh(manual = false) {
        if (polling || busy || authorizationLost) return;
        const requestedVersion = actionVersion;
        polling = true; renderButtons();
        try {
            const data = await request(urls.status);
            if (requestedVersion !== actionVersion) return;
            statusFresh = true; renderRun(data);
            text('poll-state', `Status sprawdzony: ${new Date().toLocaleTimeString('pl-PL')}.`);
            if (manual) message('Status odświeżony.');
        } catch (error) {
            if (requestedVersion !== actionVersion) return;
            statusFresh = false;
            text('poll-state', `Nie udało się odświeżyć statusu. ${error.message}`);
            if (manual || authorizationLost) message(error.message, true);
        } finally { polling = false; renderButtons(); }
    }

    async function action(name, body) {
        if (busy || authorizationLost) return;
        actionVersion++;
        busy = true; renderButtons();
        element('start-form').setAttribute('aria-busy', 'true');
        message(name === 'dryRun' ? 'Trwa sprawdzanie katalogu…' : 'Zapisywanie polecenia…');
        try {
            const data = await request(urls[name], body);
            if (name === 'dryRun') {
                dryRun = data.dry_run;
                element('confirm-token').value = '';
                renderCatalog(dryRun); renderDryRun();
                text('catalog-snapshot', `Stan z dry-run: ${date(dryRun.created_at)}.`);
                message('Dry-run gotowy. Raport nie wywołał Google API i nie zapisał tłumaczeń.');
            } else {
                statusFresh = true;
                if (name === 'start') { if (dryRun) dryRun.consumed = true; element('confirm-token').value = ''; }
                renderRun(data);
                message({start: 'Zadanie uruchomione w tle.', pause: 'Zapisano polecenie pauzy.', resume: 'Wznowiono istniejące zadanie.', stop: 'Zapisano polecenie zatrzymania.'}[name]);
            }
        } catch (error) {
            if (name === 'start') element('confirm-token').value = '';
            statusFresh = false;
            message(error.message, true);
        } finally {
            busy = false;
            element('start-form').setAttribute('aria-busy', 'false');
            renderButtons();
            await refresh();
        }
    }

    element('confirm-token').addEventListener('input', renderButtons);
    element('dry-run').addEventListener('click', () => action('dryRun', {}));
    element('refresh').addEventListener('click', () => refresh(true));
    element('start-form').addEventListener('submit', event => {
        event.preventDefault(); renderButtons();
        if (!element('start').disabled) action('start', {dry_run_id: dryRun.dry_run_id, confirm: element('confirm-token').value});
    });
    for (const name of ['pause', 'resume', 'stop']) element(name).addEventListener('click', () => {
        if (!element(name).disabled && run.run_id) action(name, {run_id: run.run_id});
    });
    renderCatalog(catalog); renderRun(initialStatus);
    window.setInterval(() => { renderButtons(); refresh(); }, 3000);
})();
</script>
</body>
</html>
