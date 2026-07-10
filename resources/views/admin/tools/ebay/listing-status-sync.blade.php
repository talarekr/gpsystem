<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>eBay listing status sync dry-run</title>
    <style>body{font-family:system-ui,sans-serif;margin:24px}.ok{color:#166534}.error{color:#991b1b}.panel{border:1px solid #ddd;padding:16px;margin:16px 0}label{display:block;margin:8px 0}button{padding:8px 12px;margin:6px 6px 0 0}button:disabled{opacity:.55;cursor:not-allowed}.muted{color:#6b7280}.status-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px}.status-card{background:#f9fafb;border:1px solid #e5e7eb;padding:10px}.status-card strong{display:block;font-size:1.35rem}pre{background:#f3f4f6;padding:16px;overflow:auto}.banner{padding:10px 12px;background:#eff6ff;border:1px solid #bfdbfe;margin:10px 0}</style>
</head>
<body>
<div data-marker="{{ $pageMarker }}" data-browser-autorun-marker="ebay_listing_status_batch_runner_browser_autorun_v2" data-delay-countdown-fix-marker="ebay_listing_status_batch_runner_delay_countdown_fix_v4">
    <h1>eBay listing status sync dry-run</h1>
    @if(session('runner_message'))<div class="ok">{{ session('runner_message') }}</div>@endif
    @if(session('runner_error'))<div class="error">{{ session('runner_error') }}</div>@endif
    <div id="autoRunMessage" class="banner muted">Auto-run nieaktywny</div>
    <div class="panel">
        <h2>Aktualny status</h2>
        <div id="statusGrid" class="status-grid"></div>
        <p><strong>Ostatni batch:</strong> <span id="lastBatch">—</span></p>
        <p><strong>Ostatni błąd:</strong> <span id="lastError">—</span></p>
        <h3>recent_results</h3>
        <pre id="recentResults">[]</pre>
        <pre id="rawStatus">{{ json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        <p><a href="{{ route('admin.tools.ebay.listing-status-sync.diagnose', ['json' => 1]) }}">Diagnostyka read-only JSON</a></p>
    </div>
    <div class="panel">
        <h2>Akcje runnera</h2>
        <form id="startForm" method="POST" action="{{ route('admin.tools.ebay.listing-status-sync.start') }}">
            @csrf
            <input type="hidden" name="confirm" value="start-ebay-listing-status-sync">
            <input type="hidden" name="scope" value="products_with_ebay_item_id">
            <input type="hidden" name="dry_run" value="1">
            <label>Batch size <input name="batch_size" value="5" type="number" min="1" max="20"></label>
            <label>Delay seconds <input name="delay_seconds" value="10" type="number" min="5"></label>
            <button id="startButton" type="submit">Start dry-run</button>
        </form>
        <form id="runNextForm" method="POST" action="{{ route('admin.tools.ebay.listing-status-sync.run-next-batch') }}">
            @csrf
            <input type="hidden" name="confirm" value="run-next-ebay-listing-status-sync-batch">
            <button id="runNextButton" type="submit">Uruchom następny batch</button>
        </form>
        <form id="stopForm" method="POST" action="{{ route('admin.tools.ebay.listing-status-sync.stop') }}">
            @csrf
            <input type="hidden" name="confirm" value="stop-ebay-listing-status-sync">
            <button id="stopButton" type="submit">Stop</button>
        </form>
    </div>
</div>
<script>
(() => {
    const statusUrl = @json(route('admin.tools.ebay.listing-status-sync.status', ['json' => 1]));
    const startUrl = @json(route('admin.tools.ebay.listing-status-sync.start'));
    const runNextBatchUrl = @json(route('admin.tools.ebay.listing-status-sync.run-next-batch'));
    const stopUrl = @json(route('admin.tools.ebay.listing-status-sync.stop'));
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || document.querySelector('input[name="_token"]')?.value || '';
    const tabId = `${Date.now()}-${Math.random().toString(36).slice(2)}`;
    const lockKey = 'ebay_listing_status_sync_browser_autorun_lock_v2';
    const lockTtlMs = 45000;
    let autoRunActive = false;
    let requestInFlight = false;
    let timerId = null;
    let countdownTimerId = null;
    let nextRunAt = null;
    let networkErrors = 0;

    const terminalStatuses = ['completed', 'stopped', 'failed'];
    const message = document.getElementById('autoRunMessage');
    const runNextButton = document.getElementById('runNextButton');

    function setMessage(text, cls = 'muted') { message.textContent = text; message.className = `banner ${cls}`; }
    function delayFromStatus(status) { return Math.max(0, Number(status?.delay_seconds || 10)); }
    function retryFromStatus(status) {
        const fallback = delayFromStatus(status);
        const retry = status?.retry_after_seconds;
        if (retry === null || retry === undefined || retry === '') return fallback;
        const seconds = Math.max(0, Number(retry));
        return status?.clock_skew_detected ? seconds : Math.min(seconds, fallback);
    }
    function clearTimer() {
        if (timerId) window.clearTimeout(timerId);
        if (countdownTimerId) window.clearInterval(countdownTimerId);
        timerId = null;
        countdownTimerId = null;
        nextRunAt = null;
    }
    function lockValue() { try { return JSON.parse(localStorage.getItem(lockKey) || 'null'); } catch { return null; } }
    function ownsLock() { const lock = lockValue(); return lock?.tabId === tabId && Number(lock.expiresAt || 0) > Date.now(); }
    function acquireLock() { const lock = lockValue(); if (lock && lock.tabId !== tabId && Number(lock.expiresAt || 0) > Date.now()) return false; localStorage.setItem(lockKey, JSON.stringify({tabId, expiresAt: Date.now() + lockTtlMs})); return true; }
    function refreshLock() { if (ownsLock()) localStorage.setItem(lockKey, JSON.stringify({tabId, expiresAt: Date.now() + lockTtlMs})); }
    function releaseLock() { if (ownsLock()) localStorage.removeItem(lockKey); }

    async function readResponse(response) {
        const text = await response.text();
        let json = null;
        try { json = text ? JSON.parse(text) : null; } catch {}
        if (!response.ok) {
            const reason = json?.reason || json?.message || (response.status === 419 ? 'Sesja wygasła (419)' : `HTTP ${response.status}`);
            const error = new Error(reason);
            error.status = response.status;
            error.payload = json;
            throw error;
        }
        return json || {ok: true, raw: text};
    }
    async function apiPost(url, payload) {
        const response = await fetch(url, {method: 'POST', credentials: 'same-origin', headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'X-Requested-With': 'XMLHttpRequest'}, body: JSON.stringify(payload)});
        return readResponse(response);
    }
    async function fetchStatus() {
        const response = await fetch(statusUrl, {credentials: 'same-origin', headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}});
        return readResponse(response);
    }
    function renderStatus(status) {
        const keys = ['status','total','processed','remaining','active','ended','not_found','invalid','unknown','failed'];
        document.getElementById('statusGrid').innerHTML = keys.map(k => `<div class="status-card"><span>${k}</span><strong>${status?.[k] ?? '—'}</strong></div>`).join('');
        document.getElementById('lastBatch').textContent = status?.last_batch_at || '—';
        document.getElementById('lastError').textContent = status?.last_error || '—';
        document.getElementById('recentResults').textContent = JSON.stringify(status?.recent_results || [], null, 2);
        document.getElementById('rawStatus').textContent = JSON.stringify(status || {}, null, 2);
        runNextButton.disabled = autoRunActive || requestInFlight;
    }
    function stopAutoRun(text, cls = 'muted') { autoRunActive = false; clearTimer(); releaseLock(); setMessage(text, cls); runNextButton.disabled = requestInFlight; }
    function scheduleNext(status, overrideSeconds = null) {
        clearTimer();
        const seconds = Math.max(0, Number(overrideSeconds ?? retryFromStatus(status)));
        nextRunAt = Date.now() + (seconds * 1000);
        const renderCountdown = () => {
            const remaining = Math.max(0, Math.ceil((nextRunAt - Date.now()) / 1000));
            setMessage(`Oczekiwanie ${remaining} s na następny batch`, 'muted');
        };
        renderCountdown();
        if (seconds > 0) countdownTimerId = window.setInterval(renderCountdown, 1000);
        timerId = window.setTimeout(autoRunStep, seconds * 1000);
    }
    async function autoRunStep() {
        if (!autoRunActive || requestInFlight) return;
        if (!acquireLock()) { setMessage('Auto-run aktywny w innej karcie — ta karta tylko pokazuje status', 'muted'); scheduleNext(await fetchStatus()); return; }
        refreshLock();
        requestInFlight = true;
        runNextButton.disabled = true;
        try {
            let status = await fetchStatus();
            renderStatus(status);
            if (terminalStatuses.includes(status.status)) return stopAutoRun(status.status === 'completed' ? 'Synchronizacja zakończona' : (status.status === 'stopped' ? 'Runner zatrzymany' : 'Błąd — auto-run zatrzymany'), status.status === 'failed' ? 'error' : 'ok');
            if (status.status === 'running') {
                setMessage('Batch w trakcie', 'muted');
                const result = await apiPost(runNextBatchUrl, {confirm: 'run-next-ebay-listing-status-sync-batch'});
                renderStatus(result);
                if (result.reason === 'delay_not_elapsed' || result.should_wait || result.retry_after_seconds) return scheduleNext(result, retryFromStatus(result));
                if (terminalStatuses.includes(result.status)) return stopAutoRun(result.status === 'completed' ? 'Synchronizacja zakończona' : (result.status === 'stopped' ? 'Runner zatrzymany' : 'Błąd — auto-run zatrzymany'), result.status === 'failed' ? 'error' : 'ok');
                networkErrors = 0;
                return scheduleNext(result);
            }
            stopAutoRun('Auto-run nieaktywny');
        } catch (error) {
            if ([401, 403, 419].includes(error.status)) return stopAutoRun('Błąd autoryzacji lub wygasła sesja — auto-run zatrzymany', 'error');
            networkErrors++;
            if (networkErrors >= 3) return stopAutoRun('Błąd — auto-run zatrzymany', 'error');
            setMessage(`Błąd sieci (${networkErrors}/3) — ponawiam po 10 s`, 'error');
            timerId = window.setTimeout(autoRunStep, 10000);
        } finally {
            requestInFlight = false;
            runNextButton.disabled = autoRunActive;
        }
    }
    function startAutoRun(initialStatus) { if (autoRunActive) return; if (!acquireLock()) { setMessage('Auto-run aktywny w innej karcie — ta karta tylko pokazuje status', 'muted'); return; } autoRunActive = true; networkErrors = 0; setMessage('Auto-run aktywny', 'ok'); renderStatus(initialStatus); clearTimer(); timerId = window.setTimeout(autoRunStep, 0); }

    document.getElementById('startForm').addEventListener('submit', async (event) => {
        event.preventDefault();
        if (requestInFlight) return;
        requestInFlight = true;
        try {
            const data = Object.fromEntries(new FormData(event.currentTarget).entries());
            const result = await apiPost(startUrl, {batch_size: Number(data.batch_size || 5), delay_seconds: Number(data.delay_seconds || 10), scope: 'products_with_ebay_item_id', dry_run: true, confirm: 'start-ebay-listing-status-sync'});
            renderStatus(result);
            startAutoRun(result);
        } catch (error) { stopAutoRun(error.message || 'Błąd — auto-run zatrzymany', 'error'); }
        finally { requestInFlight = false; }
    });
    document.getElementById('runNextForm').addEventListener('submit', async (event) => { event.preventDefault(); if (autoRunActive || requestInFlight) return; requestInFlight = true; try { renderStatus(await apiPost(runNextBatchUrl, {confirm: 'run-next-ebay-listing-status-sync-batch'})); } catch (e) { setMessage(e.message || 'Błąd ręcznego batcha', 'error'); } finally { requestInFlight = false; runNextButton.disabled = false; } });
    document.getElementById('stopForm').addEventListener('submit', async (event) => { event.preventDefault(); clearTimer(); autoRunActive = false; releaseLock(); requestInFlight = true; try { renderStatus(await apiPost(stopUrl, {confirm: 'stop-ebay-listing-status-sync'})); setMessage('Runner zatrzymany', 'ok'); renderStatus(await fetchStatus()); } catch (e) { stopAutoRun(e.message || 'Błąd — auto-run zatrzymany', 'error'); } finally { requestInFlight = false; runNextButton.disabled = false; } });
    window.addEventListener('beforeunload', releaseLock);

    const initialStatus = @json($status);
    renderStatus(initialStatus);
    if (initialStatus?.status === 'running') startAutoRun(initialStatus);
})();
</script>
</body>
</html>
