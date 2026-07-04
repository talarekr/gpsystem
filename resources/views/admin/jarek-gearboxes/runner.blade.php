<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Jarek Gearboxes Runner</title>
    <style>
        body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f6f7fb;color:#111827;margin:0;padding:24px}.wrap{max-width:1300px;margin:auto}.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.card{background:white;border:1px solid #e5e7eb;border-radius:12px;padding:18px;box-shadow:0 1px 2px #0001}.controls{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}.btn{border:0;border-radius:8px;padding:9px 13px;font-weight:700;cursor:pointer}.start{background:#16a34a;color:white}.pause{background:#f59e0b}.resume{background:#2563eb;color:white}.stop{background:#dc2626;color:white}.muted{color:#6b7280}.bar{height:14px;background:#e5e7eb;border-radius:99px;overflow:hidden}.bar>span{display:block;height:100%;background:#22c55e;width:0%}.kv{display:grid;grid-template-columns:150px 1fr;gap:6px;margin:12px 0}.log{height:190px;overflow:auto;background:#111827;color:#d1fae5;border-radius:8px;padding:10px;font:12px ui-monospace,monospace}.err{color:#b91c1c}.ok{color:#15803d}input{border:1px solid #d1d5db;border-radius:8px;padding:7px;width:90px}table{width:100%;border-collapse:collapse;font-size:12px;margin-top:10px}th,td{border-bottom:1px solid #e5e7eb;padding:6px;text-align:left;vertical-align:top}.summary{white-space:pre-wrap;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:10px;max-height:220px;overflow:auto}@media(max-width:900px){.grid{grid-template-columns:1fr}}
    </style>
</head>
<body>
<div class="wrap">
    <h1>Jarek Gearboxes auto-runner</h1>
    <p class="muted">Batch requesty są krótkie. eBay Prepare działa tylko jako dry-run/read-only; real publish zostaje osobnym krokiem z confirm tokenem.</p>
    <div class="grid">
        <section class="card" data-runner="image">
            <h2>1. Image Import</h2>
            <p class="muted">Allegro CDN → <code>public_html/storage/jarek-gearboxes/{offer_source_id}/01.jpg</code></p>
            <label>Batch size <input class="limit" type="number" min="1" max="20" value="20"></label>
            <label>Delay (s) <input class="delay" type="number" min="0" value="5"></label>
            <div class="controls"><button class="btn start">START IMAGE IMPORT</button><button class="btn pause">PAUSE</button><button class="btn resume">RESUME</button><button class="btn stop">STOP</button></div>
            @include('admin.jarek-gearboxes.runner-panel')
        </section>
        <section class="card" data-runner="ebay">
            <h2>2. eBay Prepare</h2>
            <p class="muted">Tłumaczenie, opis, cena, publiczne zdjęcia, duplicate guard, blockers, ready_to_publish. Dry-run only.</p>
            <label>Batch size <input class="limit" type="number" min="1" max="10" value="5"></label>
            <label>Delay (s) <input class="delay" type="number" min="0" value="5"></label>
            <div class="controls"><button class="btn start">START EBAY PREPARE</button><button class="btn pause">PAUSE</button><button class="btn resume">RESUME</button><button class="btn stop">STOP</button></div>
            @include('admin.jarek-gearboxes.runner-panel')
        </section>
    </div>
</div>
<script>
const endpoints={image:'/admin/tools/jarek-gearboxes/image-import-runner-batch',ebay:'/admin/tools/jarek-gearboxes/ebay-prepare-runner-batch'};
function init(section){let kind=section.dataset.runner,state={status:'idle',offset:0,total:0,processed:0,summary:{},ready:[],blocked:[],last:[]};const $=s=>section.querySelector(s);function render(){let pct=state.total?Math.min(100,Math.round(state.processed/state.total*100)):0;$('.status').textContent=state.status;$('.processed').textContent=`${state.processed} / ${state.total||'?'}`;$('.offset').textContent=state.offset;$('.bar span').style.width=pct+'%';$('.error').textContent=state.error||'—';$('.summary').textContent=JSON.stringify(state.summary,null,2);$('.items tbody').innerHTML=state.last.slice(-20).map(i=>`<tr><td>${i.sku||''}</td><td>${i.offer_source_id||''}</td><td>${i.status||''}${i.ready_to_publish?' ready':''}</td><td>${(i.blockers||[]).join(', ')}</td><td>${(i.warnings||[]).join(', ')}</td></tr>`).join('')}function log(m){let l=$('.log');l.textContent+=`[${new Date().toLocaleTimeString()}] ${m}\n`;l.scrollTop=l.scrollHeight}async function tick(){if(state.status!=='running')return;let limit=parseInt($('.limit').value|| (kind==='image'?20:5),10);let params=new URLSearchParams({offset:state.offset,limit});if(kind==='image'){params.set('only_missing','1');params.set('overwrite','0');params.set('confirm','jarek-image-import-download')}else{params.set('missing_offer_id_only','1');params.set('dry_run','1')}try{let res=await fetch(endpoints[kind]+'?'+params,{headers:{Accept:'application/json'}}),data=await res.json();if(!res.ok||!data.ok)throw new Error(data.error||'batch failed');let bs=data.batch_summary||{};state.total=bs.total||state.total;state.processed+=bs.processed_count||0;state.offset=data.next_offset??state.offset;Object.keys(bs).forEach(k=>state.summary[k]=(state.summary[k]||0)+(k==='total'?0:(bs[k]||0)));state.summary.total=state.total;state.last=data.items||[];(data.items||[]).forEach(i=>{if(i.ready_to_publish)state.ready.push(i.sku); if((i.blockers||[]).length)state.blocked.push({sku:i.sku,blockers:i.blockers})});log(`offset ${data.offset}, processed ${bs.processed_count||0}, next ${data.next_offset}, has_more=${data.has_more}, failed=${bs.failed_download_count||bs.failed_count||0}, blocked=${bs.blocked_count||0}`);render();if(data.has_more){setTimeout(tick, Math.max(0,parseInt($('.delay').value||5,10))*1000)}else{state.status='completed';state.summary.ready_to_publish_sku=state.ready;state.summary.blocked_sku=state.blocked;log('completed');render()}}catch(e){state.status='error';state.error=e.message;log('ERROR '+e.message);render()}}$('.start').onclick=()=>{state={status:'running',offset:0,total:0,processed:0,summary:{},ready:[],blocked:[],last:[]};$('.log').textContent='';log('start');render();tick()};$('.pause').onclick=()=>{if(state.status==='running'){state.status='paused';log('pause after current batch');render()}};$('.resume').onclick=()=>{if(['paused','error','idle'].includes(state.status)){state.status='running';log('resume');render();tick()}};$('.stop').onclick=()=>{state.status='idle';log('stop');render()};render()}
document.querySelectorAll('[data-runner]').forEach(init);
</script>
</body>
</html>
