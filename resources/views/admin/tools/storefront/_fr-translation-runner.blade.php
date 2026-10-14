<script>
function mountFrTranslationSection(root, overview, initialStatus, urls, confirmToken, prefix, kinds) {
    'use strict';
    const token = document.querySelector('meta[name="csrf-token"]').content;
    const activeStatuses = ['running', 'paused', 'failed', 'stopped_on_error'];
    const numberKeys = ['total', 'processed', 'translated', 'skipped', 'failed', 'remaining', 'chars_translated', 'technical_retry_total', 'technical_retry_processed', 'technical_retry_remaining', 'technical_retry_translated', 'technical_retry_failed', 'technical_retry_skipped'];
    let run = initialStatus;
    let dryRun = overview.latest_dry_run || null;
    let catalog = overview.catalog || {};
    let busy = false;
    let polling = false;
    let ticking = false;
    let browserEnabled = false;
    let authorizationLost = false;
    let statusFresh = true;
    let failedExamples = [];
    let actionVersion = 0;
    const element = id => root.querySelector(`#${prefix}${id}`);
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
        else if (run.run_storage_ready === false) blocked = 'Brakuje trwałej tabeli runów FR. Wdrożenie musi wykonać migrację; nie czyść cache.';
        else if (!overview.schema_ready) blocked = 'Brakuje tabel tłumaczeń. Administrator musi uruchomić migracje.';
        else if (!overview.enabled) blocked = 'Włącz GPSWISS_FR_TRANSLATIONS_ENABLED i odśwież stronę.';
        else if (!overview.provider_ready) blocked = 'Skonfiguruj Google Translate i odśwież stronę.';
        else if (!freshDryRun()) blocked = 'Wykonaj nowy dry-run. Raport jest ważny przez godzinę i do jednego startu.';
        else if (element('confirm-token').value !== confirmToken) blocked = 'Wpisz dokładny token potwierdzenia.';
        else blocked = 'Gotowe do ręcznego startu. Google API może naliczyć opłaty.';
        const canStart = !authorizationLost && statusFresh && !activeRun() && run.run_storage_ready !== false && overview.schema_ready && overview.enabled && overview.provider_ready && freshDryRun() && element('confirm-token').value === confirmToken;
        element('start').disabled = busy || !canStart;
        element('dry-run').disabled = busy || authorizationLost || !overview.schema_ready || activeRun();
        element('refresh').disabled = busy || polling || authorizationLost;
        element('pause').disabled = busy || !statusFresh || authorizationLost || !run.run_id || run.status !== 'running';
        element('resume').disabled = busy || !statusFresh || authorizationLost || !(run.resume_available || activeRun());
        element('stop').disabled = busy || !statusFresh || authorizationLost || !activeRun();
        element('confirm-token').disabled = busy || authorizationLost || activeRun();
        text('start-blocked', blocked);
    }

    function renderCatalog(report) {
        catalog = report || {};
        for (const [kind] of kinds) {
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
        for (const [kind, label] of kinds) {
            const row = document.createElement('tr');
            const name = document.createElement('th');
            name.scope = 'row'; name.textContent = label; row.append(name);
            for (const key of ['total', 'eligible', 'fields', 'estimated_characters', 'skipped']) {
                const cell = document.createElement('td'); cell.textContent = count(dryRun[kind]?.[key]); row.append(cell);
            }
            rows.append(row);
        }
        text('skip-reasons', safeObject(Object.fromEntries(kinds.map(([kind]) => [kind, dryRun[kind]?.skipped_reasons || {}]))));
        text('record-examples', safeObject(Object.fromEntries(kinds.map(([kind]) => [kind, dryRun[kind]?.examples || []]))));
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
        text('lease-state', run.lease_hint);
        text('lease-details', safeObject(run.lease));
        const saved = count(catalog.inventory?.translated);
        text('recovery-state', activeRun()
            ? 'Znaleziono niedokończony run. Po reloadzie lub ponownym zalogowaniu kliknij Wznów; zachowasz run_id i postęp. Nie klikaj ponownie Start.'
            : `Według ostatniego odczytu katalogu zapisano ${saved} translated/reviewed. ${run.run_id ? 'Poprzedni run jest zakończony lub zatrzymany.' : 'Brak checkpointu w bazie/cache — starego runu nie można odtworzyć.'} Możesz wykonać nowy dry-run i Start. Aktualne translated/reviewed nie będą tłumaczone ponownie; failed i needs_update pozostaną pominięte.`);
        text('worker-state', !run.run_id ? 'Start uruchomi domyślny tryb panelowy; pozostaw stronę otwartą.' : run.runner_mode === 'browser'
            ? (browserEnabled ? 'Tryb panelowy aktywny — strona zleca pojedyncze rekordy. Worker nie jest wymagany.' : 'Tryb panelowy wstrzymany w tej karcie. Kliknij Wznów, aby kontynuować istniejący run.')
            : 'Run kolejki. Kliknij Wznów, aby przełączyć istniejący checkpoint na domyślny tryb panelowy; bez ponownego Startu.');
        element('run-status').className = 'badge ' + (status === 'completed' ? 'good' : (['failed', 'stopped_on_error'].includes(status) ? 'bad' : (status === 'running' ? 'pending' : '')));
        for (const key of numberKeys) text(`run-${key}`, count(run[key]));
        for (const key of ['run_id', 'runner_mode', 'checkpoint_source', 'current_type', 'current_id', 'active_timer', 'waiting_for_worker', 'recovery_available', 'runner_hint']) text(`run-${key}`, key === 'active_timer' ? browserEnabled && run.status === 'running' : run[key]);
        for (const key of ['started_at', 'updated_at', 'next_batch', 'last_dispatch_at']) text(`run-${key}`, date(run[key]));
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
        if (polling || ticking || busy || authorizationLost) return;
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
        if (['pause', 'stop', 'resume', 'start'].includes(name)) browserEnabled = false;
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
                if (['start', 'resume'].includes(name)) browserEnabled = data.runner_mode === 'browser' && data.status === 'running';
                renderRun(data);
                message({start: 'Zadanie uruchomione. Zostaw stronę otwartą.', pause: 'Zapisano polecenie pauzy.', resume: 'Wznowiono istniejące zadanie.', stop: 'Zapisano polecenie zatrzymania.'}[name]);
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

    async function tick() {
        if (!browserEnabled || ticking || polling || busy || authorizationLost || !statusFresh || run.runner_mode !== 'browser' || run.status !== 'running') return;
        if (run.next_batch && Date.parse(run.next_batch) > Date.now()) return;
        const requestedVersion = actionVersion;
        ticking = true;
        try {
            const data = await request(urls.tick, {run_id: run.run_id});
            if (requestedVersion !== actionVersion) return;
            if (data.status !== 'running') browserEnabled = false;
            renderRun(data);
        } catch (error) {
            browserEnabled = false;
            statusFresh = false;
            message(`${error.message} Odśwież status i kliknij Wznów dla istniejącego runu.`, true);
        } finally { ticking = false; renderButtons(); }
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
    window.setInterval(async () => { renderButtons(); await refresh(); await tick(); }, 3000);
}

</script>
