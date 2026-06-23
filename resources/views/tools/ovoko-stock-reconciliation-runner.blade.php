<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ovoko stock reconciliation autorunner</title>
    <style>
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 2rem; color: #172033; background: #f7f8fb; }
        main { max-width: 1100px; margin: 0 auto; background: #fff; border: 1px solid #d8dee9; border-radius: 12px; padding: 1.5rem; }
        h1 { margin-top: 0; }
        .notice { padding: 0.75rem 1rem; border-radius: 8px; background: #fff7d6; border: 1px solid #f0d36c; font-weight: 700; }
        .controls, .params { display: flex; flex-wrap: wrap; gap: 0.75rem; margin: 1rem 0; }
        label { display: grid; gap: 0.25rem; font-size: 0.9rem; }
        input { padding: 0.45rem; border: 1px solid #b8c0cc; border-radius: 6px; width: 9rem; }
        button { padding: 0.55rem 0.9rem; border: 0; border-radius: 6px; cursor: pointer; background: #214fbd; color: #fff; font-weight: 700; }
        button.secondary { background: #5f6b7a; }
        button.danger { background: #b3261e; }
        dl { display: grid; grid-template-columns: minmax(220px, 1fr) 2fr; gap: 0.45rem 1rem; }
        dt { font-weight: 700; color: #334155; }
        dd { margin: 0; word-break: break-word; }
        pre { background: #0f172a; color: #e2e8f0; padding: 1rem; border-radius: 8px; overflow: auto; max-height: 18rem; }
        .error { color: #b3261e; font-weight: 700; }
    </style>
</head>
<body>
<main>
    <h1>Ovoko stock reconciliation autorunner</h1>
    <p class="notice">DRY-RUN ONLY — no local or marketplace writes performed.</p>

    <section class="params" aria-label="Runner parameters">
        <label>ovoko_limit <input id="ovoko_limit" type="number" min="1" max="100" value="100"></label>
        <label>max_ovoko_pages <input id="max_ovoko_pages" type="number" min="1" max="200" value="200"></label>
        <label>local_limit <input id="local_limit" type="number" min="1" max="100" value="100"></label>
        <label>max_local_pages <input id="max_local_pages" type="number" min="1" max="500" value="500"></label>
        <label>sample_limit <input id="sample_limit" type="number" min="1" max="200" value="50"></label>
        <label>delay_ms <input id="delay_ms" type="number" min="0" max="10000" value="200"></label>
    </section>

    <section class="controls">
        <button id="start">Start dry-run</button>
        <button id="stop" class="danger">Stop</button>
        <button id="resume" class="secondary">Resume</button>
        <button id="reset" class="secondary">Reset</button>
    </section>

    <p id="message" class="error"></p>

    <dl id="status"></dl>

    <h2>blockers</h2>
    <pre id="blockers">[]</pre>
    <h2>warnings</h2>
    <pre id="warnings">[]</pre>
    <h2>sample_would_mark_needs_review</h2>
    <pre id="sample_would_mark_needs_review">[]</pre>
    <h2>sample_conflicts</h2>
    <pre id="sample_conflicts">[]</pre>
</main>
<script>
(() => {
    const token = @json($token);
    const storageKey = 'ovoko_stock_reconciliation_runner_state';
    const snapshotEndpoint = '/tools/run-ovoko-stock-snapshot-step';
    const reconciliationEndpoint = '/tools/run-ovoko-stock-reconciliation-step';
    const defaults = { status: 'idle', stage: 'idle', snapshot_id: '', snapshot_complete: false, snapshot_next_page: 1, run_id: '', run_complete: false, run_next_page: 1 };
    let state = { ...defaults, ...JSON.parse(localStorage.getItem(storageKey) || '{}') };
    let stopped = true;

    const fields = ['status','snapshot_id','run_id','stage','page_fetched','snapshot_next_page','pages_fetched_total','ovoko_api_total_count','ovoko_active_ids_count','run_next_page','local_candidate_parts_count_total','matched_active_ovoko_count_total','missing_in_ovoko_active_count_total','would_mark_needs_review_count_total','already_needs_review_count_total','conflict_count_total'];
    const param = id => document.getElementById(id).value;
    const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
    const save = () => localStorage.setItem(storageKey, JSON.stringify(state));
    const setState = patch => { state = { ...state, ...patch }; save(); render(); };

    function render() {
        document.getElementById('status').innerHTML = fields.map(key => `<dt>${key}</dt><dd>${state[key] ?? ''}</dd>`).join('');
        for (const key of ['blockers','warnings','sample_would_mark_needs_review','sample_conflicts']) {
            document.getElementById(key).textContent = JSON.stringify(state[key] ?? [], null, 2);
        }
        document.getElementById('message').textContent = state.error_message || '';
    }

    async function getJson(url) {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 60000);
        try {
            const response = await fetch(url, { headers: { 'Accept': 'application/json' }, signal: controller.signal });
            const text = await response.text();
            let data;
            try { data = JSON.parse(text); } catch (error) { throw new Error(`JSON parse error: ${error.message}`); }
            if (!response.ok) throw new Error(`HTTP ${response.status}: ${JSON.stringify(data)}`);
            if (data.ok === false || (Array.isArray(data.blockers) && data.blockers.length > 0)) {
                setState({ ...data, status: 'error', stage: 'error', blockers: data.blockers || [], error_message: 'Runner stopped because response contains blockers or ok=false.' });
                stopped = true;
                return null;
            }
            return data;
        } finally {
            clearTimeout(timeout);
        }
    }

    async function loop() {
        stopped = false;
        setState({ status: state.snapshot_complete ? 'scanning_local' : 'building_snapshot', stage: state.snapshot_complete ? 'scanning_local' : 'building_snapshot', error_message: '' });
        while (!stopped) {
            try {
                if (!state.snapshot_complete) {
                    const url = new URL(snapshotEndpoint, window.location.origin);
                    url.searchParams.set('token', token);
                    url.searchParams.set('limit', param('ovoko_limit'));
                    url.searchParams.set('max_pages', param('max_ovoko_pages'));
                    if (state.snapshot_id) url.searchParams.set('snapshot_id', state.snapshot_id);
                    if (state.snapshot_next_page) url.searchParams.set('page', state.snapshot_next_page);
                    const data = await getJson(url);
                    if (!data) return;
                    setState({ ...data, status: data.snapshot_complete ? 'scanning_local' : 'building_snapshot', stage: 'building_snapshot', snapshot_id: data.snapshot_id, snapshot_complete: !!data.snapshot_complete, snapshot_next_page: data.next_page });
                } else if (!state.run_complete) {
                    const url = new URL(reconciliationEndpoint, window.location.origin);
                    url.searchParams.set('token', token);
                    url.searchParams.set('snapshot_id', state.snapshot_id);
                    url.searchParams.set('local_limit', param('local_limit'));
                    url.searchParams.set('sample_limit', param('sample_limit'));
                    url.searchParams.set('max_local_pages', param('max_local_pages'));
                    if (state.run_id) url.searchParams.set('run_id', state.run_id);
                    if (state.run_next_page) url.searchParams.set('page', state.run_next_page);
                    const data = await getJson(url);
                    if (!data) return;
                    setState({ ...data, status: data.run_complete ? 'complete' : 'scanning_local', stage: 'scanning_local', run_id: data.run_id, run_complete: !!data.run_complete, run_next_page: data.next_page });
                } else {
                    setState({ status: 'complete', stage: 'complete' });
                    stopped = true;
                    return;
                }
                await sleep(Number(param('delay_ms')) || 0);
            } catch (error) {
                stopped = true;
                setState({ status: 'error', stage: 'error', error_message: error.message });
                return;
            }
        }
        if (!state.run_complete) setState({ status: 'stopped' });
    }

    document.getElementById('start').addEventListener('click', () => { state = { ...defaults }; save(); loop(); });
    document.getElementById('stop').addEventListener('click', () => { stopped = true; setState({ status: 'stopped' }); });
    document.getElementById('resume').addEventListener('click', () => { if (state.status !== 'complete') loop(); });
    document.getElementById('reset').addEventListener('click', () => { stopped = true; state = { ...defaults }; save(); render(); });
    render();
})();
</script>
</body>
</html>
