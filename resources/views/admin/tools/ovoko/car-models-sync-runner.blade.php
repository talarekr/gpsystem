<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Ovoko car models sync runner</title>
    <style>
        body{font-family:Inter,ui-sans-serif,system-ui,Arial,sans-serif;background:#f8fafc;color:#0f172a;margin:0;padding:24px}.wrap{max-width:1180px;margin:auto}.card{background:white;border:1px solid #e2e8f0;border-radius:14px;padding:18px;margin:0 0 16px;box-shadow:0 1px 2px #0000000d}.safe{border-color:#10b981;background:#ecfdf5}.warn{border-color:#f59e0b;background:#fffbeb}.row{display:flex;gap:10px;flex-wrap:wrap;align-items:end}.stack{display:flex;flex-direction:column;gap:10px}.btn{border:0;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block}.primary{background:#2563eb;color:white}.secondary{background:#e2e8f0;color:#0f172a}.danger{background:#dc2626;color:white}.debug{background:#7c3aed;color:white}.muted{color:#64748b}.helper{color:#475569;font-size:13px;max-width:760px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px}.metric{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:12px}.metric span{display:block;font-size:12px;color:#64748b}.metric b{display:block;font-size:20px;margin-top:4px}.badge{display:inline-block;border-radius:999px;padding:4px 10px;background:#e2e8f0;font-weight:800}.running,.queued{background:#dbeafe}.completed{background:#dcfce7}.failed,.stopped{background:#fee2e2}.bar{height:22px;background:#e2e8f0;border-radius:999px;overflow:hidden}.bar span{display:block;height:100%;width:0;background:#22c55e;transition:width .25s}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}label{display:flex;flex-direction:column;gap:4px;font-size:13px;font-weight:700}.checkbox-label{align-items:flex-start;gap:6px}input[type=number]{border:1px solid #cbd5e1;border-radius:9px;padding:9px;min-width:120px}table{width:100%;border-collapse:collapse}td,th{border-bottom:1px solid #e2e8f0;text-align:left;padding:8px;font-size:13px;vertical-align:top}pre{white-space:pre-wrap;word-break:break-word;background:#0f172a;color:#e2e8f0;border-radius:12px;padding:12px;max-height:360px;overflow:auto}.hidden{display:none}.autorun-on{border-color:#2563eb;background:#eff6ff}.autorun-off{border-color:#cbd5e1;background:#f8fafc}.autorun-stopped{border-color:#f59e0b;background:#fffbeb}.inline-check{display:flex;flex-direction:row;align-items:center;gap:8px;font-size:14px}.status-line{font-weight:800}
    </style>
</head>
<body>
<div class="wrap">
    <h1>Ovoko car models sync runner</h1>
    <div class="card safe">GET tej strony jest read-only. Start, Stop i Run next batch działają tylko przez POST z CSRF i tokenem confirm. Wejście na stronę nie uruchamia synchronizacji.</div>
    @if(session('runner_message'))<div class="card safe">{{ session('runner_message') }}</div>@endif
    @if(session('runner_error'))<div class="card warn">{{ session('runner_error') }}</div>@endif

    <div class="card">
        <h2>Instrukcja kolejności</h2>
        <ol>
            <li><strong>Krok 1:</strong> Synchronizuj marki i słowniki bazowe.</li>
            <li><strong>Krok 2:</strong> Uruchom runner modeli.</li>
        </ol>
        <ul>
            <li>Jeśli chcesz uzupełnić tylko brakujące modele, zostaw zaznaczone „Tylko marki bez modeli”.</li>
            <li>Jeśli chcesz pełne odświeżenie modeli, odznacz „Tylko marki bez modeli”.</li>
        </ul>
        <p class="helper"><strong>Aby odświeżyć modele wszystkich marek, odznacz ‘Tylko marki bez modeli’ i kliknij Start.</strong></p>
    </div>

    <div class="card">
        <h2>Status <span id="statusBadge" class="badge {{ $status['status'] ?? 'idle' }}">{{ $status['status'] ?? 'idle' }}</span></h2>
        <div class="bar"><span id="progressBar"></span></div>
        <p class="muted"><span id="progressText">—</span></p>
        <div class="grid" id="statusGrid"></div>
    </div>

    <div class="card">
        <h2>Synchronizuj marki i słowniki bazowe</h2>
        <p class="helper">Pobiera marki oraz słowniki bazowe Ovoko. Nie pobiera modeli wszystkich marek — do tego służy runner poniżej.</p>
        <form method="POST" action="{{ route('admin.tools.ovoko.sync-car-dictionaries') }}" onsubmit="return confirm('Synchronizować marki i słowniki bazowe Ovoko?');">
            @csrf
            <input type="hidden" name="scope" value="all">
            <input type="hidden" name="confirm" value="sync-ovoko-car-dictionaries">
            <button class="btn secondary" type="submit">Synchronizuj marki i słowniki bazowe</button>
        </form>
    </div>

    <div class="card">
        <h2>Runner modeli</h2>
        <div class="row">
            <form method="POST" action="{{ route('admin.tools.ovoko.car-models-sync-runner.start') }}" onsubmit="return confirm('Uruchomić Ovoko car models sync runner?');">
                @csrf
                <input type="hidden" name="confirm" value="start-ovoko-car-models-sync-runner">
                <label>batch_size <input type="number" name="batch_size" value="5" min="1" max="10"></label>
                <label>delay_seconds <input type="number" name="delay_seconds" value="10" min="5" max="3600"></label>
                <input type="hidden" name="only_missing" value="0">
                <label class="checkbox-label">
                    <span>Tylko marki bez modeli</span>
                    <span><input type="checkbox" name="only_missing" value="1" checked> Tylko marki bez modeli</span>
                    <span class="helper">Zaznaczone: pobiera modele tylko dla marek, które nie mają jeszcze żadnych modeli w cache. Odznaczone: odświeża modele dla wszystkich marek.</span>
                </label>
                <button class="btn primary" type="submit">Start</button>
            </form>
            <form method="POST" action="{{ route('admin.tools.ovoko.car-models-sync-runner.stop') }}" onsubmit="return confirm('Zatrzymać Ovoko car models sync runner?');">
                @csrf
                <input type="hidden" name="confirm" value="stop-ovoko-car-models-sync-runner">
                <button class="btn danger" type="submit">Stop</button>
            </form>
            <form id="runNextForm" method="POST" action="{{ route('admin.tools.ovoko.car-models-sync-runner.run-next-batch') }}" onsubmit="return confirm('Uruchomić ręcznie następny batch runnera?');">
                @csrf
                <input type="hidden" name="confirm" value="run-next-batch-ovoko-car-models-sync-runner">
                <input id="runIdInput" type="hidden" name="run_id" value="{{ $runId ?? '' }}">
                <button class="btn debug" type="submit">Run next batch ręcznie</button>
            </form>
        </div>
    </div>

    {{-- ovoko_car_models_sync_runner_500_recovery_v6 --}}
    <div id="browserAutoRunCard" class="card autorun-off">
        <h2>Browser fallback auto-runner</h2>
        <label class="inline-check">
            <input id="browserAutoRunEnabled" type="checkbox" {{ in_array(($status['status'] ?? 'idle'), ['queued', 'running'], true) && (int) ($status['remaining_brand_count'] ?? 0) > 0 ? 'checked' : '' }}>
            <span>Auto-run w przeglądarce</span>
        </label>
        <p class="helper">Jeśli queue worker nie działa, ta strona wywoła kolejny batch co delay_seconds sekund. Zostaw kartę otwartą.</p>
        <p class="warn helper" style="padding:10px;border-radius:10px"><strong>Karta musi pozostać otwarta.</strong> Auto-run zatrzyma się po zakończeniu, błędzie, odznaczeniu opcji albo gdy nie ma już marek do przetworzenia.</p>
        <div class="grid">
            <div class="metric"><span>browser auto-run</span><b id="browserAutoRunState">—</b></div>
            <div class="metric"><span>następny batch</span><b id="browserAutoRunNextAt">—</b></div>
            <div class="metric"><span>ostatni wynik batcha</span><b id="browserAutoRunLastResult">—</b></div>
        </div>
    </div>

    <div id="runnerJsError" class="card warn hidden"><h2>Błąd panelu</h2><p id="runnerJsErrorText"></p></div>
    <div class="card"><h2>Ostatni batch</h2><div id="lastBatch"></div></div>
    <div class="card"><h2>Lista błędów</h2><div id="errors"></div></div>
    <div class="card"><h2>Przydatne adresy</h2><p>Status JSON: <a class="mono" href="{{ route('admin.tools.ovoko.car-models-sync-runner.status', ['json' => 1]) }}">{{ route('admin.tools.ovoko.car-models-sync-runner.status', ['json' => 1]) }}</a></p></div>
    <div class="card"><h2>Raw status</h2><pre id="rawStatus"></pre></div>
</div>
<script>
const statusUrl = @json(route('admin.tools.ovoko.car-models-sync-runner.status', ['json' => 1]));
const runNextBatchUrl = @json(route('admin.tools.ovoko.car-models-sync-runner.run-next-batch'));
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
const runNextConfirmToken = 'run-next-batch-ovoko-car-models-sync-runner';
const terminalStatuses = ['completed', 'stopped', 'failed'];
const runnableStatuses = ['running', 'queued'];
let refreshTimer = null;
let autoRunTimer = null;
let autoRunInFlight = false;
let latestStatus = @json($status);
let nextAutoRunAt = null;
let lastAutoRunResult = '—';
let consecutiveAutoRunErrors = 0;

function esc(v){return String(v ?? '—').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));}
function asInt(v){const n = Number(v || 0); return Number.isFinite(n) ? Math.trunc(n) : 0;}
function delayMs(data){return Math.max(5, asInt(data?.delay_seconds || 10)) * 1000;}
function canAutoRun(data){return runnableStatuses.includes(data?.status || 'idle') && asInt(data?.remaining_brand_count) > 0;}
function autoRunChecked(){return document.getElementById('browserAutoRunEnabled')?.checked === true;}
function formatTime(date){return date ? date.toLocaleTimeString() : '—';}
function asArray(value){return Array.isArray(value) ? value : (value && typeof value === 'object' ? Object.values(value) : []);}
function asObject(value){return value && typeof value === 'object' && !Array.isArray(value) ? value : {};}
function showPanelError(message){const box=document.getElementById('runnerJsError'); const text=document.getElementById('runnerJsErrorText'); if(box&&text){text.textContent=message; box.classList.remove('hidden');}}
function clearPanelError(){document.getElementById('runnerJsError')?.classList.add('hidden');}

function updateAutoRunPanel(message = null){
    const card = document.getElementById('browserAutoRunCard');
    const state = document.getElementById('browserAutoRunState');
    const next = document.getElementById('browserAutoRunNextAt');
    const last = document.getElementById('browserAutoRunLastResult');
    const active = autoRunChecked() && canAutoRun(latestStatus) && !autoRunInFlight && !!autoRunTimer;
    const waiting = autoRunChecked() && canAutoRun(latestStatus) && autoRunInFlight;
    card.classList.toggle('autorun-on', active || waiting);
    card.classList.toggle('autorun-off', !active && !waiting && !message);
    card.classList.toggle('autorun-stopped', !!message && !active && !waiting);
    state.textContent = message || (waiting ? 'aktywny — batch trwa' : (active ? 'aktywny' : 'nieaktywny'));
    next.textContent = active ? formatTime(nextAutoRunAt) : '—';
    last.textContent = lastAutoRunResult;
}

function stopAutoRun(message = 'zatrzymany'){
    clearTimeout(autoRunTimer);
    autoRunTimer = null;
    nextAutoRunAt = null;
    updateAutoRunPanel(message);
}

function scheduleAutoRun(data){
    if (!autoRunChecked()) return stopAutoRun('wyłączony przez użytkownika');
    if (!canAutoRun(data)) {
        clearTimeout(autoRunTimer);
        autoRunTimer = null;
        nextAutoRunAt = null;
        const status = data?.status || 'idle';
        return updateAutoRunPanel(terminalStatuses.includes(status) || asInt(data?.remaining_brand_count) <= 0 ? 'zatrzymany — koniec pracy' : 'nieaktywny');
    }
    if (autoRunInFlight || autoRunTimer) return updateAutoRunPanel();
    const ms = delayMs(data);
    nextAutoRunAt = new Date(Date.now() + ms);
    autoRunTimer = setTimeout(runNextBatchAutomatically, ms);
    updateAutoRunPanel();
}

async function runNextBatchAutomatically(){
    autoRunTimer = null;
    if (autoRunInFlight || !autoRunChecked() || !canAutoRun(latestStatus)) return scheduleAutoRun(latestStatus);
    autoRunInFlight = true;
    nextAutoRunAt = null;
    updateAutoRunPanel();
    const body = new URLSearchParams();
    body.set('confirm', runNextConfirmToken);
    if (latestStatus.run_id) body.set('run_id', latestStatus.run_id);
    try {
        const res = await fetch(runNextBatchUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Accept':'application/json', 'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8', 'X-CSRF-TOKEN': csrfToken, 'X-Requested-With':'XMLHttpRequest'},
            body
        });
        const result = await res.json().catch(() => ({}));
        lastAutoRunResult = res.ok && result.ok ? `OK, remaining=${result.remaining_brand_count ?? '—'}` : `Błąd: ${result.reason || result.error || res.status}`;
        if (!res.ok || !result.ok) {
            consecutiveAutoRunErrors += 1;
            autoRunInFlight = false;
            showPanelError(`Run next batch nie powiódł się (${consecutiveAutoRunErrors}/3): ${result.reason || result.error || res.status}`);
            await refresh(false);
            if (consecutiveAutoRunErrors >= 3) return stopAutoRun('zatrzymany — 3 kolejne błędy batcha');
            return scheduleAutoRun(latestStatus);
        }
        consecutiveAutoRunErrors = 0;
        clearPanelError();
        autoRunInFlight = false;
        await refresh(false);
    } catch (e) {
        lastAutoRunResult = `Błąd: ${e?.message || e}`;
        consecutiveAutoRunErrors += 1;
        showPanelError(`Request auto-run nie powiódł się (${consecutiveAutoRunErrors}/3): ${e?.message || e}`);
        autoRunInFlight = false;
        if (consecutiveAutoRunErrors >= 3) stopAutoRun('zatrzymany — 3 kolejne błędy requestu'); else scheduleAutoRun(latestStatus);
    }
}

function render(data){
    latestStatus = data || {};
    const status = data.status || 'idle';
    const badge = document.getElementById('statusBadge'); badge.textContent = status; badge.className = `badge ${status}`;
    const total = asInt(data.total_brand_count), processed = asInt(data.processed_brand_count), remaining = asInt(data.remaining_brand_count);
    const pct = total > 0 ? Math.min(100, Math.round(processed * 100 / total)) : (status === 'completed' ? 100 : 0);
    document.getElementById('progressBar').style.width = pct + '%';
    document.getElementById('progressText').textContent = `${processed} / ${total} marek (${pct}%), remaining=${remaining}`;
    const keys = ['batch_size','delay_seconds','only_missing','total_brand_count','processed_brand_count','remaining_brand_count','brands_with_models','brands_without_models','synced_models_count','failed_brand_count','started_at','updated_at','completed_at'];
    document.getElementById('statusGrid').innerHTML = keys.map(k => `<div class="metric"><span>${k}</span><b>${esc(data[k])}</b></div>`).join('');
    const lastBatch = asObject(data.last_batch);
    const brands = asArray(lastBatch.brands);
    document.getElementById('lastBatch').innerHTML = brands.length ? `<table><thead><tr><th>brand_id</th><th>brand_name</th><th>status</th><th>synced models</th><th>error</th></tr></thead><tbody>${brands.map(b => `<tr><td class="mono">${esc(b.brand_id)}</td><td>${esc(b.brand_name)}</td><td>${esc(b.status)}</td><td>${esc(b.models_count)}</td><td>${esc(b.error)}</td></tr>`).join('')}</tbody></table>` : '<p class="muted">Brak ostatniego batcha.</p>';
    const errors = asArray(data.errors);
    document.getElementById('errors').innerHTML = errors.length ? `<table><thead><tr><th>brand_id</th><th>brand_name</th><th>error</th></tr></thead><tbody>${errors.map(e => `<tr><td class="mono">${esc(e.brand_id)}</td><td>${esc(e.brand_name)}</td><td>${esc(e.error)}</td></tr>`).join('')}</tbody></table>` : '<p class="muted">Brak błędów.</p>';
    document.getElementById('rawStatus').textContent = JSON.stringify(data, null, 2);
    document.getElementById('runNextForm').classList.toggle('hidden', !runnableStatuses.includes(status));
    if (data.run_id) document.getElementById('runIdInput').value = data.run_id;
    clearTimeout(refreshTimer); if (runnableStatuses.includes(status)) refreshTimer = setTimeout(() => refresh(true), 7000);
    scheduleAutoRun(data);
}

async function refresh(keepAutoSchedule = true){
    try{
        const res = await fetch(statusUrl, {headers:{Accept:'application/json'}, credentials:'same-origin'});
        const data = await res.json();
        clearPanelError();
        if (!keepAutoSchedule) { clearTimeout(autoRunTimer); autoRunTimer = null; }
        render(data);
    }catch(e){clearTimeout(refreshTimer); showPanelError(`Nie udało się odświeżyć statusu: ${e?.message || e}`); stopAutoRun('zatrzymany — błąd statusu');}
}

document.getElementById('browserAutoRunEnabled').addEventListener('change', () => {
    if (autoRunChecked()) scheduleAutoRun(latestStatus); else stopAutoRun('wyłączony przez użytkownika');
});
render(latestStatus);
refresh(true);
</script>
</body>
</html>
