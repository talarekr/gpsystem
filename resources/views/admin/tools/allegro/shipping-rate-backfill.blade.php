<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}">
<title>Kurier Allegro — trwały dry-run</title>
<style>body{font:16px system-ui,sans-serif;color:#18302d;background:#f5f7f7;margin:0;padding:28px;max-width:1500px}button,a{margin:5px}button{padding:10px 16px;cursor:pointer}button:disabled{cursor:default}section{background:white;border:1px solid #cbd7d4;border-radius:8px;padding:18px;margin:18px 0;overflow:auto}table{border-collapse:collapse;width:100%;font-size:14px}th,td{text-align:left;padding:8px;border-bottom:1px solid #dce4e2}pre{white-space:pre-wrap}#status{font-weight:600}</style>
</head>
<body>
<h1>Kurier Allegro — trwały dry-run</h1>
<p>„Synchronizuj cenniki Allegro” zapisuje tylko lokalny katalog. Po synchronizacji uruchom nowy dry-run. Dotychczasowe checkpointy i wyniki pozostają zachowane.</p>
<p>Dry-run zapisuje wyłącznie techniczny raport i checkpoint, bez zmian Part, MarketplaceListing, logów synchronizacji i ofert Allegro. Osobna sekcja APPLY poniżej wymaga przygotowania planu i wpisania tokenu. Bez odświeżania OAuth.</p>
<p>Postęp jest zapisywany na serwerze po każdym produkcie. Po zamknięciu karty, reloadzie lub błędzie sieci kliknij „Wznów”. Panel nie pracuje w tle po zamknięciu karty. Wygasająca blokada partii zabezpiecza przed równoległymi kartami.</p>
<button id="sync-catalog">Synchronizuj cenniki Allegro</button><button id="diagnose">Diagnozuj połączenie Maxhendler</button><button id="catalog">Sprawdź cenniki Allegro</button>
<p><button id="start">Start nowego dry-runu</button><button id="pause" disabled>Pauza</button><button id="resume" disabled>Wznów</button><button id="stop" disabled>Stop</button><button id="retry" disabled>Ponów tylko błędy odczytu</button></p>
<p id="status" role="status">Wczytywanie checkpointu…</p>
<section><h2>Globalny dry-run — stan runu</h2><pre id="progress"></pre><p>„Resolved” oznacza rozstrzygnięty wynik odczytu, również blokadę mapowania lub konflikt. Nie oznacza zgody na aktualizację produktu. Błędy providera nie są liczone jako brak aktywnej oferty.</p>
<p><a id="json" hidden>Pobierz JSON raportu</a><a id="csv" hidden>Pobierz CSV</a><a id="proposals" hidden>CSV propozycji</a><a id="mapping" hidden>CSV blokad mapowania</a></p><p>Eksport wymaga pauzy lub zakończenia partii. Zachowuje stan runu — raport zatrzymany przed końcem jest częściowy.</p></section>
<section><h2>Summary</h2><p>Puste lokalne pole Kurier Allegro: <strong id="empty">—</strong> (nie oznacza braku cennika na Allegro).</p><pre id="summary"></pre></section>
<section><h2>Diagnostyka / cenniki</h2><pre id="diagnostics"></pre><div id="rates"></div></section>
<section><h2>Produkty według cennika</h2><div id="breakdown"></div><h3>Aktualne lokalne cenniki Allegro</h3><div id="local-counts"></div></section>
<section><h2>Ostatnie 20 wyników</h2><div id="products"></div><p>Pełny raport jest zapisany na serwerze. CSV blokad mapowania zawiera part_id, offer_id, shipping_rate_id i shipping_rate_name. Nie dodajemy nowych lokalnych opcji.</p></section>
<section id="apply-section">
<h2>Uzupełnij puste pola Kurier Allegro</h2>
<p>Run: <code>e2229ef9-4db3-4c85-ab01-e7931e705e7b</code>. Oczekiwane propozycje SET_EMPTY_FIELD: 4609.</p>
<p>Plan sprawdza zapisane wyniki ukończonego runu i lokalny katalog. Zapis dotyczy wyłącznie pustego pola Kurier Allegro. Konflikty pozostają bez zmian. Bez odpytywania i zmian Allegro.</p>
<button id="apply-plan">Przygotuj plan APPLY</button>
<pre id="apply-plan-details">Najpierw przygotuj plan. Eligible, already correct, conflicts i blocked zostaną odczytane z wyników.</pre>
<label>Token potwierdzenia: <input id="apply-confirm" autocomplete="off" spellcheck="false" placeholder="APPLY-ALLEGRO-SHIPPING-RATE-BACKFILL"></label>
<button id="apply-execute" disabled>Uzupełnij puste pola</button>
<p id="apply-status" role="status"></p><pre id="apply-report"></pre>
</section>
<script>
(() => {
    const el = id => document.getElementById(id), base = window.location.pathname.replace(/\/$/, '');
    let run = null, driving = false, stopping = false, starting = false;
    let inFlight=false,tickTimer=null,scheduleVersion=0,driverPromise=null,resolveDriver=null;
    let failures=0,recovering=false,pendingEvent=null,recoveryBaseline=null,lastScheduledAction=null;
    const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
    function table(id, columns, rows) {
        const t = document.createElement('table'), h = t.createTHead().insertRow();
        columns.forEach(key => {const th = document.createElement('th');th.textContent = key;h.append(th);});
        const body = t.createTBody();
        rows.forEach(row => {const tr=body.insertRow();columns.forEach(key => tr.insertCell().textContent=row[key] ?? '—');});
        el(id).replaceChildren(t);
    }
    async function request(url, action, extra={}) {
        const stage=['batch','resume','start','retry'].includes(action)?action:'status';
        const started=Date.now();let status=null,bytes=null;
        const controller=typeof AbortController==='undefined'?null:new AbortController();
        const timer=controller?setTimeout(()=>controller.abort(),10000):null;
        function failure(reason) {return Object.assign(new Error(reason+(status?' (HTTP '+status+')':'')),{reason,stage,http_status:status,duration_ms:Math.min(600000,Date.now()-started),response_bytes:bytes,online:typeof navigator==='undefined'?null:navigator.onLine});}
        try {
            const options={method:action?'POST':'GET',credentials:'same-origin',cache:'no-store',redirect:'error',headers:{Accept:'application/json'}};
            if(controller)options.signal=controller.signal;
            if(action){options.headers['Content-Type']='application/json';options.headers['X-CSRF-TOKEN']=document.querySelector('meta[name="csrf-token"]').content;options.body=JSON.stringify({action,...extra});}
            const response=await fetch(url,options);status=response.status ?? 200;
            const header=response.headers?.get('X-Audit-Response-Bytes') ?? response.headers?.get('Content-Length');
            bytes=header && /^\d+$/.test(header)?Math.min(50000000,Number(header)):null;
            if(!response.ok)throw failure([401,419].includes(status)?'session_expired':'http_error');
            let data;try {data=await response.json();}catch(error){if(error.name==='SyntaxError')throw failure('invalid_json');throw error;}
            for(const flag of ['allegro_writes','product_writes','marketplace_writes'])if(data[flag]!==false)throw failure('protocol_error');
            return data;
        } catch(error) {if(error.reason)throw error;throw failure(error.name==='AbortError'?'browser_timeout':'fetch_failed');}
        finally {if(timer!==null)clearTimeout(timer);}
    }
    function render(data) {
        if(run && data.run?.run_id===run.run_id && data.run.processed_count<run.processed_count)return;
        run=data.run;
        ['pause','resume','stop','retry'].forEach(id => el(id).disabled=!run);
        if(!run){el('status').textContent='Brak zapisanego runu. Rozpocznij nowy dry-run.';return;}
        window.history.replaceState(null,'','#run='+run.run_id);
        el('progress').textContent=JSON.stringify({run_id:run.run_id,state:run.state,phase:run.phase,processed:run.processed_count,total:run.total,resolved:run.resolved,provider_read_failed:run.provider_read_failed,remaining:run.remaining,retry_remaining:run.retry_remaining,current_part:run.current_part ?? (driving && run.state==='running' ? run.next_part_id : null),cursor:run.cursor,max_part_id:run.max_part_id,last_success:run.last_success,last_error:run.last_error,wait_seconds:run.wait_seconds,busy:run.busy,coverage_changed:run.coverage_changed,lease_token_present:run.lease_token_present,lease_expires_in:run.lease_expires_in,stale_lease:run.stale_lease,resume_reason:run.resume_reason,blocked_reason:run.blocked_reason,last_heartbeat:run.last_heartbeat,last_tick:run.last_tick,tick_totals:run.tick_totals,last_abandoned_tick:run.last_abandoned_tick,last_backend_error:run.last_backend_error,last_client_event:run.last_client_event,pending_read:run.pending_read,resume_allowed:run.resume_allowed,next_action:run.next_action,local_runner_active:driving,local_request_in_flight:inFlight,local_tick_scheduled:tickTimer!==null,last_scheduled_action:lastScheduledAction},null,2);
        el('summary').textContent=JSON.stringify({...run.summary,provider_read_failed_by_reason:run.provider_read_failed_by_reason,provider_offer_not_found_count:run.provider_offer_not_found_count,unresolved_active_offer_count:run.processed_count-run.summary.parts_with_one_active_offer-run.summary.parts_without_active_offer-run.summary.parts_with_multiple_active_offers,technical_report_persisted:true,business_writes:false,product_writes:false,marketplace_writes:false,allegro_writes:false},null,2);
        el('empty').textContent=run.summary.empty_kurier_allegro;
        const groups=Object.values(run.breakdown ?? {});
        for(const a of run.catalog) for(const r of a.shipping_rates) if(!groups.some(g=>g.account===a.allegro_account_code && g.shipping_rate_id===r.shipping_rate_id)) groups.push({account:a.allegro_account_code,...r,products_count:0});
        table('breakdown',['account','shipping_rate_id','shipping_rate_name','products_count'],groups);
        table('local-counts',['local_option','products_count'],run.local_options.map(name=>({local_option:name,products_count:groups.filter(g=>g.shipping_rate_name===name).reduce((n,g)=>n+g.products_count,0)})));
        table('products',['part_id','allegro_offer_id','shipping_rate_id','shipping_rate_name','current_allegro_shipping_rate_name','proposed_allegro_shipping_rate_name','reason_code','action'],run.preview);
        el('retry').disabled=driving || !run.scan_complete || run.provider_read_failed===0 || run.busy;
        el('resume').disabled=starting || inFlight || run.state==='completed';
        el('pause').disabled=!driving && run.state!=='running';
        for(const [id,format,only] of [['json','json','all'],['csv','csv','all'],['proposals','csv','proposals'],['mapping','csv','mapping']]) {
            el(id).hidden=run.state==='running' || run.busy;
            el(id).href=base+'/runs/'+run.run_id+'?'+new URLSearchParams({format,only});
        }
    }
    async function load(notify=true) {
        const id=new URLSearchParams(window.location.hash.slice(1)).get('run');
        render(await request(base+'/runs'+(id?'/'+encodeURIComponent(id):'')));
        if(run && notify) el('status').textContent='Checkpoint odtworzony. Kliknij Wznów, aby kontynuować, lub pobierz zapisany raport.';
    }
    async function control(action) {const data=await request(base+'/runs/'+run.run_id,action);render(data);return data;}
    function marker(r) {return (r?.processed_count ?? 0)+':'+(r?.tick_totals?.ticks ?? 0);}
    function nextAction() {
        if(recovering)return 'status';
        // New servers provide this explicitly. Fallback supports rolling deployment without silence.
        return run?.next_action ?? (run?.state==='running'?(run.busy?'poll_lease':run.wait_seconds>0?'poll_cooldown':run.phase==='retry'?'retry_tick':'scan_tick'):'none');
    }
    function clearTick() {scheduleVersion++;if(tickTimer!==null)clearTimeout(tickTimer);tickTimer=null;}
    function finishDriver(message) {
        driving=false;clearTick();el('start').disabled=starting;el('sync-catalog').disabled=false;
        el('status').textContent=message;if(run)render({run});
        const resolve=resolveDriver;resolveDriver=null;driverPromise=null;resolve?.();
    }
    function scheduleNextTick(milliseconds=0) {
        clearTick();
        if(!driving || stopping || inFlight)return;
        const action=nextAction();
        if(!['status','poll_lease','poll_cooldown','scan_tick','retry_tick'].includes(action)) {
            finishDriver(run?.state==='completed'?'Run zakończony. Wyniki zapisane.':run?.last_tick?.reason==='backend_exception'?'backend_error — '+run.last_backend_error.reason:'Runner wstrzymany — next_action='+action+'; checkpoint zachowany.');return;
        }
        lastScheduledAction=action;
        if(action==='poll_lease')el('status').textContent='waiting_for_live_lease — '+(run.lease_expires_in ?? 0)+' s; potem automatyczny tick';
        else if(action==='poll_cooldown')el('status').textContent='Pauza Allegro: '+run.wait_seconds+' s';
        else if(!recovering)el('status').textContent=action+'_scheduled — '+run.processed_count+' / '+run.total;
        const version=scheduleVersion;
        tickTimer=setTimeout(()=>{if(version!==scheduleVersion)return;tickTimer=null;void executeTick();},milliseconds);
        render({run});
    }
    async function executeTick() {
        if(!driving || stopping || inFlight)return;
        inFlight=true;render({run});let nextDelay=150;
        try {
            const action=nextAction();
            if(['status','poll_lease','poll_cooldown'].includes(action)) {
                render(await request(base+'/runs/'+run.run_id));
                if(recovering){
                    if(marker(run)!==recoveryBaseline)failures=0;
                    if(pendingEvent){try{await request(base+'/runs/'+run.run_id,'client-event',{event:pendingEvent});}catch(_){}pendingEvent=null;}
                    recovering=false;
                }
                nextDelay=run.busy || run.wait_seconds>0?1000:0;
            } else if(['scan_tick','retry_tick'].includes(action)) {
                el('status').textContent=action+'_started — Part '+(run.current_part ?? run.next_part_id ?? '—');
                render(await request(base+'/runs/'+run.run_id,'batch'));failures=0;
            }
        } catch(error) {
            failures++;recoveryBaseline=marker(run);
            pendingEvent={reason:error.reason ?? 'fetch_failed',stage:error.stage ?? 'batch',http_status:error.http_status ?? null,duration_ms:error.duration_ms ?? null,response_bytes:error.response_bytes ?? null,online:error.online ?? null,attempt:failures};
            if(error.reason==='session_expired'){finishDriver('session_expired — zaloguj się ponownie, potem Wznów ten sam run.');return;}
            if(error.reason==='protocol_error' || [403,404,422].includes(error.http_status) || failures>=4){finishDriver('Runner zatrzymany — kliknij Wznów. '+error.message);return;}
            recovering=true;nextDelay=1000*2**(failures-1);
            el('status').textContent='auto_recovery '+failures+'/4 — '+error.message+'; odczytam checkpoint bez resetu';
        } finally {
            inFlight=false;
            if(stopping && driving)finishDriver('Wstrzymano. Checkpoint zachowany.');
            else if(driving)scheduleNextTick(nextDelay);
        }
    }
    function activateRunner() {
        if(!driverPromise)driverPromise=new Promise(resolve=>{resolveDriver=resolve;});
        driving=true;stopping=false;failures=0;recovering=false;
        el('start').disabled=true;el('sync-catalog').disabled=true;
        // Re-arm even when a prior local active flag was already true. Never duplicate an in-flight tick.
        scheduleNextTick(0);return driverPromise ?? Promise.resolve();
    }
    async function begin(action) {
        if(starting){el('status').textContent='resume_started — weryfikacja jest już w toku';return;}
        starting=true;stopping=false;el('resume').disabled=true;el('start').disabled=true;
        el('status').textContent=action==='start'?'start_started':'resume_started — sprawdzam checkpoint i lease';
        try {
            if(action==='start'){render(await request(base+'/runs','start'));await control('resume');}
            else {
                for(let attempt=0;attempt<4;attempt++) {
                    try{await load(false);if(!run)throw new Error('run_not_found');if(!(action==='retry' && run.phase==='retry' && run.state==='running'))await control(action);break;}
                    catch(error){if(attempt===3 || error.reason==='session_expired' || [403,404,422].includes(error.http_status))throw error;el('status').textContent='resume_recovery — odczytuję ten sam run';await delay(1000*2**attempt);}
                }
            }
            if(run.resume_allowed===false){el('status').textContent='resume_blocked — '+(run.blocked_reason ?? run.state);return;}
            starting=false;await activateRunner();
        }catch(error){el('status').textContent='resume_error — '+error.message+'. Checkpoint zachowany.';}
        finally{starting=false;el('start').disabled=driving;if(run)render({run});}
    }
    el('sync-catalog').onclick=async()=>{
        el('sync-catalog').disabled=true;
        try {const data=await request(base+'/sync-catalog','sync');el('diagnostics').textContent=JSON.stringify(data,null,2);el('status').textContent=data.ok?'Katalog zsynchronizowany: '+data.rates_count+' cenników. Uruchom nowy dry-run.':'Katalog nie został zmieniony: '+data.reason_code;}
        catch(e){el('status').textContent=e.message;}finally{el('sync-catalog').disabled=driving;}
    };
    el('start').onclick=()=>begin('start');
    el('resume').onclick=()=>begin('resume');
    el('retry').onclick=()=>begin('retry');
    for(const action of ['pause','stop'])el(action).onclick=async()=>{stopping=true;clearTick();if(!inFlight)finishDriver('Wstrzymuję — checkpoint zachowany.');try{await control(action);el('status').textContent='Checkpoint zachowany: '+run.state;}catch(e){el('status').textContent=e.message+'. Po odzyskaniu sieci odczytaj checkpoint.';}};
    for(const [id,mode] of [['diagnose','diagnose'],['catalog','catalog']])el(id).onclick=async()=>{try{el(id).disabled=true;const data=await request(base+'?mode='+mode);el('diagnostics').textContent=JSON.stringify(mode==='diagnose'?data:data.catalog,null,2);}catch(e){el('diagnostics').textContent=e.message;}finally{el(id).disabled=false;}};
    load().catch(e=>el('status').textContent=e.message+'. Run nie został zmieniony. Spróbuj odświeżyć stronę.');
    (()=>{
        const runId='e2229ef9-4db3-4c85-ab01-e7931e705e7b',confirm='APPLY-ALLEGRO-SHIPPING-RATE-BACKFILL';
        let plan=null,working=false;
        function enabled(){el('apply-plan').disabled=working;el('apply-execute').disabled=working || !plan?.apply_allowed || el('apply-confirm').value!==confirm;}
        async function post(payload){
            // No scan timeout: the atomic local transaction may outlast a short provider tick.
            const response=await fetch(base+'/apply',{method:'POST',credentials:'same-origin',cache:'no-store',redirect:'error',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify({run_id:runId,...payload})});
            const data=await response.json();
            if(!response.ok || data.ok!==true){if(data.reason_code)el('apply-report').textContent=JSON.stringify(data,null,2);throw new Error(data.reason_code ?? data.message ?? 'HTTP '+response.status);}
            return data;
        }
        el('apply-confirm').oninput=enabled;
        el('apply-plan').onclick=async()=>{
            if(working)return;working=true;plan=null;enabled();
            try{plan=await post({action:'prepare'});el('apply-plan-details').textContent=JSON.stringify({run_id:plan.run_id,eligible:plan.eligible_count,expected_count:plan.expected_count,count_difference:plan.count_difference,plan_digest:plan.plan_digest,already_correct:plan.summary.already_correct,conflicts:plan.summary.current_value_conflicts,blocked:plan.blocked,excluded:plan.excluded},null,2);el('apply-status').textContent=plan.apply_allowed?'Plan gotowy. Wpisz token, aby świadomie uruchomić zapis.':'Liczba eligible różni się od 4609 — APPLY zablokowany.';if(plan.last_report)el('apply-report').textContent=JSON.stringify(plan.last_report,null,2);}
            catch(error){el('apply-status').textContent='Plan zablokowany: '+error.message;}
            finally{working=false;enabled();}
        };
        el('apply-execute').onclick=async()=>{
            if(working || !plan?.apply_allowed || el('apply-confirm').value!==confirm)return;
            working=true;enabled();el('apply-status').textContent='APPLY w toku — nie zamykaj karty.';
            try{const data=await post({action:'apply',plan_id:plan.plan_id,plan_digest:plan.plan_digest,confirm:el('apply-confirm').value});el('apply-report').textContent=JSON.stringify(data,null,2);el('apply-status').textContent='APPLY zakończony. Sprawdź raport.';}
            catch(error){el('apply-status').textContent='Brak potwierdzonego zakończenia: '+error.message+'. Po odzyskaniu połączenia przygotuj ponownie ten sam plan — zapisany ledger zabezpiecza przed powtórnym zapisem.';}
            finally{working=false;el('apply-confirm').value='';enabled();}
        };
    })();
})();
</script>
</body>
</html>
