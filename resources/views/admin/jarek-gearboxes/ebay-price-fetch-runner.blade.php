<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Jarek eBay — fetch/cache runner</title>
    <style>
        body{font:14px system-ui;margin:2rem;max-width:1200px}button,input,select{padding:.55rem;margin:.2rem}pre{background:#111;color:#eee;padding:1rem;overflow:auto;white-space:pre-wrap}.warning{color:#9b3b00}.grid{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:.5rem}.metric,.debug{border:1px solid #ddd;padding:.7rem}.debug-grid{display:grid;grid-template-columns:repeat(2,minmax(250px,1fr));gap:.35rem}.debug-value{font-family:ui-monospace,monospace;overflow-wrap:anywhere}.error{border:2px solid #b91c1c;color:#fee;background:#450a0a}.status{font-weight:700;color:#075985}
    </style>
</head>
<body>
<main id="jarek-ebay-price-fetch-runner"
      data-preview-url="{{ route('admin.tools.jarek-gearboxes.ebay-price-fetch-preview') }}"
      data-cache-url="{{ route('admin.tools.jarek-gearboxes.ebay-price-fetch-cache-apply') }}">
    <h1>Jarek Gearboxes — eBay price fetch/cache</h1>
    <p class="warning"><strong>Bezpieczny zakres:</strong> wyłącznie GET ofert eBay oraz opcjonalny lokalny cache. Nigdy revise/update/publish; <code>marketplace_write=false</code>.</p>
    <label>Tryb <select id="fetch-runner-mode"><option value="cache">Fetch + cache</option><option value="dry">Dry-run (bez zapisu)</option></select></label>
    <label>Channel <select id="fetch-runner-channel"><option value="ebay_de">ebay_de</option></select></label>
    <label>Batch <input id="fetch-runner-limit" type="number" min="1" max="100" value="50"></label>
    <label>Delay ms <input id="fetch-runner-delay" type="number" min="1500" max="10000" value="2000"></label>
    <label>Offset <input id="fetch-runner-offset" type="number" min="0" value="0"></label>
    <label>Confirm <input id="fetch-runner-confirm" value="FETCH_JAREK_EBAY_PRICES_READ_ONLY_CACHE" readonly size="46"></label>
    <p>
        <button id="fetch-runner-start" type="button">Start</button>
        <button id="fetch-runner-pause" type="button">Pause</button>
        <button id="fetch-runner-resume" type="button">Resume</button>
        <button id="fetch-runner-stop" type="button">Stop</button>
        <button id="fetch-runner-test" type="button">Test dry-run request</button>
    </p>
    <p id="fetch-runner-action" class="status">Ready</p>
    <div class="grid" id="fetch-runner-progress"></div>
    <h2>Ostatni batch</h2><pre id="fetch-runner-last">—</pre>
    <h2>Błędy requestu</h2><pre id="fetch-runner-errors">—</pre>

    <section id="fetch-runner-debug" class="debug" aria-live="polite">
        <h2>Debug runnera</h2>
        <div class="debug-grid">
            @foreach (['js_loaded' => 'JS loaded', 'initialized_at' => 'initialized_at', 'bound' => 'bound', 'start_click_count' => 'start_click_count', 'last_action' => 'last_action', 'mode' => 'mode', 'channel' => 'channel', 'limit' => 'limit', 'offset' => 'offset', 'endpoint' => 'endpoint', 'request_started_at' => 'request_started_at', 'request_finished_at' => 'request_finished_at', 'last_http_status' => 'last_http_status', 'last_response_is_json' => 'last_response_is_json', 'last_error_type' => 'last_error_type', 'last_error_message' => 'last_error_message', 'last_response_preview' => 'last_response_preview'] as $key => $label)
                <div><strong>{{ $label }}:</strong> <span id="fetch-debug-{{ $key }}" class="debug-value">{{ in_array($key, ['js_loaded', 'bound'], true) ? 'no' : '—' }}</span></div>
            @endforeach
        </div>
    </section>
</main>

<script>
(() => {
    'use strict';
    const rootId = 'jarek-ebay-price-fetch-runner';
    const id = name => document.getElementById(`fetch-runner-${name}`);
    const debugElement = name => document.getElementById(`fetch-debug-${name}`);
    const now = () => new Date().toISOString();
    const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
    const runtime = window.jarekEbayPriceFetchRunner ||= {
        bound: false, running: false, paused: false, stopped: false, aborter: null,
        startClickCount: 0, processed: 0, fetched: 0, cached: 0, missing: 0,
        stale: 0, skipped: 0, errors: 0, total: 0, withOffer: 0
    };

    function setDebug(name, value) {
        const element = debugElement(name);
        if (element) element.textContent = value === null || value === undefined || value === '' ? '—' : String(value);
    }

    function selectedRequest() {
        const root = document.getElementById(rootId);
        const mode = id('mode')?.value || 'dry';
        const channel = id('channel')?.value || 'ebay_de';
        const limit = Math.min(100, Math.max(1, Number(id('limit')?.value) || 50));
        const offset = Math.max(0, Number(id('offset')?.value) || 0);
        const endpoint = mode === 'dry' ? root?.dataset.previewUrl : root?.dataset.cacheUrl;
        return {root, mode, channel, limit, offset, endpoint};
    }

    function reflectSelection(action) {
        const request = selectedRequest();
        setDebug('last_action', action);
        setDebug('mode', request.mode);
        setDebug('channel', request.channel);
        setDebug('limit', request.limit);
        setDebug('offset', request.offset);
        setDebug('endpoint', request.endpoint || 'missing endpoint');
        return request;
    }

    function initialize() {
        if (!document.getElementById(rootId)) return;
        setDebug('js_loaded', 'yes');
        setDebug('initialized_at', now());
        setDebug('bound', runtime.bound ? 'yes' : 'no');
        setDebug('start_click_count', runtime.startClickCount);
        reflectSelection('Initialized');
        draw(Number(id('offset')?.value) || 0);
    }

    function preview(text) {
        return String(text || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 800);
    }

    function errorType(status, isJson, exception = false) {
        if (exception) return 'network';
        if (status === 419) return 'csrf_or_session';
        if (status === 401 || status === 403) return 'auth';
        if (status === 405) return 'method';
        if (status === 422) return 'validation';
        if (status === 429) return 'throttle';
        if (status >= 500) return 'server';
        if (!isJson) return 'non_json_response';
        return status >= 400 ? 'http' : 'none';
    }

    async function request(url, options = {}) {
        runtime.aborter = new AbortController();
        const timer = setTimeout(() => runtime.aborter.abort(), 30000);
        setDebug('endpoint', url);
        setDebug('request_started_at', now());
        setDebug('request_finished_at', '—');
        setDebug('last_http_status', 'pending');
        setDebug('last_response_is_json', 'pending');
        setDebug('last_error_type', 'none');
        setDebug('last_error_message', '—');
        id('action').textContent = `Request started: ${url}`;
        try {
            const response = await fetch(url, {
                ...options,
                signal: runtime.aborter.signal,
                headers: {'Accept': 'application/json', ...(options.headers || {})}
            });
            const body = await response.text();
            let json = null;
            try { json = JSON.parse(body); } catch (_) { /* diagnosed below */ }
            const isJson = json !== null && typeof json === 'object';
            const type = errorType(response.status, isJson);
            setDebug('request_finished_at', now());
            setDebug('last_http_status', response.status);
            setDebug('last_response_is_json', isJson ? 'yes' : 'no');
            setDebug('last_response_preview', preview(body));
            setDebug('last_error_type', type);
            if (!response.ok || !isJson) {
                const message = isJson ? (json.message || json.error || `HTTP ${response.status}`) : `HTTP ${response.status}: odpowiedź nie jest JSON`;
                setDebug('last_error_message', message);
                throw Object.assign(new Error(message), {diagnosed: true, status: response.status, type});
            }
            id('action').textContent = `Request finished: HTTP ${response.status}`;
            return json;
        } catch (error) {
            if (!error.diagnosed) {
                setDebug('request_finished_at', now());
                setDebug('last_http_status', 'no response');
                setDebug('last_response_is_json', 'no');
                setDebug('last_error_type', 'network');
                setDebug('last_error_message', error.name === 'AbortError' ? 'Request aborted or timed out' : error.message);
            }
            id('errors').classList.add('error');
            id('errors').textContent = `${error.type || 'network'}: ${error.message}\nEndpoint: ${url}`;
            throw error;
        } finally {
            clearTimeout(timer);
        }
    }

    function draw(next) {
        if (!id('progress')) return;
        const remaining = Math.max(0, runtime.withOffer - next);
        const names = {total:'total_jarek_products',withOffer:'products_with_ebay_offer_id',processed:'processed',fetched:'fetched_count',cached:'cached_count',missing:'missing_price_count',stale:'stale_404_count',skipped:'skipped_count',errors:'errors_count'};
        id('progress').innerHTML = Object.entries(names).map(([key, name]) => `<div class="metric"><b>${name}</b><br>${runtime[key]}</div>`).join('') + `<div class="metric"><b>current_offset</b><br>${id('offset')?.value || 0}</div><div class="metric"><b>next_offset</b><br>${next}</div><div class="metric"><b>estimated remaining</b><br>${remaining}</div>`;
    }

    async function run() {
        if (runtime.running) return;
        runtime.running = true; runtime.paused = false; runtime.stopped = false;
        try {
            while (!runtime.stopped) {
                if (runtime.paused) { await sleep(250); continue; }
                const current = reflectSelection('Preparing batch');
                const url = current.mode === 'dry'
                    ? `${current.endpoint}?${new URLSearchParams({channel:current.channel, limit:String(current.limit), offset:String(current.offset), local_write:'false', marketplace_write:'false'})}`
                    : current.endpoint;
                const options = current.mode === 'dry' ? {method:'GET'} : {
                    method:'POST',
                    headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content || ''},
                    body:JSON.stringify({channel:current.channel,limit:current.limit,offset:current.offset,confirm:id('confirm').value,local_write:true,marketplace_write:false})
                };
                const json = await request(url, options);
                id('last').textContent = JSON.stringify(json, null, 2);
                runtime.total = json.total_jarek_products || 0; runtime.withOffer = json.products_with_ebay_offer_id || 0;
                runtime.processed += json.count || 0; runtime.fetched += json.prices_fetched_from_ebay_count || 0;
                runtime.cached += current.mode === 'dry' ? 0 : (json.prices_fetched_from_ebay_count || 0);
                runtime.missing += json.prices_missing_count || 0; runtime.stale += json.stale_404_count || 0;
                const products = Array.isArray(json.products) ? json.products : [];
                runtime.skipped += Math.max(0, (json.count || 0) - (json.eligible_for_7_percent_increase || 0));
                runtime.errors += products.filter(item => item.http_status >= 400 && item.http_status !== 404).length;
                id('errors').classList.remove('error'); id('errors').textContent = JSON.stringify(products.filter(item => item.ebay_error).slice(0, 10), null, 2);
                const next = Number(json.next_offset) || (current.offset + (json.count || 0));
                id('offset').value = next; setDebug('offset', next); draw(next);
                if (!json.count || next >= runtime.withOffer) break;
                await sleep(Math.max(1500, Number(id('delay').value) || 2000));
            }
        } catch (_) { /* request() has rendered a diagnostic */ }
        finally { runtime.running = false; }
    }

    async function testDryRun() {
        const root = document.getElementById(rootId);
        const url = `${root.dataset.previewUrl}?${new URLSearchParams({channel:'ebay_de',limit:'1',offset:'0'})}`;
        setDebug('last_action', 'Test dry-run request');
        try { id('last').textContent = JSON.stringify(await request(url, {method:'GET'}), null, 2); } catch (_) {}
    }

    if (!runtime.bound) {
        document.addEventListener('click', event => {
            const button = event.target.closest('button');
            if (!button || !document.getElementById(rootId)) return;
            if (button.id === 'fetch-runner-start') {
                runtime.startClickCount++;
                setDebug('start_click_count', runtime.startClickCount);
                reflectSelection('Start clicked');
                id('action').textContent = 'Start clicked';
                run();
            } else if (button.id === 'fetch-runner-pause') { runtime.paused = true; reflectSelection('Pause clicked'); id('action').textContent = 'Pause clicked'; }
            else if (button.id === 'fetch-runner-resume') { runtime.paused = false; reflectSelection('Resume clicked'); id('action').textContent = 'Resume clicked'; run(); }
            else if (button.id === 'fetch-runner-stop') { runtime.stopped = true; runtime.aborter?.abort(); reflectSelection('Stop clicked'); id('action').textContent = 'Stop clicked'; }
            else if (button.id === 'fetch-runner-test') { testDryRun(); }
        });
        runtime.bound = true;
    }

    initialize();
    document.addEventListener('DOMContentLoaded', initialize);
    document.addEventListener('livewire:navigated', initialize);
    document.addEventListener('filament:navigated', initialize);
})();
</script>
</body>
</html>
