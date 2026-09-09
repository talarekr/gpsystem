<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Jarek eBay price apply runner</title>
    <style>
        body{font:15px system-ui;max-width:1200px;margin:30px auto;padding:0 20px}.danger,.error{background:#fee2e2;border:2px solid #dc2626;padding:16px}.controls{display:flex;gap:10px;flex-wrap:wrap;margin:20px 0}input{padding:8px}button{padding:8px 15px}table{border-collapse:collapse;width:100%}td,th{padding:7px;border:1px solid #ddd;text-align:left}pre{background:#111;color:#eee;padding:15px;overflow:auto;white-space:pre-wrap}.marketplace{font-size:20px;color:#b91c1c;font-weight:bold}.summary{font-size:17px}.hidden{display:none}.debug-grid{display:grid;grid-template-columns:max-content 1fr;gap:5px 14px}.debug-grid dt{font-weight:bold}.debug-grid dd{margin:0;overflow-wrap:anywhere}
    </style>
</head>
<body>
<main id="jarek-ebay-apply-runner"
      data-preview-url="{{ route('admin.tools.jarek-gearboxes.ebay-bulk-price-increase-preview') }}?percent=7"
      data-action-url="{{ route('admin.tools.jarek-gearboxes.ebay-bulk-price-increase-apply-runner.action') }}"
      data-resume-url="{{ route('admin.tools.jarek-gearboxes.ebay-bulk-price-increase-apply-runner.resume') }}"
      data-status-url="{{ route('admin.tools.jarek-gearboxes.ebay-bulk-price-increase-apply-runner-status') }}">
    <h1>Jarek Gearboxes — eBay DE +7% batch apply runner</h1>
    <div class="danger"><div class="marketplace">⚠ MARKETPLACE WRITE</div><b>Wyłącznie price-only PUT istniejącej oferty.</b> Runner nie zmienia ceny lokalnej, stocku, quantity, treści, zdjęć, kategorii, polityk ani tytułów; nie publikuje i nie dotyka eBay FR. Wejście na stronę ani załadowanie preview niczego nie uruchamia.</div>
    <p>Global eBay write: <b>{{ ($ebayConnection['write_enabled'] ?? false) ? 'ENABLED' : 'DISABLED' }}</b></p>
    <div class="controls">
        <button id="preview" type="button">Załaduj preview</button>
        <label>Snapshot <input id="snapshot" size="68"></label>
        <label>Batch (max 10) <input id="size" type="number" min="1" max="10" value="5"></label>
        <label>Delay ms <input id="delay" type="number" min="1000" value="4000"></label>
    </div>
    <p class="summary">Eligible: <b id="eligible-count">—</b></p>
    <label>Token <input id="token" size="55" autocomplete="off" placeholder="APPLY_JAREK_EBAY_PRICES_7_PERCENT_BATCH_RUNNER"></label>
    <div class="controls">
        <button id="start" type="button">Start Canary / kontrolowany runner od offsetu 0</button>
        <button id="pause" type="button">Pause</button>
        <button id="resume" type="button">Resume</button>
        <button id="stop" type="button">Stop</button>
        <button id="status" type="button">Odśwież status</button>
    </div>
    <section id="preview-error" class="error hidden" role="alert" aria-live="assertive">
        <h2>Nie udało się załadować preview</h2>
        <pre id="preview-error-details"></pre>
    </section>
    <section id="resume-error" class="error hidden" role="alert" aria-live="assertive">
        <h2>Nie udało się wznowić runnera</h2>
        <p id="resume-error-message"></p>
        <pre id="resume-error-details"></pre>
    </section>
    <h2>Pierwsze 20 planowanych rekordów</h2>
    <table>
        <thead><tr><th>ID</th><th>SKU</th><th>Offer ID</th><th>Listing ID</th><th>Old price</th><th>New price</th><th>Currency</th></tr></thead>
        <tbody id="items"><tr><td colspan="7">Najpierw załaduj preview. Start pozostaje ręczny.</td></tr></tbody>
    </table>
    <h2>Status / batch history</h2>
    <pre id="output">Nie uruchomiono.</pre>
    <h2>Preview debug</h2>
    <dl class="debug-grid">
        <dt>js_loaded</dt><dd id="debug-js-loaded">no</dd>
        <dt>preview_click_count</dt><dd id="debug-preview-click-count">0</dd>
        <dt>last_preview_endpoint</dt><dd id="debug-last-preview-endpoint">—</dd>
        <dt>last_http_status</dt><dd id="debug-last-http-status">—</dd>
        <dt>last_response_is_json</dt><dd id="debug-last-response-is-json">—</dd>
        <dt>last_error_type</dt><dd id="debug-last-error-type">none</dd>
        <dt>last_response_preview</dt><dd id="debug-last-response-preview">—</dd>
    </dl>
    <h2>Resume debug</h2>
    <dl class="debug-grid">
        <dt>resume_click_count</dt><dd id="debug-resume-click-count">0</dd>
        <dt>last_resume_endpoint</dt><dd id="debug-last-resume-endpoint">—</dd>
        <dt>last_resume_http_status</dt><dd id="debug-last-resume-http-status">—</dd>
        <dt>last_resume_response_is_json</dt><dd id="debug-last-resume-response-is-json">—</dd>
        <dt>last_resume_error_type</dt><dd id="debug-last-resume-error-type">none</dd>
        <dt>last_resume_response_preview</dt><dd id="debug-last-resume-response-preview">—</dd>
        <dt>active_timer</dt><dd id="debug-active-timer">no</dd>
        <dt>next_batch_scheduled_at</dt><dd id="debug-next-batch-scheduled-at">—</dd>
    </dl>
</main>
<script>
(() => {
    const runtimeKey = '__jarekEbayApplyRunnerUi';
    const runtime = window[runtimeKey] ?? {previewClickCount: 0, resumeClickCount: 0, timer: null, listenersBound: false};
    window[runtimeKey] = runtime;

    const root = () => document.getElementById('jarek-ebay-apply-runner');
    const element = id => root()?.querySelector(`#${id}`);
    const setDebug = (name, value) => {
        const target = element(`debug-${name.replaceAll('_', '-')}`);
        if (target) target.textContent = String(value);
    };
    const responsePreview = body => String(body ?? '').replace(/\s+/g, ' ').trim().slice(0, 1000) || '(empty response)';
    const errorType = (status, isJson, networkError = false) => {
        if (networkError) return 'network';
        if (status === 401 || status === 403) return 'auth';
        if (status === 419) return 'csrf_or_session';
        if (status === 422) return 'validation';
        if (status === 429) return 'throttle';
        if (status >= 500) return 'server';
        if (!isJson) return 'non_json_response';
        return status >= 400 ? 'http' : 'none';
    };

    function initialize() {
        if (!root()) return;
        setDebug('js_loaded', 'yes');
        setDebug('preview_click_count', runtime.previewClickCount);
        setDebug('resume_click_count', runtime.resumeClickCount);
        setDebug('active_timer', runtime.timer ? 'yes' : 'no');
    }

    function showPreviewError(details) {
        const panel = element('preview-error');
        if (!panel) return;
        panel.classList.remove('hidden');
        element('preview-error-details').textContent = JSON.stringify(details, null, 2);
    }

    function clearPreviewError() {
        element('preview-error')?.classList.add('hidden');
        const details = element('preview-error-details');
        if (details) details.textContent = '';
    }

    function renderRows(rows) {
        const tableBody = element('items');
        if (!tableBody) return;
        tableBody.replaceChildren();
        if (!rows.length) {
            const row = tableBody.insertRow();
            const cell = row.insertCell();
            cell.colSpan = 7;
            cell.textContent = 'Brak rekordów kwalifikujących się do zmiany.';
            return;
        }
        rows.slice(0, 20).forEach(item => {
            const row = tableBody.insertRow();
            [item.jarek_gearbox_id ?? item.id, item.sku, item.ebay_offer_id ?? item.offer_id, item.ebay_listing_id ?? item.listing_id, item.old_price, item.new_price, item.currency]
                .forEach(value => {
                    const cell = row.insertCell();
                    cell.textContent = value ?? '—';
                });
        });
    }

    async function loadPreview() {
        const page = root();
        if (!page) return;
        const endpoint = page.dataset.previewUrl;
        runtime.previewClickCount += 1;
        setDebug('preview_click_count', runtime.previewClickCount);
        setDebug('last_preview_endpoint', endpoint);
        setDebug('last_http_status', 'pending');
        setDebug('last_response_is_json', 'pending');
        setDebug('last_error_type', 'none');
        setDebug('last_response_preview', 'pending');
        clearPreviewError();

        try {
            const response = await fetch(endpoint, {method: 'GET', headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
            const body = await response.text();
            let json = null;
            try { json = JSON.parse(body); } catch (_) { /* reported as non_json_response */ }
            const isJson = json !== null && typeof json === 'object';
            const type = errorType(response.status, isJson);
            setDebug('last_http_status', response.status);
            setDebug('last_response_is_json', isJson ? 'yes' : 'no');
            setDebug('last_error_type', type);
            setDebug('last_response_preview', responsePreview(body));
            if (!response.ok || !isJson || json.ok === false) {
                const resolvedType = json?.error_type || type || 'preview_error';
                setDebug('last_error_type', resolvedType);
                showPreviewError({endpoint, http_status: response.status, response_preview: responsePreview(body), error_type: resolvedType});
                return;
            }
            element('snapshot').value = json.snapshot_id ?? '';
            element('eligible-count').textContent = json.products_eligible_for_price_increase ?? json.eligible_count ?? 0;
            renderRows(Array.isArray(json.eligible_products) ? json.eligible_products : []);
            element('output').textContent = JSON.stringify({snapshot_id: json.snapshot_id, eligible: json.products_eligible_for_price_increase ?? json.eligible_count ?? 0, read_only: json.read_only, marketplace_write: json.marketplace_write, external_api_requests: json.external_api_requests}, null, 2);
        } catch (error) {
            setDebug('last_http_status', 'no response');
            setDebug('last_response_is_json', 'no');
            setDebug('last_error_type', 'network');
            setDebug('last_response_preview', error.message || String(error));
            showPreviewError({endpoint, http_status: 'no response', response_preview: error.message || String(error), error_type: 'network'});
        }
    }

    async function post(action, extra = {}) {
        const page = root();
        const response = await fetch(page.dataset.actionUrl, {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content}, body: JSON.stringify({action, ...extra})});
        const json = await response.json();
        element('output').textContent = JSON.stringify(json, null, 2);
        return json;
    }
    function cancel() {
        if (runtime.timer) clearTimeout(runtime.timer);
        runtime.timer = null;
        setDebug('active_timer', 'no');
        setDebug('next_batch_scheduled_at', '—');
    }
    function schedule(snapshotId, delayMs) {
        cancel();
        const delay = Number(delayMs) || 4000;
        setDebug('active_timer', 'yes');
        setDebug('next_batch_scheduled_at', new Date(Date.now() + delay).toISOString());
        runtime.timer = setTimeout(() => resumeNextBatch(snapshotId, false), delay);
    }

    function showResumeError(details, csrf = false) {
        cancel();
        const panel = element('resume-error');
        if (!panel) return;
        panel.classList.remove('hidden');
        element('resume-error-message').textContent = csrf
            ? 'Sesja lub token CSRF wygasły. Odśwież stronę i kliknij Resume.'
            : 'Resume nie wykonał batcha. Szczegóły diagnostyczne znajdują się poniżej.';
        element('resume-error-details').textContent = JSON.stringify(details, null, 2);
    }

    function clearResumeError() { element('resume-error')?.classList.add('hidden'); }

    async function resumeNextBatch(snapshotId, countClick = true) {
        const page = root();
        if (!page) return;
        cancel();
        if (countClick) runtime.resumeClickCount += 1;
        setDebug('resume_click_count', runtime.resumeClickCount);
        const statusEndpoint = `${page.dataset.statusUrl}?snapshot_id=${encodeURIComponent(snapshotId || '')}`;
        setDebug('last_resume_endpoint', statusEndpoint);
        setDebug('last_resume_http_status', 'pending');
        setDebug('last_resume_response_is_json', 'pending');
        setDebug('last_resume_error_type', 'none');
        setDebug('last_resume_response_preview', 'pending');
        clearResumeError();
        try {
            const statusResponse = await fetch(statusEndpoint, {method: 'GET', headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
            const statusBody = await statusResponse.text();
            let statusJson = null;
            try { statusJson = JSON.parse(statusBody); } catch (_) { /* handled below */ }
            if (!statusResponse.ok || !statusJson || !statusJson.runner?.apply_run_id) {
                throw {response: statusResponse, body: statusBody, json: statusJson, phase: 'status'};
            }
            const runner = statusJson.runner;
            element('snapshot').value = runner.snapshot_id;
            element('size').value = runner.batch_size;
            element('delay').value = runner.delay_ms;
            // The server performs the strict error allow-list check. The browser
            // only needs to let a stopped OAuth run reach that guarded endpoint.
            if (!['running', 'paused', 'stopped_on_error'].includes(runner.status)) {
                throw {response: statusResponse, body: statusBody, json: statusJson, phase: 'runner_state'};
            }

            const endpoint = page.dataset.resumeUrl;
            setDebug('last_resume_endpoint', endpoint);
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const response = await fetch(endpoint, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken}, body: JSON.stringify({snapshot_id: runner.snapshot_id, confirm: element('token').value})});
            const body = await response.text();
            let json = null;
            try { json = JSON.parse(body); } catch (_) { /* handled below */ }
            const isJson = json !== null && typeof json === 'object';
            const type = errorType(response.status, isJson);
            setDebug('last_resume_http_status', response.status);
            setDebug('last_resume_response_is_json', isJson ? 'yes' : 'no');
            setDebug('last_resume_error_type', type);
            setDebug('last_resume_response_preview', responsePreview(body));
            element('output').textContent = isJson ? JSON.stringify(json, null, 2) : body;
            if (!response.ok || !isJson || json.ok === false) {
                showResumeError({endpoint, phase: 'resume_batch', http_status: response.status, error_type: type, response_preview: responsePreview(body)}, response.status === 419);
                return;
            }
            if (json.status === 'running') schedule(json.snapshot_id, json.delay_ms);
            else cancel();
        } catch (failure) {
            const status = failure?.response?.status ?? 'no response';
            const body = failure?.body ?? failure?.message ?? String(failure);
            const isJson = failure?.json !== null && typeof failure?.json === 'object';
            const type = errorType(Number(status) || 0, isJson, status === 'no response');
            setDebug('last_resume_http_status', status);
            setDebug('last_resume_response_is_json', isJson ? 'yes' : 'no');
            setDebug('last_resume_error_type', type);
            setDebug('last_resume_response_preview', responsePreview(body));
            showResumeError({endpoint: page.dataset.statusUrl, phase: failure?.phase || 'status', http_status: status, error_type: type, response_preview: responsePreview(body)}, status === 419);
        }
    }

    async function handleClick(event) {
        const button = event.target.closest('button');
        if (!button || !root()?.contains(button)) return;
        if (button.id === 'preview') { event.preventDefault(); await loadPreview(); return; }
        if (button.id === 'start') {
            cancel();
            if (!confirm('Rozpocząć MARKETPLACE WRITE od offsetu 0?')) return;
            const json = await post('start', {confirm: element('token').value, snapshot_id: element('snapshot').value, percent: 7, channel: 'ebay_de', batch_size: +element('size').value, delay_ms: +element('delay').value});
            if (json.ok && json.status === 'running') schedule(json.snapshot_id, json.delay_ms);
            return;
        }
        if (button.id === 'pause' || button.id === 'stop') { cancel(); await post(button.id); return; }
        if (button.id === 'resume') { await resumeNextBatch(element('snapshot').value); return; }
        if (button.id === 'status') {
            const response = await fetch(`${root().dataset.statusUrl}?snapshot_id=${encodeURIComponent(element('snapshot').value)}`, {method: 'GET', headers: {'Accept': 'application/json'}});
            element('output').textContent = JSON.stringify(await response.json(), null, 2);
        }
    }

    if (!runtime.listenersBound) {
        runtime.listenersBound = true;
        document.addEventListener('click', handleClick);
        document.addEventListener('DOMContentLoaded', initialize);
        document.addEventListener('livewire:navigated', initialize);
        document.addEventListener('filament:navigated', initialize);
    }
    if (document.readyState !== 'loading') initialize();
})();
</script>
</body>
</html>
