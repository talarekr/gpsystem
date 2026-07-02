<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title>Skrzynie Jarka Allegro import runner</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 2rem; line-height: 1.4; }
        input, select, button { font: inherit; margin: .25rem; padding: .35rem .55rem; }
        pre { background: #111827; color: #e5e7eb; padding: 1rem; overflow: auto; max-height: 55vh; }
        .ok { color: #047857; } .error { color: #b91c1c; }
    </style>
</head>
<body>
<h1>Skrzynie Jarka Allegro import runner</h1>
<p>Runner wykonuje małe requesty HTTP bez queue workera i bez zapisów marketplace. Apply jest dodatkowo ograniczony po stronie serwisu do maks. {{ $maxApplyBatchSize }} ofert na request.</p>
<label>Tryb
    <select id="mode"><option value="dry-run">dry-run</option><option value="apply">apply</option></select>
</label>
<label>Batch size <input id="batch" type="number" min="1" max="{{ $maxApplyBatchSize }}" value="{{ $defaultBatchSize }}"></label>
<label>Start offset <input id="offset" type="number" min="0" value="0"></label>
<button id="start">Start/Resume</button>
<button id="stop">Stop</button>
<button id="status">Status</button>
<p id="summary">Gotowy.</p>
<pre id="log"></pre>
<script>
const endpoints = {
    dry: '/admin/tools/jarek-gearboxes/allegro-import-dry-run',
    apply: '/admin/tools/jarek-gearboxes/allegro-import-apply?confirm=jarek-gearboxes-import',
    status: '/admin/tools/jarek-gearboxes/import-status',
};
let running = false;
const log = (message, data = null) => {
    document.getElementById('log').textContent += `[${new Date().toISOString()}] ${message}` + (data ? `\n${JSON.stringify(data, null, 2)}` : '') + '\n';
};
async function request(url) {
    const response = await fetch(url, {headers: {Accept: 'application/json'}});
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || data.message || `HTTP ${response.status}`);
    return data;
}
async function tick() {
    if (!running) return;
    const batch = Math.max(1, Math.min({{ $maxApplyBatchSize }}, parseInt(document.getElementById('batch').value || '{{ $defaultBatchSize }}', 10)));
    const offsetInput = document.getElementById('offset');
    const offset = Math.max(0, parseInt(offsetInput.value || '0', 10));
    const mode = document.getElementById('mode').value;
    const separator = mode === 'apply' ? '&' : '?';
    try {
        const data = await request((mode === 'apply' ? endpoints.apply : endpoints.dry) + `${separator}limit=${batch}&offset=${offset}`);
        log(`${mode} offset=${offset} limit=${batch}`, data);
        document.getElementById('summary').innerHTML = `<span class="ok">OK</span> offset=${offset}, found=${data.found ?? data.effective_limit}, created=${data.created ?? 0}, updated=${data.updated ?? 0}`;
        offsetInput.value = offset + batch;
        if (!data.has_more_after_limit || data.found === 0) { running = false; log('Runner stopped: no more offers after this batch.'); return; }
        setTimeout(tick, 900);
    } catch (error) {
        running = false;
        document.getElementById('summary').innerHTML = `<span class="error">ERROR</span> ${error.message}`;
        log('Runner stopped on error: ' + error.message);
    }
}
document.getElementById('start').onclick = () => { if (!running) { running = true; log('Runner started.'); tick(); } };
document.getElementById('stop').onclick = () => { running = false; log('Runner stopped manually.'); };
document.getElementById('status').onclick = async () => log('status', await request(endpoints.status));
</script>
</body>
</html>
