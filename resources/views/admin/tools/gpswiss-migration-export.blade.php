<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="csrf-token" content="{{ csrf_token() }}"><title>Eksport GPSwiss → Wystawczesc</title>
<style>body{max-width:1100px;margin:30px auto;padding:20px;font:16px system-ui}button,a{margin:8px;padding:10px}pre{white-space:pre-wrap;background:#f1f5f4;padding:18px}</style></head><body>
<h1>Prywatny eksport części, samochodów i zamówień GPSwiss → Wystawczesc</h1>
<p>Eksport tworzy prywatne pliki. Nie zmienia części, zdjęć, cen, stanów, ofert ani synchronizacji. Nie wykonuje importu ani API Allegro/eBay/Ovoko.</p>
<p>Panel wykonuje krótkie partie i zapisuje postęp na serwerze. Zamrożone granice odczytu oraz sumy kontrolne tworzą wersjonowany snapshot logiczny; nie jest to jedna transakcja bazy utrzymywana między żądaniami HTTP. Granice i ograniczenia spójności znajdziesz w manifeście.</p>
<p>Próbka obejmuje do 5 rzeczywistych części, a pełny eksport używa tego samego formatu. Zdjęcia zachowują oryginalne nazwy w katalogach ID części; kolizje w obrębie części otrzymują podkatalog ID zdjęcia. Brakujące/zmienione pliki są raportowane.</p>
<p>Paczka zawiera kody, kategorię, jakość, pozycję, magazyn, opis, lokalne/Ovoko ID auta, ceny sklep/Allegro/Ovoko/eBay, Kurier Allegro, lokalne parametry i linki ofert. Zawiera wszystkie samochody, zamówienia i potrzebne dane klientów — pobieranie wyłącznie prywatnie przez zalogowanego właściciela eksportu. Bez haseł, tokenów i pełnych danych kart. Nie udostępniaj archiwum ani katalogu publicznie.</p>
<p>Snapshot bazy nie jest globalnym snapshotem filesystemu. Kopie plików są weryfikowane podczas kopiowania; finalny cutover wymaga osobnej procedury freeze/snapshotu. Nie zatrzymujemy synchronizacji automatycznie.</p>
<p>Próbka wybiera sprzedaną część (status sold), część do wystawienia (needs_listing), oferty eBay/Ovoko i galerię — gdy takie przypadki są zapisane. Powiązania Allegro są wyłącznie historyczne. Import, publikacja, synchronizacja i wiadomości do klientów pozostają wyłączone.</p>
<button id="start" disabled>Przygotuj nowy eksport</button><button id="resume" disabled>Wznów istniejący eksport</button><button id="pause" disabled>Pauza snapshotu</button><button id="stop-local" disabled>Stop lokalnej pętli</button>
<section id="snapshot-actions" hidden>
<p>Niedokończony snapshot można ponowić w trybie panelowym w tym samym eksporcie. Nowa próba ustali własne granice odczytu i nie wykorzysta częściowych danych poprzedniej próby. Wznowienie gotowej próby zachowuje jej granice i cursor.</p>
<label><input id="snapshot-confirm-retry" type="checkbox"> Potwierdzam rozpoczęcie snapshotu panelowego od początku w tym samym run_id.</label><button id="retry-snapshot" disabled>Ponów snapshot w trybie panelowym</button>
<p><label><input id="snapshot-confirm-abort" type="checkbox"> Potwierdzam odrzucenie tej niedokończonej próby bez usuwania jej plików.</label><button id="abort-snapshot" disabled>Odrzuć niedokończony snapshot</button></p>
</section>
<section id="photo-retry-actions" hidden>
<p>Ponowienie obejmuje błędy COPY_FAILED, UNREADABLE_ORIGINAL i INSUFFICIENT_DISK_SPACE. Zachowuje poprawne kopie i zamrożone dane produktów. Brakujące lub zmienione od czasu snapshotu oryginały pozostają w raporcie.</p>
<label><input id="photo-confirm-retry" type="checkbox"> Potwierdzam ponowienie nieudanych kopii i odtworzenie archiwów w tym samym run_id.</label><button id="retry-photos" disabled>Ponów nieudane kopie zdjęć</button>
</section>
<p id="snapshot-worker" hidden></p><p id="progress" role="status"></p>
<p id="message" role="status">Szukam niedokończonego eksportu właściciela…</p><a id="login" href="/admin/login" hidden>Zaloguj się ponownie, potem odśwież ten panel</a><pre id="resume-diagnostic" aria-label="Diagnostyka wznowienia"></pre><pre id="state"></pre><pre id="request-diagnostic" aria-label="Diagnostyka żądania"></pre>
<a id="sample" hidden>Pobierz próbkę ZIP</a><a id="full" hidden>Pobierz pełny ZIP</a>
<ul id="download-artifacts" hidden></ul><a id="full-debug" hidden target="_blank" rel="noreferrer">Pełny status diagnostyczny</a>
<p>Cały eksport działa z tego panelu. Pauza snapshotu zapisuje stan na serwerze; Stop lokalnej pętli kończy wysyłanie kolejnych żądań. Po odświeżeniu użyj Wznów. Pobierz manifest i wszystkie wskazane części archiwum.</p>
<script>
(()=>{
 const el=id=>document.getElementById(id),base=@json(route('admin.tools.gpswiss-migration-export', [], false));
 let id=new URLSearchParams(location.hash.slice(1)).get('run'),state=null,active=false,working=false,ready=false,sessionExpired=false,accessBlocked=false,inFlight=false,pauseRequested=false,pausing=false;
 let loopDone=null,finishLoop=null,manualResuming=false,resumeTraceActive=false,retryExhausted=false;
 const waits=new Map(),resumeEvents=[];
 const wait=ms=>new Promise(resolve=>{const timer=setTimeout(()=>{waits.delete(timer);resolve();},ms);waits.set(timer,resolve);});
 function cancelBackoff(){for(const [timer,resolve] of waits){clearTimeout(timer);resolve();}waits.clear();}
 function resumeEvent(event,blocked_reason=null){resumeEvents.push({event,blocked_reason});if(resumeEvents.length>12)resumeEvents.shift();el('resume-diagnostic').textContent=JSON.stringify({events:resumeEvents,retry_exhausted:retryExhausted},null,2);}
 async function manualResume(){
  if(manualResuming)return;
  manualResuming=true;resumeTraceActive=true;resumeEvents.length=0;resumeEvent('resume_started');
  try{
   await boot;
   if(sessionExpired || accessBlocked){resumeEvent('blocked',sessionExpired?'SESSION_EXPIRED':'run_owner_mismatch');return;}
   if(inFlight || pausing || (working && !loopDone)){resumeEvent('blocked','active_request_or_operation');return;}
   if(working){active=false;pauseRequested=false;cancelBackoff();await loopDone;}
   active=false;pauseRequested=false;retryExhausted=false;requestFailures.length=0;cancelBackoff();
   el('request-diagnostic').textContent='';el('message').textContent='resume_started / local_retry_state_reset';
   resumeEvent('local_retry_state_reset');
   drive(false).catch(()=>resumeEvent('blocked','local_runner_error'));
  }finally{manualResuming=false;buttons();}
 }
 const reasonOf=(data,fallback)=>typeof data?.reason_code==='string' && /^[A-Za-z0-9_]{1,80}$/.test(data.reason_code)?data.reason_code:fallback;
 const snapshotState=()=>state?.snapshot_state ?? (String(state?.state ?? '').startsWith('snapshot')?state.state:null);
 const browserSnapshot=()=>state?.snapshot_mode==='browser';
 const photoRetryPending=()=>state?.photo_retry_requested===true || ['photos_retry_setup','photos_retry_inventory','photos_retry'].includes(state?.phase);
 const unfinished=()=>state && state.state!=='completed' && snapshotState()!=='snapshot_aborted';
 const canPause=()=>browserSnapshot() && (['snapshot_pending','snapshot_running'].includes(snapshotState()) || (snapshotState()==='snapshot_completed' && state?.phase==='photo_inventory'));
 function nextAction(){
  if(state?.next_action)return state.next_action;
  if(state?.state==='completed')return 'download';
  if(state?.busy)return 'wait';
  if(state?.resume_allowed===false)return 'blocked';
  return ['photos','archive'].includes(state?.state)?'tick':'blocked';
 }
 function buttons(){
  const blocked=!ready || sessionExpired || accessBlocked;
  el('start').disabled=blocked || working || !!unfinished();
  el('resume').disabled=blocked || (inFlight || pausing || manualResuming) || !state || state.state==='completed' || (nextAction()==='blocked' && !state.busy);
  el('pause').disabled=blocked || !canPause() || pausing || pauseRequested;
  el('stop-local').disabled=!working;
  el('snapshot-actions').hidden=!(state?.snapshot_retry_allowed || state?.snapshot_abort_allowed);
  el('retry-snapshot').disabled=blocked || working || !state?.snapshot_retry_allowed || !el('snapshot-confirm-retry').checked;
  el('abort-snapshot').disabled=blocked || working || !state?.snapshot_abort_allowed || !el('snapshot-confirm-abort').checked;
  el('photo-retry-actions').hidden=!state?.photo_retry_allowed;
  el('retry-photos').disabled=blocked || working || !state?.photo_retry_allowed || !el('photo-confirm-retry').checked;
 }
 function render(data){
  const value=data?.run ?? (data?.run_id && data?.state?data:null);
  // Requests are sequential. Counters may restart for the next dataset/phase or a conscious retry.
  if(value){state=value;id=value.run_id;history.replaceState(null,'','#run='+id);}
  else if(data?.existing_run_found===false)state=null;
  el('state').textContent=JSON.stringify(state?{...state,local_runner_active:active,local_request_in_flight:inFlight}:data,null,2);
  const progress=state?.snapshot_progress ?? {},dataset=state?.current_dataset ?? progress.current_dataset ?? progress.dataset;
  const total=state && Object.hasOwn(state,'total')?(state.total ?? '—'):(progress.total ?? state?.total_images ?? '—');
  el('progress').textContent=state?'Run: '+id+' | Stan: '+state.state+' | Faza: '+(state.phase ?? state.state)+' | Zbiór: '+(dataset ?? '—')+' | Postęp: '+(state.processed ?? progress.processed ?? state.processed_images ?? 0)+' / '+total+' | Cursor: '+cursorText(state.dataset_cursor ?? state.cursor ?? progress.cursor)+' | Następny krok: '+nextAction():'';
  const debug=el('full-debug');debug.hidden=!id;if(id)debug.href=base+'/'+encodeURIComponent(id)+'?format=status';
  if(state?.state==='completed' && browserSnapshot() && !Array.isArray(state.download_artifacts) && artifactsLoadedFor!==id){
   artifactsLoadedFor=id;const artifactRun=id;
   queueMicrotask(async()=>{try{const data=await request(base+'/'+encodeURIComponent(artifactRun)+'?format=artifacts');if(id===artifactRun && state?.state==='completed'){state.download_artifacts=data.download_artifacts??[];render(state);}}catch(error){artifactsLoadedFor=null;el('message').textContent='artifact_list_fetch_failed — odśwież panel, aby ponowić pobranie listy plików.';}});
  }
  const artifacts=Array.isArray(state?.download_artifacts)?state.download_artifacts:[];
  for(const kind of ['sample','full']){el(kind).hidden=state?.state!=='completed' || artifacts.length>0 || browserSnapshot() || state?.schema_version==='gpswiss-wystawczesc-3';if(id)el(kind).href=base+'/'+encodeURIComponent(id)+'?download='+kind;}
  const downloads=el('download-artifacts');downloads.replaceChildren();downloads.hidden=state?.state!=='completed' || artifacts.length===0;
  if(!downloads.hidden)for(const artifact of artifacts){
   if(typeof artifact.key!=='string' || !/^[A-Za-z0-9][A-Za-z0-9_.-]{0,179}$/.test(artifact.key))continue;
   const item=document.createElement('li'),link=document.createElement('a');link.href=base+'/'+encodeURIComponent(id)+'?download=artifact&artifact='+encodeURIComponent(artifact.key);link.textContent=artifact.name ?? artifact.key;item.append(link);
   const info=document.createElement('span');info.textContent=' '+(artifact.bytes ?? '—')+' B'+(artifact.sha256?' | SHA-256: '+artifact.sha256:'');item.append(info);downloads.append(item);
  }
  const phase=snapshotState();el('snapshot-worker').hidden=!phase || phase==='snapshot_completed';
  el('snapshot-worker').textContent=browserSnapshot()?'Tryb panelowy: każda partia zapisuje checkpoint. Wznów kontynuuje tę samą próbę; Ponów snapshot świadomie ustala nowe granice.':phase?'To próba utworzona w starszym trybie. Jeśli nie działa, użyj „Ponów snapshot w trybie panelowym”, aby zachować run_id.':'';
  buttons();
 }
 let artifactsLoadedFor=null;
 const requestFailures=[];
 const cursorText=value=>value==null?'—':typeof value==='object'?JSON.stringify(value):String(value);
 const transient=error=>error.reason!=='status_fetch_retry_exhausted' && (['network_error','browser_fetch_timeout','malformed_json','invalid_response','snapshot_http_tick_failed'].includes(error.reason) || [408,429,500,502,503,504].includes(error.http));
 async function request(url,action,extra={}){
  inFlight=true;buttons();const isStatus=!action && url.includes('format=compact'),controller=new AbortController(),started=performance.now(),timer=setTimeout(()=>controller.abort(),isStatus?10000:45000);
  const diagnostic={endpoint:new URL(url,location.href).pathname+new URL(url,location.href).search,route_name:'admin.tools.gpswiss-migration-export',action:action??(isStatus?'compact_status':'status'),request_kind:isStatus?'status':'operation',status_response_bytes:isStatus?0:null,malformed_json:false,content_type:null,content_length:null,http_status:null,fetch_timeout:false,network_exception_type:null,transport_reason:null,response_bytes:0,server_reason_code:null,current_dataset:state?.current_dataset??null,cursor_before:state?.cursor??null,cursor_after:null};
  try{
   const response=await fetch(url,{method:action?'POST':'GET',credentials:'same-origin',cache:'no-store',redirect:'error',signal:controller.signal,headers:{Accept:'application/json',...(action?{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content}:{})},...(action?{body:JSON.stringify({action,...extra})}:{})});
   diagnostic.http_status=response.status;diagnostic.content_type=response.headers.get('content-type');diagnostic.content_length=response.headers.get('content-length');
   if([401,419].includes(response.status))throw Object.assign(new Error('SESSION_EXPIRED'),{reason:'SESSION_EXPIRED',http:response.status});
   const body=await response.text();diagnostic.response_bytes=new TextEncoder().encode(body).length;
   let data;try{data=JSON.parse(body);}catch(_){data=null;diagnostic.malformed_json=true;}
   diagnostic.server_reason_code=reasonOf(data,reasonOf(data?.status_request_diagnostic,null));diagnostic.status_response_bytes=isStatus?diagnostic.response_bytes:null;diagnostic.server_status_response_bytes=data?.status_response_bytes??null;diagnostic.backend=isStatus?(data?.status_request_diagnostic??null):(data?.tick_diagnostic??data?.last_tick??null);diagnostic.last_status_error=isStatus?(data?.last_status_error??null):null;diagnostic.backend_exception_class=data?.backend_exception_class??diagnostic.backend?.backend_exception_class??null;
   if([401,419].includes(response.status))throw Object.assign(new Error('SESSION_EXPIRED'),{reason:'SESSION_EXPIRED',http:response.status});
   if(!response.ok)throw Object.assign(new Error('http_error'),{reason:reasonOf(data,'http_'+response.status),http:response.status});
   if(!data || typeof data!=='object')throw Object.assign(new Error('malformed_json'),{reason:'malformed_json',http:response.status});
   diagnostic.cursor_after=data.cursor??data.run?.cursor??null;diagnostic.backend=isStatus?(data.status_request_diagnostic??null):(data.tick_diagnostic??data.last_tick??null);
   return data;
  }catch(error){
   if(error.reason){diagnostic.reason_code=error.reason;throw error;}
   diagnostic.fetch_timeout=controller.signal.aborted;diagnostic.network_exception_type=['AbortError','TimeoutError','TypeError'].includes(error.name)?error.name:'Error';
   const reason=controller.signal.aborted?'browser_fetch_timeout':'network_error';diagnostic.reason_code=reason;diagnostic.transport_reason=controller.signal.aborted?'browser_fetch_timeout':'unknown_network_error';
   throw Object.assign(new Error(reason),{reason,http:diagnostic.http_status});
  }finally{
   diagnostic.duration_ms=Math.round(performance.now()-started);clearTimeout(timer);inFlight=false;buttons();
   if(diagnostic.reason_code){requestFailures.push(diagnostic);if(requestFailures.length>4)requestFailures.shift();}
   el('request-diagnostic').textContent=JSON.stringify({latest:diagnostic,recent_failures:requestFailures},null,2);
  }
 }

 function stop(error){
  if(error.reason==='local_stop'){active=false;return;}
  if(resumeTraceActive)resumeEvent('blocked',error.reason ?? 'unknown_local_error');
  active=false;retryExhausted=transient(error);
  if(error.reason==='SESSION_EXPIRED'){
   sessionExpired=true;el('login').hidden=false;
   el('message').textContent='SESSION_EXPIRED / HTTP '+(error.http ?? 419)+' — checkpoint pozostaje na serwerze. Zaloguj się ponownie i odśwież panel; potem Wznów istniejący eksport.';
  }else if(error.reason==='run_owner_mismatch'){
   accessBlocked=true;el('message').textContent='BLOCK / run_owner_mismatch — ten eksport nie należy do bieżącego właściciela. Nie tworzę nowego runu.';
  }else if(error.reason==='run_not_found' && !state){
   el('message').textContent='run_not_found — brak zapisanego eksportu dla tego UUID i brak niedokończonego eksportu właściciela. UUID w adresie sam nie potwierdza utworzenia checkpointu; POST odrzucony przez 419 mógł nie utworzyć runu.';
  }else el('message').textContent='Eksport zatrzymany: '+(transient(error)?'retry_exhausted / ':'')+error.reason+(error.http?' / HTTP '+error.http:'')+'. Checkpoint i pliki pozostają zachowane. Wznów po usunięciu blokady.';
  buttons();
 }
 async function discover(){const data=await request(base+'?format=compact');render(data);return !!state;}
 async function readCurrent(){
  let last;
  for(let attempt=1;attempt<=4;attempt++){
   try{const data=await request(base+'/'+encodeURIComponent(id)+'?format=compact');render(data);return state;}
   catch(error){
    if(!transient(error))throw error;last=error;
    if(attempt===4)break;
    el('message').textContent='status_fetch_retry '+attempt+'/4 — ten sam run i attempt';
    await wait(250*2**(attempt-1));
    if(!active && working && loopDone)throw Object.assign(new Error('local_stop'),{reason:'local_stop'});
   }
  }
  throw Object.assign(new Error('status_fetch_retry_exhausted'),{reason:'status_fetch_retry_exhausted',http:last?.http});
 }
 async function recoverOnce(error,attempt){
  if(error.reason==='SESSION_EXPIRED' || error.reason==='run_owner_mismatch' || [403,422].includes(error.http))throw error;
  if(error.http===404 || error.reason==='existing_export_unfinished'){
   el('message').textContent=error.reason+' — szukam istniejącego eksportu właściciela';
   if(await discover())return;
   throw Object.assign(new Error('run_not_found'),{reason:'run_not_found',http:404});
  }
  if(!transient(error))throw error;
  el('message').textContent='network_recovery '+attempt+'/4 — odczytuję checkpoint tego samego eksportu';
  await wait(500*2**(attempt-1));
  if(!active && working && loopDone)throw Object.assign(new Error('local_stop'),{reason:'local_stop'});
  try{if(id)await readCurrent();else await discover();}
  catch(next){if(next.http===404 && next.reason!=='run_owner_mismatch'){if(await discover())return;}throw next;}
 }
 async function recover(error,attempt){
  let last=error;
  for(let n=attempt;n<=4;n++){
   try{await recoverOnce(last,n);return;}
   catch(next){if(transient(next)){last=next;if(n<4)continue;}throw next;}
  }
  throw last;
 }
 function restoredMessage(){
  if(state?.state==='completed')return 'Eksport ukończony — pobierz manifest i wszystkie części paczki.';
  if(snapshotState()==='snapshot_aborted')return 'snapshot_aborted — próba odrzucona; możesz świadomie przygotować nowy eksport.';
  if(snapshotState()==='snapshot_paused')return 'snapshot_paused — checkpoint zachowany. Kliknij Wznów, aby kontynuować tę samą próbę.';
  if(state?.snapshot_retry_allowed && !browserSnapshot())return 'Ta próba nie została ukończona. Użyj „Ponów snapshot w trybie panelowym” w tym samym run_id.';
  if(state)return 'Masz niedokończony eksport: '+id+'. Kliknij Wznów, aby kontynuować od checkpointu.';
  return 'Brak niedokończonego eksportu właściciela.';
 }
 async function restore(){
  for(let attempt=1;attempt<=4;attempt++){
   try{
    if(id){try{await readCurrent();}catch(error){if(error.http!==404 || error.reason==='run_owner_mismatch')throw error;if(!await discover())throw Object.assign(new Error('run_not_found'),{reason:'run_not_found',http:404});}}
    else await discover();
    el('message').textContent=restoredMessage();break;
   }catch(error){if(transient(error) && attempt<4){await wait(500*2**(attempt-1));continue;}stop(error);break;}
  }
  ready=true;buttons();
 }
 const boot=restore();
 async function pauseCheckpoint(){
  if(!canPause() || pausing)return;pausing=true;buttons();
  try{render(await request(base+'/'+encodeURIComponent(id),'pause_snapshot'));el('message').textContent='snapshot_paused — postęp zapisany na serwerze. Wznów kontynuuje tę samą próbę.';}
  catch(error){try{await recover(error,1);el('message').textContent=restoredMessage();}catch(next){stop(next);}}
  finally{pausing=false;buttons();}
 }
 async function drive(start){
  await boot;if(working || sessionExpired || accessBlocked)return;
  working=true;active=true;loopDone=new Promise(resolve=>{finishLoop=resolve;});buttons();let attempts=0,needsResume=!start;
  const expectedRun=id,expectedAttempt=state?.snapshot_attempt_id;
  try{
   if(start){
    await discover();if(unfinished()){el('message').textContent='Masz niedokończony eksport '+id+' — użyj dostępnych akcji tego runu.';return;}
    id=crypto.randomUUID();state=null;history.replaceState(null,'','#run='+id);
    try{render(await request(base+'/'+id,'start'));}catch(error){await recover(error,++attempts);}
   }else{
    try{if(id)await readCurrent();else if(!await discover())throw Object.assign(new Error('run_not_found'),{reason:'run_not_found',http:404});}
    catch(error){await recover(error,++attempts);}
   }
   if(!start){
    if(expectedRun && (id!==expectedRun || (expectedAttempt && state?.snapshot_attempt_id!==expectedAttempt))){resumeEvent('blocked','run_or_attempt_changed');el('message').textContent='Wznowienie zablokowane: run_or_attempt_changed';return;}
    if(resumeTraceActive){resumeEvent('status_loaded');resumeEvent('next_action='+nextAction());}
    if(browserSnapshot() && state?.state==='snapshot_running' && nextAction()==='snapshot_tick' && !state.busy)needsResume=false;
   }
   while(active && state){
    const next=nextAction();
    if(next==='download' || state.state==='completed'){el('message').textContent='Eksport gotowy. Pobierz manifest i wszystkie wskazane części archiwum.';break;}
    if(state.busy || next==='wait'){
     await wait(1000);if(!active)break;try{await readCurrent();}catch(error){if(++attempts>4)throw error;await recover(error,1);}continue;
    }
    if(next==='blocked'){if(resumeTraceActive)resumeEvent('blocked',state.blocked_reason ?? state.last_error ?? state.state);el('message').textContent='Wznowienie zablokowane: '+(state.blocked_reason ?? state.last_error ?? state.state)+'. Sprawdź dostępne akcje snapshotu.';break;}
    if(browserSnapshot() && state.state==='snapshot_running' && next==='snapshot_tick' && !state.busy)needsResume=false;
    if(needsResume || next==='resume'){
     try{render(await request(base+'/'+encodeURIComponent(id),'resume'));needsResume=!!state.busy;attempts=0;}
     catch(error){if(++attempts>4)throw error;await recover(error,1);}
     continue;
    }
    if(!['snapshot_tick','tick'].includes(next)){el('message').textContent='Nieznany następny krok: '+next+'. Checkpoint zachowany.';break;}
    const run=id,attempt=state.snapshot_attempt_id ?? null;
    if(resumeTraceActive && !resumeEvents.some(e=>e.event==='tick_scheduled'))resumeEvent('tick_scheduled');
    try{
     render(await request(base+'/'+encodeURIComponent(id),next,next==='snapshot_tick'?{expected_snapshot_attempt:attempt}:{}));attempts=0;
     if(state.last_error)throw Object.assign(new Error(state.last_error),{reason:state.last_error});
     if(resumeTraceActive && !resumeEvents.some(e=>e.event==='tick_completed'))resumeEvent('tick_completed');
    }catch(error){
     if(++attempts>4)throw error;await recover(error,1);
     if(id!==run || (next==='snapshot_tick' && state?.snapshot_attempt_id!==attempt)){el('message').textContent='Próba snapshotu zmieniła się. Sprawdź status i świadomie użyj Wznów.';break;}
    }
    await wait(150);
   }
   if(!active && !sessionExpired && !pauseRequested)el('message').textContent='Zatrzymano lokalną pętlę. Checkpoint i pliki zachowane; Wznów istniejący eksport.';
  }catch(error){stop(error);}
  finally{
   if(pauseRequested && !sessionExpired && !accessBlocked){pauseRequested=false;await pauseCheckpoint();}
   working=false;active=false;const finished=finishLoop;finishLoop=null;loopDone=null;if(finished)finished();buttons();if(state)render(state);
  }
 }
 async function snapshotAction(action){
  await boot;if(working || sessionExpired || accessBlocked || !state)return;
  const retry=action==='retry_snapshot',checkbox=el(retry?'snapshot-confirm-retry':'snapshot-confirm-abort');
  if(!checkbox.checked || !(retry?state.snapshot_retry_allowed:state.snapshot_abort_allowed))return;
  const expected=state.snapshot_attempt_id ?? null,expectedRun=id;working=true;active=false;buttons();let follow=false;
  try{
   render(await request(base+'/'+encodeURIComponent(id),action,{expected_snapshot_attempt:expected,...(retry?{confirm_restart:true}:{confirm_abort:true})}));
   checkbox.checked=false;el('message').textContent=retry?'Nowa próba panelowa w tym samym run_id; wykonuję krótkie partie.':'snapshot_aborted — pliki pozostają zachowane. Możesz teraz świadomie przygotować nowy eksport.';
   follow=retry && browserSnapshot() && nextAction()!=='blocked';
  }catch(error){
   try{await recover(error,1);if(state?.snapshot_attempt_id!==expected)checkbox.checked=false;follow=retry && state?.run_id===expectedRun && state?.snapshot_attempt_id!==expected && browserSnapshot() && nextAction()!=='blocked';}
   catch(next){stop(next);}
  }
  finally{working=false;buttons();}
  if(follow)drive(false);
 }
 async function retryPhotos(){
  await boot;if(working || sessionExpired || accessBlocked || !state?.photo_retry_allowed || !el('photo-confirm-retry').checked)return;
  const expectedRun=id;working=true;active=false;buttons();let follow=false;
  try{
   render(await request(base+'/'+encodeURIComponent(id),'retry_photos',{confirm_retry_photos:true}));
   el('photo-confirm-retry').checked=false;el('message').textContent='Ponawiam wyłącznie wskazane nieudane kopie. Zamrożony snapshot i poprawne pliki pozostają zachowane.';
   follow=id===expectedRun && photoRetryPending();
  }catch(error){
   try{await recover(error,1);follow=id===expectedRun && photoRetryPending();if(follow)el('photo-confirm-retry').checked=false;}
   catch(next){stop(next);}
  }
  finally{working=false;buttons();}
  if(follow)drive(false);
 }
 el('start').onclick=()=>drive(true);el('resume').onclick=manualResume;el('stop-local').onclick=()=>{active=false;};
 el('pause').onclick=async()=>{
  await boot;if(sessionExpired || accessBlocked || !canPause() || pausing || pauseRequested)return;
  if(working){pauseRequested=true;active=false;buttons();return;}
  working=true;buttons();try{await pauseCheckpoint();}finally{working=false;buttons();}
 };
 el('retry-snapshot').onclick=()=>snapshotAction('retry_snapshot');el('abort-snapshot').onclick=()=>snapshotAction('abort_snapshot');
 el('retry-photos').onclick=retryPhotos;el('photo-confirm-retry').onchange=buttons;
 el('snapshot-confirm-retry').onchange=buttons;el('snapshot-confirm-abort').onchange=buttons;
})();
</script></body></html>
