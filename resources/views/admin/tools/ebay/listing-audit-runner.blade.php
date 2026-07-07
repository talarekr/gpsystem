<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>eBay listing audit runner</title>
    <style>
        body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;margin:24px;line-height:1.45;color:#172033}button,input,select{font:inherit}button{margin-right:8px;padding:8px 12px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:16px 0}.card{border:1px solid #d8dee9;border-radius:8px;padding:12px;background:#fff}.muted{color:#687385}.bar{height:16px;background:#edf2f7;border-radius:999px;overflow:hidden}.bar>span{display:block;height:100%;background:#2563eb;width:0}.status{font-weight:700}.running{color:#b26a00}.paused{color:#6b46c1}.stopped{color:#7b341e}.completed{color:#087f23}pre{background:#111827;color:#e5e7eb;padding:12px;border-radius:8px;overflow:auto;max-height:360px}.controls{display:flex;gap:8px;align-items:end;flex-wrap:wrap}.controls label{display:flex;flex-direction:column;font-size:13px;color:#374151}.controls input,.controls select{padding:7px;min-width:120px}.warning{background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:12px}
    </style>
</head>
<body>
<h1>eBay listing audit runner</h1>
<p class="warning">GET batch runner jest read-only. Tryb apply pozostaje wyłącznie świadomym POST z CSRF i <code>confirm=mark-ebay-ended-historical</code>; panel nie usuwa URL-i, nie relistuje i nie wykonuje mutacji marketplace.</p>

<div class="controls">
    <label>Kanał<select id="channel"><option value="{{ $defaultChannel }}">{{ $defaultChannel }}</option><option value="ebay_fr">ebay_fr</option><option value="ebay">ebay</option></select></label>
    <label>Batch size<input id="batchSize" type="number" min="1" max="20" value="{{ $defaultBatchSize }}"></label>
    <label>Delay ms<input id="delayMs" type="number" min="0" step="500" value="{{ $defaultDelayMs }}"></label>
    <button id="startBtn">Start</button>
    <button id="pauseBtn" disabled>Pause</button>
    <button id="resumeBtn" disabled>Resume</button>
    <button id="stopBtn" disabled>Stop</button>
</div>

<div class="grid">
    <div class="card"><div class="muted">run_id</div><code id="runId">—</code></div>
    <div class="card"><div class="muted">status</div><span id="status" class="status stopped">stopped</span></div>
    <div class="card"><div class="muted">processed_count</div><strong id="processed">0</strong></div>
    <div class="card"><div class="muted">total_count</div><strong id="total">0</strong></div>
    <div class="card"><div class="muted">remaining_count</div><strong id="remaining">0</strong></div>
    <div class="card"><div class="muted">progress</div><strong id="progressText">0%</strong><div class="bar"><span id="progressBar"></span></div></div>
</div>

<h2>summary_total</h2><pre id="summaryTotal">{}</pre>
<h2>summary_batch</h2><pre id="summaryBatch">{}</pre>
<h2>problem_samples</h2><pre id="problemSamples">[]</pre>
<h2>Ostatnia odpowiedź</h2><pre id="lastResponse">Kliknij Start, aby uruchomić pierwszy batch AJAX-em.</pre>

<script>
(() => {
    const endpoint = @json($batchEndpoint);
    let nextUrl = null;
    let timer = null;
    let state = 'stopped';
    const el = id => document.getElementById(id);
    const pretty = value => JSON.stringify(value ?? {}, null, 2);
    const setButtons = () => {
        el('startBtn').disabled = state === 'running' || state === 'paused';
        el('pauseBtn').disabled = state !== 'running';
        el('resumeBtn').disabled = state !== 'paused' || !nextUrl;
        el('stopBtn').disabled = state === 'stopped' || state === 'completed';
    };
    const setStatus = value => {
        state = value;
        el('status').textContent = value;
        el('status').className = 'status ' + value;
        setButtons();
    };
    const clearTimer = () => { if (timer) window.clearTimeout(timer); timer = null; };
    const render = data => {
        el('runId').textContent = data.run_id || '—';
        const processed = data.processed_count ?? data.offset_after ?? 0;
        const total = data.total_count ?? 0;
        const remaining = data.remaining_count ?? 0;
        const pct = total > 0 ? Math.min(100, Math.round((processed / total) * 10000) / 100) : 0;
        el('processed').textContent = processed;
        el('total').textContent = total;
        el('remaining').textContent = remaining;
        el('progressText').textContent = pct + '%';
        el('progressBar').style.width = pct + '%';
        el('summaryTotal').textContent = pretty(data.summary_total);
        el('summaryBatch').textContent = pretty(data.summary_batch);
        el('problemSamples').textContent = pretty(data.problem_samples || []);
        el('lastResponse').textContent = pretty(data);
        nextUrl = data.next_url;
        if (data.completed) setStatus('completed');
    };
    const runBatch = async url => {
        clearTimer();
        setStatus('running');
        const response = await fetch(url, {headers: {'Accept': 'application/json'}});
        const data = await response.json();
        if (!response.ok || data.ok === false) throw new Error(data.message || 'Batch request failed');
        render(data);
        if (!data.completed && state === 'running' && nextUrl) {
            timer = window.setTimeout(() => runBatch(nextUrl).catch(showError), Math.max(0, Number(el('delayMs').value || 5000)));
        }
    };
    const showError = error => { clearTimer(); setStatus('paused'); el('lastResponse').textContent = String(error.stack || error); };
    el('startBtn').addEventListener('click', () => {
        const params = new URLSearchParams({channel: el('channel').value, batch_size: el('batchSize').value, start: '1'});
        runBatch(endpoint + '?' + params.toString()).catch(showError);
    });
    el('pauseBtn').addEventListener('click', () => { clearTimer(); setStatus('paused'); });
    el('resumeBtn').addEventListener('click', () => { if (nextUrl) runBatch(nextUrl).catch(showError); });
    el('stopBtn').addEventListener('click', () => { clearTimer(); nextUrl = null; setStatus('stopped'); });
    setButtons();
})();
</script>
</body>
</html>
