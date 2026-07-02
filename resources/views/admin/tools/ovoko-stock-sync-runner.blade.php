<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Ovoko stock sync runner</title>
    <style>
        body{font-family:Inter,ui-sans-serif,system-ui,Arial,sans-serif;background:#f8fafc;color:#0f172a;margin:0;padding:24px}.wrap{max-width:1180px;margin:auto}.card{background:white;border:1px solid #e2e8f0;border-radius:14px;padding:18px;margin:0 0 16px;box-shadow:0 1px 2px #0000000d}.warning{border-color:#f59e0b;background:#fffbeb}.danger{border-color:#dc2626;background:#fef2f2}.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.btn{border:0;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer}.btn:disabled{opacity:.5;cursor:not-allowed}.primary{background:#2563eb;color:white}.secondary{background:#e2e8f0;color:#0f172a}.dangerBtn{background:#dc2626;color:white}.applyBtn{background:#7f1d1d;color:white}.muted{color:#64748b}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}.metric{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:12px}.metric b{display:block;font-size:22px}.bar{height:22px;background:#e2e8f0;border-radius:999px;overflow:hidden}.bar span{display:block;height:100%;width:0;background:#22c55e;transition:width .25s}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}pre{white-space:pre-wrap;word-break:break-word;background:#0f172a;color:#e2e8f0;border-radius:12px;padding:12px;max-height:360px;overflow:auto}table{width:100%;border-collapse:collapse}td,th{border-bottom:1px solid #e2e8f0;text-align:left;padding:8px;font-size:13px}.badge{display:inline-block;border-radius:999px;padding:3px 9px;background:#e2e8f0;font-weight:700}.running{background:#dbeafe}.completed{background:#dcfce7}.failed,.cancelled{background:#fee2e2}
    </style>
</head>
<body>
<div class="wrap" data-active-run="{{ $activeRun?->id }}" data-latest-run="{{ $latestRun?->id }}">
    <h1>Ovoko stock sync runner</h1>
    <div class="card warning"><b>Warning:</b> Lokalny stock only: brak marketplace write, brak price sync, brak publish/relist/end. Tryb apply zapisuje wyłącznie lokalne pola: quantity, status, is_visible_storefront.</div>

    <div class="card">
        <p>Browser runner działa bez queue workera: kliknięcie Start/Resume uruchamia HTTP tick co 900 ms. Każdy tick przetwarza maksymalnie <b>{{ $batchSize }}</b> produktów synchronicznie.</p>
        <div class="row">
            <button id="dryStart" class="btn primary">Start dry-run</button>
            <button id="applyStart" class="btn applyBtn">Start apply — zapisuje lokalny stock</button>
            <button id="resume" class="btn secondary" @disabled(! $activeRun)>Resume aktywny run{{ $activeRun ? ' #'.$activeRun->id : '' }}</button>
            <button id="cancel" class="btn dangerBtn" @disabled(! $activeRun)>Cancel</button>
        </div>
        @if($activeRun)
            <p class="muted">Wykryto aktywny run <b>#{{ $activeRun->id }}</b> ze statusem <b>{{ $activeRun->status }}</b>. Możesz go wznowić przez browser tick.</p>
        @endif
    </div>

    <div class="card">
        <h2>Status <span id="statusBadge" class="badge">idle</span></h2>
        <div class="bar"><span id="progressBar"></span></div>
        <p><span id="processed">0</span> / <span id="total">0</span> (<span id="percent">0</span>%) · batch_size=<span id="batch">{{ $batchSize }}</span> · last_processed_part_id=<span id="lastPart">—</span></p>
        <div class="grid" id="metrics"></div>
    </div>

    <div class="card"><h2>Top blockers</h2><pre id="blockers">{}</pre></div>
    <div class="card"><h2>Recent results (ostatnie 20)</h2><div id="recent"></div></div>
    <div class="card"><h2>Log</h2><pre id="log"></pre></div>
</div>
<script>
const activeRun = @json($activeRun?->summary());
let currentRunId = activeRun ? activeRun.run_id : null;
let running = false;
let timer = null;
const urls = {
    startDry: @json(route('admin.tools.ovoko-stock-sync-runner.start-browser', ['mode' => 'dry-run', 'confirm' => 'ovoko-stock-sync-runner'], false)),
    startApply: @json(route('admin.tools.ovoko-stock-sync-runner.start-browser', ['mode' => 'apply', 'confirm' => 'ovoko-stock-sync-runner-apply'], false)),
    status: id => `/admin/tools/ovoko-stock-sync-runner/status/${id}`,
    tick: id => `/admin/tools/ovoko-stock-sync-runner/tick/${id}?confirm=ovoko-stock-sync-runner-tick`,
    cancel: id => `/admin/tools/ovoko-stock-sync-runner/cancel/${id}?confirm=cancel-ovoko-stock-sync-runner`,
};
function log(msg){document.getElementById('log').textContent = `[${new Date().toLocaleTimeString()}] ${msg}\n` + document.getElementById('log').textContent;}
async function getJson(url){const res=await fetch(url,{headers:{Accept:'application/json'}}); const data=await res.json(); if(!res.ok){throw data;} return data;}
function render(data){
    if(!data) return; if(data.run) data=data.run; currentRunId=data.run_id || currentRunId;
    const status=data.status || 'unknown'; const pct=Number(data.progress_percent || 0);
    const badge=document.getElementById('statusBadge'); badge.textContent=`#${currentRunId || '—'} ${status} (${data.mode || '—'})`; badge.className=`badge ${status}`;
    document.getElementById('progressBar').style.width=Math.min(100,pct)+'%';
    document.getElementById('processed').textContent=data.processed_count ?? 0; document.getElementById('total').textContent=data.total_candidates ?? 0; document.getElementById('percent').textContent=pct; document.getElementById('batch').textContent=data.batch_size ?? {{ $batchSize }}; document.getElementById('lastPart').textContent=data.last_processed_part_id ?? '—';
    const keys=['no_change_count','would_update_count','applied_count','blocked_count','skipped_count','failed_count','remaining_count'];
    document.getElementById('metrics').innerHTML=keys.map(k=>`<div class="metric"><span class="muted">${k}</span><b>${data[k] ?? 0}</b></div>`).join('');
    document.getElementById('blockers').textContent=JSON.stringify(data.top_blockers || {}, null, 2);
    const rows=(data.recent_results || []).slice().reverse();
    document.getElementById('recent').innerHTML=rows.length ? `<table><thead><tr><th>part_id</th><th>ovoko_id</th><th>action</th><th>blockers</th></tr></thead><tbody>${rows.map(r=>`<tr><td>${r.part_id ?? ''}</td><td>${r.ovoko_id ?? ''}</td><td>${r.action ?? ''}</td><td class="mono">${(r.blockers || []).join(', ')}</td></tr>`).join('')}</tbody></table>` : '<p class="muted">Brak wyników.</p>';
    document.getElementById('applyStart').disabled=['queued','running'].includes(status);
    document.getElementById('dryStart').disabled=['queued','running'].includes(status);
    document.getElementById('resume').disabled=!['queued','running'].includes(status);
    document.getElementById('cancel').disabled=!['queued','running'].includes(status);
}
async function start(url){try{const data=await getJson(url); render(data); log(`Started run #${data.run_id} (${data.mode})`); autoTick();}catch(e){render(e); log('Start blocked: '+JSON.stringify(e));}}
async function autoTick(){ if(!currentRunId || running) return; running=true; clearTimeout(timer); try{const data=await getJson(urls.tick(currentRunId)); render(data); log(`Tick: status=${data.status}, processed=${data.processed_count}/${data.total_candidates}, locked=${data.locked}`); if(['queued','running'].includes(data.status)){timer=setTimeout(()=>{running=false; autoTick();},900); return;}}catch(e){log('Tick error: '+JSON.stringify(e));} running=false; }
document.getElementById('dryStart').onclick=()=>start(urls.startDry);
document.getElementById('applyStart').onclick=()=>{if(confirm('Tryb APPLY zapisze lokalny stock (quantity/status/is_visible_storefront). Brak marketplace write. Kontynuować?')) start(urls.startApply);};
document.getElementById('resume').onclick=()=>autoTick();
document.getElementById('cancel').onclick=async()=>{if(currentRunId && confirm('Anulować run?')){render(await getJson(urls.cancel(currentRunId))); log('Cancel requested.');}};
if(activeRun){render(activeRun); log(`Loaded active run #${activeRun.run_id}. Kliknij Resume, aby kontynuować bez queue workera.`);}
</script>
</body>
</html>
