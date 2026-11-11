<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="csrf-token" content="{{ csrf_token() }}"><title>Eksport GPSwiss → Wystawczesc</title>
<style>body{max-width:1100px;margin:30px auto;padding:20px;font:16px system-ui}button,a{margin:8px;padding:10px}pre{white-space:pre-wrap;background:#f1f5f4;padding:18px}</style></head><body>
<h1>Prywatny eksport części, samochodów i zamówień GPSwiss → Wystawczesc</h1>
<p>Eksport tworzy prywatne pliki. Nie zmienia części, zdjęć, cen, stanów, ofert ani synchronizacji. Nie wykonuje importu ani API Allegro/eBay/Ovoko.</p>
<p>Panel wykonuje krótkie partie i zapisuje postęp na serwerze. Zamrożone granice odczytu oraz sumy kontrolne tworzą wersjonowany snapshot logiczny; nie jest to jedna transakcja bazy utrzymywana między żądaniami HTTP. Granice i ograniczenia spójności znajdziesz w manifeście.</p>
<p>Próbka obejmuje do 5 rzeczywistych części, a pełny eksport używa tego samego formatu. Zdjęcia zachowują oryginalne nazwy w katalogach ID części; kolizje w obrębie części otrzymują podkatalog ID zdjęcia. Brakujące/zmienione pliki są raportowane.</p>
<p>Paczka zawiera kody, kategorię, jakość, pozycję, magazyn, opis, lokalne/Ovoko ID auta, ceny sklep/Allegro/Ovoko/eBay, Kurier Allegro, lokalne parametry i linki ofert. Zawiera wszystkie samochody, zamówienia i potrzebne dane klientów — pobieranie wyłącznie prywatnie przez zalogowanego właściciela eksportu. Bez haseł, tokenów i pełnych danych kart. Nie udostępniaj archiwum ani katalogu publicznie.</p>
<p>Części i listingi mogą zmieniać się podczas sprzedaży. Recovery części zachowuje kompletny eksport i uzgadnia tylko zmienione partie. Dotychczasowe pliki listingów i attempt pozostają zachowane. Licznik weryfikacji jest oddzielony od liczby wyeksportowanych rekordów. Rekoncyliacja ma limit trzech rund i jawny raport niestabilności.</p>
<p>Snapshot bazy nie jest globalnym snapshotem filesystemu. Kopie plików są weryfikowane podczas kopiowania; finalny cutover wymaga osobnej procedury freeze/snapshotu. Nie zatrzymujemy synchronizacji automatycznie.</p>
<p>Próbka wybiera sprzedaną część (status sold), część do wystawienia (needs_listing), oferty eBay/Ovoko i galerię — gdy takie przypadki są zapisane. Powiązania Allegro są wyłącznie historyczne. Import, publikacja, synchronizacja i wiadomości do klientów pozostają wyłączone.</p>
<button id="start" disabled>Przygotuj nowy eksport</button><button id="resume" disabled>Wznów istniejący eksport</button><button id="diagnose-conflict" hidden>Diagnozuj konflikt datasetu</button><button id="pause" disabled>Pauza snapshotu</button><button id="stop-local" disabled>Stop lokalnej pętli</button>
<section aria-label="Pakiet danych bez zdjęć">
<p>DATA ONLY korzysta z zakończonego snapshotu. Nie odczytuje ponownie bazy i nie czeka na zdjęcia. Pakiet zawiera dane klientów — pobieraj i przekazuj go prywatnie. Zdjęcia przeniesiesz osobno; zaakceptowany drift pozostaje w manifeście.</p>
<button id="data-only" disabled>Przygotuj pakiet danych bez zdjęć</button>
<p id="data-only-status" role="status"></p><ul id="data-only-downloads" hidden></ul>
<p>Zbiorcze CSV korzystają tylko z zamrożonych danych. Nie wymagają ZIP-ów ani oryginałów zdjęć. Pliki otwieraj z kolumnami ID i kodów jako tekst, aby zachować początkowe zera.</p>
<button id="consolidated-csv" disabled>Przygotuj zbiorcze CSV</button>
<button id="consolidated-csv-stop" disabled>Zatrzymaj lokalną pętlę CSV</button>
<p id="consolidated-csv-status" role="status"></p><pre id="consolidated-csv-listing-audit" aria-label="Audyt zamrożonych listingów"></pre><ul id="consolidated-csv-downloads" hidden></ul>
</section>
<section aria-labelledby="allegro-supplement-heading">
<h2 id="allegro-supplement-heading">Parametry Allegro — pakiet uzupełniający</h2>
<p>Osobny pakiet powiązany z istniejącym runem i próbą. Korzysta z zamrożonych danych oraz lokalnego cache i reguł, bez API Allegro. Nie zmienia głównego eksportu, zdjęć ani DATA ONLY. Kompletność dotyczy lokalnie rozwiązanych parametrów, nie gotowości publikacji.</p>
<p id="allegro-supplement-identity"></p>
<h3>A. Odśwież definicje parametrów z Allegro</h3>
<p>Plan czyta zamrożone dane i lokalne informacje o cache. Dopiero Uruchom wykonuje GET /parameters i GET /product-parameters dla wskazanych kategorii. Bez zapisu ofert i bez automatycznego OAuth refreshu.</p>
<button id="allegro-schema-plan" disabled>Przygotuj plan</button>
<label><input id="allegro-schema-confirm" type="checkbox"> Zatwierdzam pokazany plan GET i prywatny zapis odpowiedzi.</label>
<button id="allegro-schema-start" disabled>Uruchom odświeżanie definicji</button>
<button id="allegro-schema-resume" disabled>Wznów odświeżanie definicji</button>
<button id="allegro-schema-stop" disabled>Stop lokalnej pętli definicji</button>
<button id="allegro-schema-status-refresh" disabled>Odśwież status definicji</button>
<p id="allegro-schema-status" role="status"></p><pre id="allegro-schema-plan-preview" aria-label="Plan i wyniki GET schematów Allegro"></pre>
<h3>B. Przygotuj pakiet parametrów Allegro</h3>
<p>Nowy pakiet wymaga zakończonego etapu A. Błędy GET pozostają w raporcie świeżości i nie są zastępowane starym cache. Archiwalny pakiet lokalny pozostaje oznaczony jako legacy.</p>
<button id="allegro-supplement-start" disabled>Przygotuj pakiet parametrów Allegro</button>
<button id="allegro-supplement-resume" disabled>Wznów supplement</button>
<button id="allegro-supplement-stop" disabled>Stop lokalnej pętli supplementu</button>
<button id="allegro-supplement-status-refresh" disabled>Odśwież status supplementu</button>
<p id="allegro-supplement-status" role="status">Wybierz istniejący eksport z zakończonym zapisem rekordów.</p>
<p>Liczby rekordów cache i mapowań pochodzą ze statusu albo końcowego manifestu. „—” oznacza brak licznika w bieżącym statusie, nie zero.</p>
<ul id="allegro-supplement-counts" aria-label="Liczniki parametrów Allegro"></ul>
<ul id="allegro-supplement-downloads" aria-label="Prywatne pliki supplementu" hidden></ul>
<ul id="allegro-supplement-contents" aria-label="Zbiory CSV i JSONL w pakiecie ZIP"></ul>
</section>
<section id="parts-drift-actions" hidden>
<p>Wyłącznie bieżący run: akceptacja deklarowanych dwóch zmian części. Manifest zachowa znane ID, nieznane ID i zakres pominiętej weryfikacji. Dalszy odczyt żywej bazy zostanie pominięty; pozostają kontrola plików, SHA i relacji. Najpierw zatrzymaj lokalną pętlę.</p>
<label><input id="parts-drift-confirm" type="checkbox"> Akceptuję znany drift części oraz finalizację z kontrolą zapisanych artefaktów i relacji zamiast dalszej weryfikacji źródła.</label>
<label>Wpisz ACCEPT-PARTS-DRIFT-AND-FINALIZE <input id="parts-drift-token" autocomplete="off"></label>
<button id="accept-parts-drift" disabled>Akceptuję znany drift części i finalizuję eksport</button>
</section>
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
<p id="message" role="status">Szukam niedokończonego eksportu właściciela…</p><a id="login" href="/admin/login" hidden>Zaloguj się ponownie, potem odśwież ten panel</a><pre id="resume-diagnostic" aria-label="Diagnostyka wznowienia"></pre><pre id="dataset-conflict" aria-label="Bezpieczna diagnostyka konfliktu datasetu"></pre><pre id="state"></pre><pre id="request-diagnostic" aria-label="Diagnostyka żądania"></pre>
<a id="sample" hidden>Pobierz próbkę ZIP</a><a id="full" hidden>Pobierz pełny ZIP</a>
<ul id="download-artifacts" hidden></ul><a id="full-debug" hidden target="_blank" rel="noreferrer">Pełny status diagnostyczny</a>
<p>Cały eksport działa z tego panelu. Pauza snapshotu zapisuje stan na serwerze; Stop lokalnej pętli kończy wysyłanie kolejnych żądań. Po odświeżeniu użyj Wznów. Pobierz manifest i wszystkie wskazane części archiwum.</p>
<script>
(()=>{
 const el=id=>document.getElementById(id),base=@json(route('admin.tools.gpswiss-migration-export', [], false));
 let id=new URLSearchParams(location.hash.slice(1)).get('run'),state=null,active=false,working=false,ready=false,sessionExpired=false,accessBlocked=false,inFlight=false,pauseRequested=false,pausing=false;
 let loopDone=null,finishLoop=null,manualResuming=false,resumeTraceActive=false,retryExhausted=false,stopBeforePhotos=false,kickDiagnostic=null;
 let dataOnlyWorking=false,dataOnlyLoaded=null;
 let csvWorking=false,csvStop=false,csvLoaded=null;
 let schemaValue=null,schemaWorking=false,schemaStopped=false,schemaLoaded=null,schemaWaiting=null;
 const supplementFormat='gpswiss-allegro-parameters-supplement-v1';
 const supplementDatasets=['allegro_category_parameters_cache','allegro_parameter_mappings','allegro_resolved_parameters','conflicts','completeness','allegro_product_parameters_cache','allegro_parameter_selections','schema_freshness'];
 let supplementWorking=false,supplementStopped=false,supplementLoading=false,supplementLoaded=null,supplementValue=null,supplementManifest=null,supplementPackageKey=null,supplementWait=null,supplementViewKey=null,supplementEpoch=0;
 const supplementCurrent=(run,attempt)=>id===run && state?.snapshot_attempt_id===attempt;
 const waits=new Map(),resumeEvents=[];
 const wait=ms=>new Promise(resolve=>{const timer=setTimeout(()=>{waits.delete(timer);resolve();},ms);waits.set(timer,resolve);});
 function cancelBackoff(){for(const [timer,resolve] of waits){clearTimeout(timer);resolve();}waits.clear();}
 function runnerContext(){return {phase:state?.phase??null,state:state?.state??null,next_action:nextAction(),busy:state?.busy??false,resume_allowed:state?.resume_allowed??null,blocked_reason:state?.blocked_reason??state?.last_error??null,runner_decision:runnerDecision()};}
 function resumeEvent(event,blocked_reason=null){const context=runnerContext();resumeEvents.push({...context,event,blocked_reason:blocked_reason??context.blocked_reason});if(resumeEvents.length>30)resumeEvents.splice(3,1);el('resume-diagnostic').textContent=JSON.stringify({events:resumeEvents,retry_exhausted:retryExhausted,...context,kick_result:kickDiagnostic},null,2);}
 async function manualResume(){
  if(manualResuming)return;
  manualResuming=true;resumeTraceActive=true;kickDiagnostic=null;resumeEvents.length=0;resumeEvent('resume_started');
  try{
   await boot;
   if(sessionExpired || accessBlocked){resumeEvent('blocked',sessionExpired?'SESSION_EXPIRED':'run_owner_mismatch');return;}
   if(inFlight || pausing || (working && !loopDone)){resumeEvent('blocked','active_request_or_operation');return;}
   if(working){active=false;pauseRequested=false;cancelBackoff();await loopDone;}
   active=false;pauseRequested=false;retryExhausted=false;cancelBackoff();
   el('message').textContent='resume_started / local_retry_state_reset';
   resumeEvent('local_retry_state_reset');
   drive(false).catch(()=>resumeEvent('blocked','local_runner_error'));
  }finally{manualResuming=false;buttons();}
 }
 const reasonOf=(data,fallback)=>typeof data?.reason_code==='string' && /^[A-Za-z0-9_]{1,80}$/.test(data.reason_code)?data.reason_code:fallback;
 const snapshotState=()=>state?.snapshot_state ?? (String(state?.state ?? '').startsWith('snapshot')?state.state:null);
 const browserSnapshot=()=>state?.snapshot_mode==='browser';
 const recoverableState=s=>s?.state==='snapshot_failed' && [null,undefined,'snapshot_row_updated_after_cutoff'].includes(s.last_error) && ['parts','marketplace_listings'].includes(s.current_dataset) && s.dataset_recovery_allowed===true;
 const datasetRecoverable=()=>browserSnapshot() && recoverableState(state);
 const photoRetryPending=()=>state?.photo_retry_requested===true || ['photos_retry_setup','photos_retry_inventory','photos_retry'].includes(state?.phase);
 const unfinished=()=>state && state.state!=='completed' && snapshotState()!=='snapshot_aborted';
 const canPause=()=>browserSnapshot() && (['photos','archive'].includes(state?.state) || ['snapshot_pending','snapshot_running'].includes(snapshotState()) || (snapshotState()==='snapshot_completed' && ['relation_audit','photo_inventory'].includes(state?.phase)));
 function nextAction(){
  if(state?.next_action)return state.next_action;
  if(state?.state==='completed')return 'download';
  if(state?.busy)return 'wait';
  if(state?.resume_allowed===false)return 'blocked';
  return ['photos','archive'].includes(state?.state)?'tick':'blocked';
 }
 function runnerDecision(value=state){
  if(!value)return 'no_state';
  const next=value.next_action??(value===state?nextAction():null);
  if(value.busy || next==='wait')return 'wait';
  if(value.state==='completed' || next==='download')return 'completed';
  if(recoverableState(value))return 'resume_required';
  if(next==='resume' && value.resume_allowed!==false && !value.blocked_reason)return 'resume_required';
  if(value.last_error || value.blocked_reason || ['snapshot_failed','failed','snapshot_aborted'].includes(value.state))return 'blocked';
  if(value.resume_allowed===false)return 'blocked';
  if(next==='resume' || ['snapshot_paused','pipeline_paused'].includes(value.state))return 'resume_required';
  return ['snapshot_tick','tick'].includes(next)?'tick_ready':'blocked';
 }
 function buttons(){
  schemaButtons();
  supplementButtons();
  dataOnlyButtons();
  const blocked=!ready || sessionExpired || accessBlocked;
  el('parts-drift-actions').hidden=state?.operator_parts_drift_allowed!==true;
  el('accept-parts-drift').disabled=blocked || working || inFlight || state?.operator_parts_drift_allowed!==true || !el('parts-drift-confirm').checked || el('parts-drift-token').value!=='ACCEPT-PARTS-DRIFT-AND-FINALIZE';
  el('start').disabled=blocked || working || !!unfinished();
  el('resume').disabled=blocked || (inFlight || pausing || manualResuming) || !state || state.state==='completed' || (nextAction()==='blocked' && !state.busy && !datasetRecoverable());
  el('diagnose-conflict').hidden=!datasetRecoverable();el('diagnose-conflict').disabled=blocked || working || inFlight;
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
  if(value){state=value;id=value.run_id;history.replaceState(null,'','#run='+id);remember();}
  else if(data?.existing_run_found===false)state=null;
  loadSchemaStatus();
  loadSupplementStatus();
  loadDataOnlyStatus();
  el('state').textContent=JSON.stringify(state?{...state,local_runner_active:active,local_request_in_flight:inFlight}:data,null,2);
  const progress=state?.snapshot_progress ?? {},dataset=state?.current_dataset ?? progress.current_dataset ?? progress.dataset;
  const total=state && Object.hasOwn(state,'total')?(state.total ?? '—'):(progress.total ?? state?.total_images ?? '—');
  el('progress').textContent=state?'Run: '+id+' | Stan: '+state.state+' | Faza: '+(state.phase ?? state.state)+' | Zbiór: '+(dataset ?? '—')+' | Postęp: '+(state.processed ?? progress.processed ?? state.processed_images ?? 0)+' / '+total+' | Cursor: '+cursorText(state.dataset_cursor ?? state.cursor ?? progress.cursor)+' | Następny krok: '+nextAction():'';
  if(state?.exported_records!=null)el('progress').textContent+=' | Wyeksportowane rekordy: '+state.exported_records+' / '+(state.record_capture_total??state.snapshot_progress?.total??total)+' | Zweryfikowane: '+state.verification_processed;
  if(state?.operator_finalize_stage)el('progress').textContent+=' | Etap finalizacji: '+state.operator_finalize_stage;
  if(state?.dataset_recovery)el('progress').textContent+=' | Kontrola starej generacji: '+state.dataset_recovery.preflight_shard+' / '+state.dataset_recovery.preflight_total_shards;
  if(state?.mutable_dirty_rows!=null)el('progress').textContent+=' | Dirty wiersze: '+state.mutable_dirty_rows+' | Dirty shardy: '+state.mutable_dirty_shards+' | Runda weryfikacji: '+state.mutable_round;
  if(state?.dataset_generation!=null)el('progress').textContent+=' | Generacja datasetu: '+state.dataset_generation;
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
 const requestFailures=[],statusAttempts=[];
 let latestRequestDiagnostic=null,lastOperationFailure=null;
 function showRequestDiagnostic(){el('request-diagnostic').textContent=JSON.stringify({latest:latestRequestDiagnostic,last_operation_failure:lastOperationFailure,status_attempts:statusAttempts,recent_failures:requestFailures},null,2);}
 function checkpoint(){
  const p=state?.snapshot_progress??{},w=state?.dataset_recovery??p.dataset_recovery??{};
  return {run_id:state?.run_id??id,attempt_id:state?.snapshot_attempt_id??state?.attempt_id??null,
   phase:state?.phase??p.phase??null,operator_finalize_stage:state?.operator_finalize_stage??p.operator_finalize_stage??null,snapshot_stage:state?.snapshot_stage??p.stage??null,dataset:state?.current_dataset??p.current_dataset??null,
   generation:w.new_generation??state?.dataset_generation??p.dataset_generation??null,boundary:w.dataset_boundary_id??state?.dataset_boundary_id??p.dataset_boundary_id??null,
   preflight_shard:w.preflight_shard??null,cursor:state?.dataset_cursor??state?.cursor??p.cursor??null,
   processed:state?.processed??p.processed??null,exported_records:state?.exported_records??p.exported_records??null,
   verification_processed:state?.verification_processed??p.verification_processed??null,mutable_round:state?.mutable_round??p.mutable_round??null,
   completed:state?.state==='completed'};
 }
 const checkpointKey=({run_id,attempt_id,generation,boundary,...progress})=>JSON.stringify(progress,(_,v)=>v && typeof v==='object' && !Array.isArray(v)?Object.fromEntries(Object.entries(v).sort(([a],[b])=>a.localeCompare(b))):v);
 function checkpointAdvanced(before,after){
  if(before.run_id!==after.run_id || before.attempt_id!==after.attempt_id)throw Object.assign(new Error('run_or_attempt_changed'),{reason:'run_or_attempt_changed'});
  if(before.dataset===after.dataset){
   if((before.boundary!=null && after.boundary!=null && before.boundary!==after.boundary) || (before.generation!=null && after.generation!=null && before.generation!==after.generation))throw Object.assign(new Error('dataset_boundary_changed'),{reason:'dataset_boundary_changed'});
   if(before.phase===after.phase && before.preflight_shard!=null && after.preflight_shard!=null && after.preflight_shard<before.preflight_shard)throw Object.assign(new Error('checkpoint_regressed'),{reason:'checkpoint_regressed'});
  }
  return !state?.last_error && !state?.blocked_reason && !['snapshot_failed','failed','snapshot_aborted','snapshot_paused'].includes(state?.state) && checkpointKey(before)!==checkpointKey(after);
 }
 function safeBackend(value){
  if(!value || typeof value!=='object')return null;
  return Object.fromEntries(['action','route_name','reason_code','duration_ms','backend_exception_class','current_dataset','cursor_before','cursor_after','records_attempted','records_completed','dataset_generation','preflight_shard_before','preflight_shard_after','artifacts_checked','operator_finalize_stage','safe_exception_category','dataset_index','artifact_offset_before','artifact_offset_after','relation_offset_before','relation_offset_after','manifest_package_stage','stage_before','stage_after','request_phase'].filter(k=>Object.hasOwn(value,k)).map(k=>[k,value[k]]));
 }
 const recoveryKey='gpswiss-export-last-operational-state';
 function remember(){
  if(!state || !/^[a-f0-9-]{36}$/i.test(id??'') || !/^[a-f0-9-]{36}$/i.test(state.snapshot_attempt_id??''))return;
  const safe={};for(const key of ['run_id','snapshot_attempt_id','snapshot_mode','snapshot_state','state','phase','current_dataset','processed','total','remaining','cursor','next_action','busy','last_error','last_tick','dataset_recovery_allowed','dataset_recovery','operator_parts_drift_allowed'])safe[key]=state[key];
  safe.last_tick={reason_code:state.last_tick?.reason_code??state.last_tick_reason??null};
  try{sessionStorage.setItem(recoveryKey,JSON.stringify(safe));}catch(_){}
 }
 function restoreKnownState(){
  try{const raw=sessionStorage.getItem(recoveryKey);if(!raw || raw.length>4096)return;const saved=JSON.parse(raw);if(saved.run_id===id && /^[a-f0-9-]{36}$/i.test(saved.snapshot_attempt_id??'') && kickState(saved))render(saved);}catch(_){}
 }
 const kickState=s=>s?.snapshot_mode==='browser' && (recoverableState(s) || runnerDecision(s)==='tick_ready' && (s.next_action==='tick' || s.last_tick?.reason_code==='tick_completed'));
 const kickEligible=()=>!!id && !!state?.snapshot_attempt_id && kickState(state);
 const cursorText=value=>value==null?'—':typeof value==='object'?JSON.stringify(value):String(value);
 const transient=error=>error.reason!=='status_fetch_retry_exhausted' && (['network_error','browser_fetch_timeout','malformed_json','invalid_response','snapshot_http_tick_failed'].includes(error.reason) || [408,429,500,502,503,504].includes(error.http));
 async function request(url,action,extra={},statusAttempt=null){
  inFlight=true;buttons();const isStatus=!action && url.includes('format=compact'),controller=new AbortController(),started=performance.now(),timer=setTimeout(()=>controller.abort(),isStatus?10000:45000);
  const diagnostic={endpoint:new URL(url,location.href).pathname+new URL(url,location.href).search,route_name:'admin.tools.gpswiss-migration-export',action:action??(isStatus?'compact_status':'status'),request_kind:isStatus?'status':'operation',attempt:statusAttempt,browser_online:navigator.onLine,status_response_bytes:isStatus?0:null,malformed_json:false,content_type:null,content_length:null,http_status:null,fetch_timeout:false,network_exception_type:null,transport_reason:null,response_bytes:0,server_reason_code:null,current_dataset:state?.current_dataset??null,cursor_before:state?.cursor??null,cursor_after:null,checkpoint_before:checkpoint(),checkpoint_after:null,retry_number:0};
  try{
   const response=await fetch(url,{method:action?'POST':'GET',credentials:'same-origin',cache:'no-store',redirect:'error',signal:controller.signal,headers:{Accept:'application/json',...(action?{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content}:{})},...(action?{body:JSON.stringify(action==='kick'?extra:{action,...extra})}:{})});
   diagnostic.http_status=response.status;diagnostic.content_type=response.headers.get('content-type');diagnostic.content_length=response.headers.get('content-length');
   if([401,419].includes(response.status))throw Object.assign(new Error('SESSION_EXPIRED'),{reason:'SESSION_EXPIRED',http:response.status});
   const body=await response.text();diagnostic.response_bytes=new TextEncoder().encode(body).length;
   let data;try{data=JSON.parse(body);}catch(_){data=null;diagnostic.malformed_json=true;}
   diagnostic.server_reason_code=reasonOf(data,reasonOf(data?.status_request_diagnostic,null));diagnostic.status_response_bytes=isStatus?diagnostic.response_bytes:null;diagnostic.server_status_response_bytes=data?.status_response_bytes??null;diagnostic.backend=safeBackend(isStatus?(data?.status_request_diagnostic??null):(data?.tick_diagnostic??data?.last_tick??null));diagnostic.last_status_error=safeBackend(isStatus?data?.last_status_error:null);diagnostic.operator_finalize=safeBackend(data?.operator_finalize_diagnostic);diagnostic.backend_exception_class=data?.backend_exception_class??data?.operator_finalize_diagnostic?.backend_exception_class??(isStatus?data?.status_request_diagnostic?.backend_exception_class:data?.tick_diagnostic?.backend_exception_class)??null;
   diagnostic.backend_evidence=data?.tick_diagnostic?'response_tick_diagnostic':data?.last_tick?'last_recorded_tick_not_http_error_cause':null;
   if([401,419].includes(response.status))throw Object.assign(new Error('SESSION_EXPIRED'),{reason:'SESSION_EXPIRED',http:response.status});
   if(!response.ok)throw Object.assign(new Error('http_error'),{reason:reasonOf(data,'http_'+response.status),http:response.status});
   if(!data || typeof data!=='object')throw Object.assign(new Error('malformed_json'),{reason:'malformed_json',http:response.status});
   diagnostic.cursor_after=data.cursor??data.run?.cursor??null;
   return data;
  }catch(error){
   if(error.reason){diagnostic.reason_code=error.reason;error.diagnostic=diagnostic;throw error;}
   diagnostic.fetch_timeout=controller.signal.aborted;diagnostic.network_exception_type=['AbortError','TimeoutError','TypeError'].includes(error.name)?error.name:'Error';
   const reason=controller.signal.aborted?'browser_fetch_timeout':'network_error';diagnostic.reason_code=reason;diagnostic.transport_reason=controller.signal.aborted?'browser_fetch_timeout':'unknown_network_error';
   throw Object.assign(new Error(reason),{reason,http:diagnostic.http_status,diagnostic});
  }finally{
   diagnostic.duration_ms=Math.round(performance.now()-started);clearTimeout(timer);inFlight=false;buttons();
   if(diagnostic.reason_code){requestFailures.push(diagnostic);if(requestFailures.length>8)requestFailures.shift();if(action)lastOperationFailure=diagnostic;}
   if(isStatus && statusAttempt)statusAttempts.push(diagnostic);
   latestRequestDiagnostic=diagnostic;showRequestDiagnostic();
  }
 }

 function stop(error){
  if(error.reason==='local_stop'){active=false;return;}
  active=false;retryExhausted=transient(error) || ['status_fetch_retry_exhausted','kick_retry_exhausted'].includes(error.reason);
  if(resumeTraceActive)resumeEvent('blocked',error.reason ?? 'unknown_local_error');
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
  let last;statusAttempts.length=0;
  for(let attempt=1;attempt<=4;attempt++){
   try{const data=await request(base+'/'+encodeURIComponent(id)+'?format=compact',undefined,{},attempt);render(data);return state;}
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
 async function guardedKick(run,attempt){
  const attempts=[],maxAttempts=snapshotState()==='snapshot_completed'?3:1;
  kickDiagnostic={phase:state?.phase??null,reason_code:'kick_pending',checkpoint_before:checkpoint(),checkpoint_before_source:'browser_display_only',checkpoint_after:null,server_checkpoint_before:null,max_attempts:maxAttempts,attempts};
  resumeEvent('fallback_kick');resumeEvent('kick_fallback_started');
  for(let n=1;n<=maxAttempts;n++){
   if(!active)throw Object.assign(new Error('local_stop'),{reason:'local_stop'});
   try{
    const result=await request(base+'/'+encodeURIComponent(run)+'/kick','kick',{attempt_id:attempt});
    if(result.run_id!==run || result.snapshot_attempt_id!==attempt)throw Object.assign(new Error('run_or_attempt_changed'),{reason:'run_or_attempt_changed'});
    render(result);
    attempts.push({attempt:n,reason_code:result.kick_reason,blocked_reason:result.blocked_reason??null,http_status:latestRequestDiagnostic?.http_status??null,duration_ms:latestRequestDiagnostic?.duration_ms??null});
    kickDiagnostic={...kickDiagnostic,reason_code:result.kick_reason,blocked_reason:result.blocked_reason??null,server_checkpoint_before:result.kick_checkpoint_before??null,checkpoint_after:result.kick_checkpoint_after??checkpoint(),checkpoint_after_source:'server_kick_response',phase:state.phase,next_action:nextAction(),runner_decision:runnerDecision()};
    resumeEvent('kick_result',result.blocked_reason??null);
    if(!['tick_completed','completed','busy','recovery_started'].includes(result.kick_reason))throw Object.assign(new Error('kick_blocked'),{reason:result.blocked_reason??result.kick_reason??'kick_blocked'});
    return result;
   }catch(error){
    if(attempts.at(-1)?.attempt!==n)attempts.push({attempt:n,reason_code:error.reason??'unknown_kick_error',http_status:error.http??null,fetch_timeout:error.diagnostic?.fetch_timeout??false,network_exception_type:error.diagnostic?.network_exception_type??null,transport_reason:error.diagnostic?.transport_reason??null,response_bytes:error.diagnostic?.response_bytes??0,duration_ms:error.diagnostic?.duration_ms??null});
    kickDiagnostic={...kickDiagnostic,reason_code:error.reason??'unknown_kick_error',blocked_reason:error.reason??'unknown_kick_error'};
    resumeEvent('kick_result',kickDiagnostic.blocked_reason);
    if(!transient(error))throw error;
    if(n===maxAttempts)throw Object.assign(new Error('kick_retry_exhausted'),{reason:'kick_retry_exhausted',http:error.http});
    resumeEvent('kick_retry_scheduled');el('message').textContent='kick_retry '+n+'/'+maxAttempts+' — ponawiam od aktualnego checkpointu serwera';
    await wait(500*2**(n-1));
   }
  }
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
  catch(next){if(next.reason==='status_fetch_retry_exhausted' && resumeTraceActive && active && snapshotState()==='snapshot_completed' && kickEligible()){resumeEvent('status_fetch_retry_exhausted');await guardedKick(id,state.snapshot_attempt_id);return;}if(next.http===404 && next.reason!=='run_owner_mismatch'){if(await discover())return;}throw next;}
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
  if(state?.state==='pipeline_paused')return 'pipeline_paused — zdjęcia/archiwa zachowane. Kliknij Wznów.';
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
 restoreKnownState();
 const boot=restore();
 async function pauseCheckpoint(){
  if(!canPause() || pausing)return;pausing=true;buttons();
  try{render(await request(base+'/'+encodeURIComponent(id),'pause_snapshot'));el('message').textContent=(state.state==='pipeline_paused'?'pipeline_paused':'snapshot_paused')+' — postęp zapisany na serwerze. Wznów kontynuuje tę samą próbę.';}
  catch(error){try{await recover(error,1);el('message').textContent=restoredMessage();}catch(next){stop(next);}}
  finally{pausing=false;buttons();}
 }
 async function drive(start){
  await boot;if(working || sessionExpired || accessBlocked)return;
  working=true;active=true;loopDone=new Promise(resolve=>{finishLoop=resolve;});buttons();let attempts=0,needsResume=!start,usedKick=false,lastDecision=null;
  const expectedRun=id,expectedAttempt=state?.snapshot_attempt_id;
  try{
   if(start){
    await discover();if(unfinished()){el('message').textContent='Masz niedokończony eksport '+id+' — użyj dostępnych akcji tego runu.';return;}
    id=crypto.randomUUID();state=null;history.replaceState(null,'','#run='+id);
    try{render(await request(base+'/'+id,'start'));}catch(error){await recover(error,++attempts);}
   }else{
    if(resumeTraceActive)resumeEvent('status_loading');
    try{if(id)await readCurrent();else if(!await discover())throw Object.assign(new Error('run_not_found'),{reason:'run_not_found',http:404});}
    catch(error){
     if(resumeTraceActive && error.reason==='status_fetch_retry_exhausted')resumeEvent('status_fetch_retry_exhausted');
     if(resumeTraceActive && error.reason==='status_fetch_retry_exhausted' && kickEligible()){
      usedKick=true;
      const result=await guardedKick(expectedRun,expectedAttempt);
      if(result.kick_reason==='recovery_started'){resumeEvent(result.dataset_recovery?.strategy==='adopt_complete_shards_then_reconcile'?'reconciliation_started':'dataset_boundary_refresh_started');resumeEvent('dataset='+result.dataset_recovery?.dataset);}
      if(result.kick_reason==='tick_completed')resumeEvent('tick_completed');
      else if(result.kick_reason==='busy')resumeEvent('blocked','busy');
      needsResume=false;
     }else{if(resumeTraceActive && error.reason==='status_fetch_retry_exhausted')resumeEvent('kick_fallback_blocked',state?.blocked_reason??state?.last_error??'known_work_or_capture_proof_missing');await recover(error,++attempts);}
    }
   }
   if(!start){
    if(expectedRun && (id!==expectedRun || (expectedAttempt && state?.snapshot_attempt_id!==expectedAttempt))){resumeEvent('blocked','run_or_attempt_changed');el('message').textContent='Wznowienie zablokowane: run_or_attempt_changed';return;}
    if(resumeTraceActive){if(!usedKick)resumeEvent('status_loaded');resumeEvent('next_action='+nextAction());}
    if(browserSnapshot() && runnerDecision()==='tick_ready')needsResume=false;
    el('message').textContent=(usedKick?'kick_result':'status_loaded')+' / phase='+(state?.phase??'unknown')+' / runner_decision='+runnerDecision();
   }
   while(active && state){
    const next=nextAction(),decision=runnerDecision();
    if(resumeTraceActive && decision!==lastDecision){resumeEvent('runner_decision='+decision);lastDecision=decision;}
    if(stopBeforePhotos && state.state==='photos' && state.phase==='photos'){stopBeforePhotos=false;el('message').textContent='Dane sfinalizowane z accepted_known_drift. Zdjęcia gotowe do kopiowania — użyj Wznów, aby rozpocząć etap zdjęć.';break;}
    if(next==='download' || state.state==='completed'){el('message').textContent='Eksport gotowy. Pobierz manifest i wszystkie wskazane części archiwum.';break;}
    if(state.busy || next==='wait'){
     await wait(1000);if(!active)break;try{await readCurrent();}catch(error){if(++attempts>4)throw error;await recover(error,1);}continue;
    }
    if(decision==='blocked'){if(resumeTraceActive)resumeEvent('blocked',state.blocked_reason ?? state.last_error ?? 'server_work_not_runnable');el('message').textContent='Wznowienie zablokowane: '+(state.blocked_reason ?? state.last_error ?? 'server_work_not_runnable')+'. Sprawdź dostępne akcje snapshotu.';break;}
    if(browserSnapshot() && decision==='tick_ready')needsResume=false;
    if(needsResume || decision==='resume_required'){
     const recovering=datasetRecoverable();if(recovering)resumeEvent(state.current_dataset==='parts'?'recoverable_mutable_dataset_conflict':'recoverable_dataset_conflict');
     try{render(await request(base+'/'+encodeURIComponent(id),'resume',{expected_snapshot_attempt:state.snapshot_attempt_id??null}));needsResume=!!state.busy;attempts=0;
     }
     catch(error){if(++attempts>4)throw error;await recover(error,1);}
      if(recovering && state.dataset_recovery){resumeEvent(state.dataset_recovery.strategy==='adopt_complete_shards_then_reconcile'?'reconciliation_started':'dataset_boundary_refresh_started');resumeEvent('dataset='+state.dataset_recovery.dataset);resumeEvent('previous_generation='+state.dataset_recovery.previous_generation);resumeEvent('new_generation='+state.dataset_recovery.new_generation);resumeEvent(state.state);}
     continue;
    }
    if(!['snapshot_tick','tick'].includes(next)){el('message').textContent='Nieznany następny krok: '+next+'. Checkpoint zachowany.';break;}
    const run=id,attempt=state.snapshot_attempt_id ?? null,before=checkpoint();
    if(resumeTraceActive)resumeEvent('tick_scheduled');
    try{
     render(await request(base+'/'+encodeURIComponent(id),next,next==='snapshot_tick'?{expected_snapshot_attempt:attempt}:{}));
     if(state.last_error)throw Object.assign(new Error(state.last_error),{reason:state.last_error});
     attempts=0;
     if(resumeTraceActive && !resumeEvents.some(e=>e.event==='tick_completed'))resumeEvent('tick_completed');
    }catch(error){
     attempts++;
     if(error.diagnostic)error.diagnostic.retry_number=attempts;
     let progressed=false;
     try{
      // Even the fifth error may follow a committed tick. Poll before exhausting its budget.
      await recover(error,Math.min(attempts,4));
      const after=checkpoint();
      if(error.diagnostic)error.diagnostic.checkpoint_after=after;
      progressed=checkpointAdvanced(before,after);
      if(error.diagnostic)error.diagnostic.checkpoint_progress_detected=progressed;
     }finally{showRequestDiagnostic();}
     if(progressed){attempts=0;retryExhausted=false;if(resumeTraceActive){resumeEvent('checkpoint_progress_detected');resumeEvent('retry_counter_reset');}}
     else if(attempts>=5)throw error;
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
 async function acceptPartsDrift(){
  await boot;if(working || inFlight || sessionExpired || accessBlocked || state?.operator_parts_drift_allowed!==true || !el('parts-drift-confirm').checked || el('parts-drift-token').value!=='ACCEPT-PARTS-DRIFT-AND-FINALIZE')return;
  const run=id,attempt=state.snapshot_attempt_id;working=true;buttons();let follow=false;
  try{render(await request(base+'/'+encodeURIComponent(run),'accept_parts_drift',{expected_snapshot_attempt:attempt,confirm_parts_drift:el('parts-drift-token').value}));follow=state?.consistency_exception?.accepted_by_operator===true;if(!follow)el('message').textContent='accept_not_confirmed / '+(state?.busy?'busy':'not_accepted')+' — zatrzymaj pozostałe pętle i sprawdź status.';}
  catch(error){try{await recover(error,1);follow=id===run && state?.snapshot_attempt_id===attempt && state?.consistency_exception?.accepted_by_operator===true;if(!follow)el('message').textContent='accept_not_confirmed — sprawdź status; nie utworzono nowej próby.';}catch(next){stop(next);}}
  finally{working=false;buttons();}
  if(follow){retryExhausted=false;resumeTraceActive=true;resumeEvents.length=0;resumeEvent('operator_accepts_known_parts_drift');el('parts-drift-confirm').checked=false;el('parts-drift-token').value='';stopBeforePhotos=true;drive(false);}
 }
 function dataOnlyAvailable(){return browserSnapshot() && typeof state?.snapshot_attempt_id==='string' && (state.snapshot_state==='snapshot_completed' || state.snapshot_stage==='completed' || state.snapshot_progress?.done===true || ['photos','archive','completed'].includes(state.state));}
 function dataOnlyButtons(){
  el('consolidated-csv').disabled=csvWorking || sessionExpired || accessBlocked || !id || !dataOnlyAvailable();
  el('consolidated-csv-stop').disabled=!csvWorking;el('data-only').disabled=dataOnlyWorking || sessionExpired || accessBlocked || !id || !dataOnlyAvailable();}
 function showDataOnly(value,run,attempt){
  if(id!==run || state?.snapshot_attempt_id!==attempt)return;
  el('data-only-status').textContent=value.done?'Pakiet danych bez zdjęć gotowy. Pobierz oba manifesty i wszystkie wolumeny ZIP.':'DATA ONLY: '+value.phase+' | rekordy '+value.records+'/'+value.total_records+' | pliki '+value.files_checked+' | wolumeny '+value.volume_count;
  const list=el('data-only-downloads');list.replaceChildren();list.hidden=!value.done;
  if(value.done)for(const file of value.downloads??[]){
   if(!/^(data-only-package-manifest|data-only-archive-manifest|volume-[0-9]{6})$/.test(file.key??''))continue;
   const item=document.createElement('li'),link=document.createElement('a');link.textContent=file.name;link.href=base+'/'+encodeURIComponent(run)+'/data-only?parent_attempt_id='+encodeURIComponent(attempt)+'&download='+encodeURIComponent(file.key);item.append(link);
   item.append(document.createTextNode(' '+file.bytes+' B | SHA-256: '+file.sha256));list.append(item);
  }
 }
 async function dataOnlyRequest(run,attempt,action){
  const endpoint=base+'/'+encodeURIComponent(run)+'/data-only',controller=new AbortController(),timer=setTimeout(()=>controller.abort(),30000);
  try{
   const response=await fetch(action?endpoint:endpoint+'?parent_attempt_id='+encodeURIComponent(attempt),{method:action?'POST':'GET',credentials:'same-origin',headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},...(action?{body:JSON.stringify({parent_attempt_id:attempt,action})}:{}),signal:controller.signal});
   let value;try{value=await response.json();}catch(_){throw Object.assign(new Error('data_only_invalid_response'),{http:response.status});}
   if(!response.ok)throw Object.assign(new Error(value.reason_code??('http_'+response.status)),{http:response.status});
   return value;
  }finally{clearTimeout(timer);}
 }
 function loadDataOnlyStatus(){
  loadCsvStatus();
  if(!id || !dataOnlyAvailable() || dataOnlyWorking)return;
  const run=id,attempt=state.snapshot_attempt_id,key=run+':'+attempt;
  if(dataOnlyLoaded===key)return;dataOnlyLoaded=key;
  queueMicrotask(async()=>{try{showDataOnly(await dataOnlyRequest(run,attempt),run,attempt);}catch(_){/* Not prepared yet; the explicit button starts/resumes it. */}});
 }
 async function prepareDataOnly(){
  await boot;if(dataOnlyWorking || !dataOnlyAvailable() || sessionExpired || accessBlocked)return;
  const run=id,attempt=state.snapshot_attempt_id;
  dataOnlyWorking=true;dataOnlyButtons();el('data-only-downloads').hidden=true;
  let action='start',failures=0;
  try{
   while(id===run && state?.snapshot_attempt_id===attempt){
    try{
     const value=await dataOnlyRequest(run,attempt,action);showDataOnly(value,run,attempt);failures=0;
     if(value.done)return;action='tick';
     await new Promise(resolve=>setTimeout(resolve,150));
    }catch(error){
     if((error.http && error.http<500 && error.message!=='data_only_busy') || ++failures>4)throw error;
     el('data-only-status').textContent='DATA ONLY retry '+failures+'/4 — checkpoint zachowany';
     await new Promise(resolve=>setTimeout(resolve,Math.min(4000,500*2**failures)));
     try{const value=await dataOnlyRequest(run,attempt);showDataOnly(value,run,attempt);if(value.done)return;action='tick';}catch(_){/* Repeat only this package operation. */}
    }
   }
   throw new Error('data_only_parent_changed');
  }catch(error){el('data-only-status').textContent='DATA ONLY zatrzymany: '+(error.message??'network_error')+'. Kliknij ponownie, aby kontynuować własny checkpoint.';}
  finally{dataOnlyWorking=false;dataOnlyButtons();}
 }

 const csvNames=['czesci.csv','sprzedane.csv','do_wystawienia.csv','moje_samochody.csv','zdjecia_czesci.csv','zdjecia_czesci_issues.csv','marketplace_listingi_czesci.csv','marketplace_listingi_czesci_issues.csv'];
 function showCsv(value,run,attempt){
  if(run!==id || attempt!==state?.snapshot_attempt_id)return;
  el('consolidated-csv-status').textContent=value.done?'Zbiorcze CSV gotowe. Pobierz pliki części, samochodów, zdjęć i listingów oraz raporty problemów.':'CSV: '+value.phase+' | przetworzone wiersze źródłowe: '+(value.processed??0)+' | shard: '+(value.shard??0)+' | wiersz: '+(value.row??0);
  el('consolidated-csv-listing-audit').textContent=value.listing_audit?JSON.stringify(value.listing_audit,null,2):'';
  const list=el('consolidated-csv-downloads');list.replaceChildren();list.hidden=!value.done;
  if(value.done)for(const file of value.downloads??[]){
   if(!csvNames.includes(file.key))continue;
   const item=document.createElement('li'),link=document.createElement('a');link.textContent='Pobierz '+file.key;
   link.href=base+'/'+encodeURIComponent(run)+'/consolidated-csv?parent_attempt_id='+encodeURIComponent(attempt)+'&download='+encodeURIComponent(file.key);
   item.append(link,document.createTextNode(' | rekordy: '+file.rows+' | '+file.bytes+' B | SHA-256: '+file.sha256));list.append(item);
  }
 }
 async function csvRequest(run,attempt,action){
  const endpoint=base+'/'+encodeURIComponent(run)+'/consolidated-csv',controller=new AbortController(),timer=setTimeout(()=>controller.abort(),30000);
  try{
   const response=await fetch(action?endpoint:endpoint+'?parent_attempt_id='+encodeURIComponent(attempt),{method:action?'POST':'GET',credentials:'same-origin',headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},...(action?{body:JSON.stringify({parent_attempt_id:attempt,action})}:{}),signal:controller.signal});
   let value;try{value=await response.json();}catch(_){throw Object.assign(new Error('csv_invalid_response'),{http:response.status});}
   if(!response.ok)throw Object.assign(new Error(reasonOf(value,'http_'+response.status)),{http:response.status});
   if(value.run_id!==run || value.attempt_id!==attempt)throw new Error('csv_identity_mismatch');
   return value;
  }finally{clearTimeout(timer);}
 }
 function loadCsvStatus(){
  if(!id || !dataOnlyAvailable() || csvWorking)return;
  const run=id,attempt=state.snapshot_attempt_id,key=run+':'+attempt;
  if(csvLoaded===key)return;csvLoaded=key;
  queueMicrotask(async()=>{
   try{showCsv(await csvRequest(run,attempt),run,attempt);}
   catch(error){csvLoaded=null;if(error.http!==404)el('consolidated-csv-status').textContent='Odczyt CSV: '+(error.message??'network_error')+'. Kliknij Przygotuj zbiorcze CSV, aby odczytać checkpoint i wznowić.';}
  });
 }
 async function prepareCsv(){
  await boot;if(csvWorking || !id || !dataOnlyAvailable() || sessionExpired || accessBlocked)return;
  const run=id,attempt=state.snapshot_attempt_id;csvWorking=true;csvStop=false;dataOnlyButtons();
  let action='start',failures=0;
  try{
   while(!csvStop && id===run && state?.snapshot_attempt_id===attempt){
    try{
     const value=await csvRequest(run,attempt,action);showCsv(value,run,attempt);failures=0;
     if(value.done)return;action='tick';
     await new Promise(resolve=>setTimeout(resolve,150));
    }catch(error){
     if((error.http && error.http<500 && error.message!=='csv_busy') || ++failures>4)throw error;
     el('consolidated-csv-status').textContent='CSV: ponowienie '+failures+'/4; checkpoint zachowany';
     await new Promise(resolve=>setTimeout(resolve,Math.min(4000,500*2**failures)));
     try{const value=await csvRequest(run,attempt);showCsv(value,run,attempt);if(value.done)return;action='tick';}catch(_){/* Same CSV checkpoint only. */}
    }
   }
   el('consolidated-csv-status').textContent='CSV zatrzymane lokalnie. Kliknij Przygotuj zbiorcze CSV, aby wznowić ten sam checkpoint.';
  }catch(error){el('consolidated-csv-status').textContent='CSV zatrzymane: '+(error.message??'network_error')+'. Kliknij Przygotuj zbiorcze CSV, aby wznowić.';}
  finally{csvWorking=false;dataOnlyButtons();}
 }
 // Supplement state, delays and requests are independent of the parent, DATA ONLY and CSV runners.
 function supplementButtons(){
  const disabled=!ready || sessionExpired || accessBlocked || !id || !dataOnlyAvailable() || schemaWorking || supplementWorking || supplementLoading;
  el('allegro-supplement-start').disabled=disabled || schemaValue?.done!==true || supplementValue?.done===true;
  el('allegro-supplement-resume').disabled=disabled || schemaValue?.done!==true || supplementValue?.done===true;
  el('allegro-supplement-status-refresh').disabled=disabled;
  el('allegro-supplement-stop').disabled=!supplementWorking || supplementStopped;
 }
 function supplementCancelDelay(){if(supplementWait){clearTimeout(supplementWait.timer);supplementWait.resolve();supplementWait=null;}}
 function supplementDelay(ms){return new Promise(resolve=>{supplementWait={resolve,timer:setTimeout(()=>{supplementWait=null;resolve();},ms)};});}
 function supplementResetView(){
  supplementEpoch++;
  supplementValue=null;supplementManifest=null;supplementPackageKey=null;supplementLoaded=null;
  el('allegro-supplement-downloads').replaceChildren();el('allegro-supplement-downloads').hidden=true;
  el('allegro-supplement-contents').replaceChildren();el('allegro-supplement-counts').replaceChildren();
  el('allegro-supplement-status').textContent='Pakiet nie został jeszcze odczytany.';
 }
 function supplementError(error){
  const reason=typeof error?.reason==='string' && /^[A-Za-z0-9_]{1,80}$/.test(error.reason)?error.reason:'supplement_network_error';
  return reason+(error.http?' / HTTP '+error.http:'');
 }
 const supplementTransient=error=>!error.http || [429,500,502,503,504].includes(error.http) || error.reason==='supplement_busy';
 async function supplementRequest(run,attempt,{action=null,download=null}={}){
  const endpoint=base+'/'+encodeURIComponent(run)+'/allegro-parameters-supplement',controller=new AbortController();let timedOut=false;
  const timer=setTimeout(()=>{timedOut=true;controller.abort();},action?30000:15000);
  const fresh=schemaValue?.done && schemaValue.plan_id?{fresh_plan_id:schemaValue.plan_id}:{};
  const query=new URLSearchParams({parent_attempt_id:attempt,...fresh});if(download)query.set('download',download);
  try{
   const response=await fetch(action?endpoint:endpoint+'?'+query,{method:action?'POST':'GET',credentials:'same-origin',cache:'no-store',redirect:'error',headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},...(action?{body:JSON.stringify({parent_attempt_id:attempt,action,...fresh})}:{}),signal:controller.signal});
   if(response.redirected || [401,419].includes(response.status))throw {reason:'supplement_session_expired',http:response.status};
   let value;try{value=await response.json();}catch(_){throw {reason:'supplement_invalid_response',http:response.status};}
   if(!response.ok)throw {reason:reasonOf(value,'http_'+response.status),http:response.status};
   if(!download && fresh.fresh_plan_id && value.fresh_plan_id!==fresh.fresh_plan_id)throw {reason:'supplement_refresh_plan_changed',http:409};
   if(!download && (value?.format!==supplementFormat || value.parent_run_id!==run || value.parent_attempt_id!==attempt || typeof value.done!=='boolean' || value.next_action!==(value.done?'download':'tick')))throw {reason:'supplement_identity_or_contract_mismatch',http:409};
   return value;
  }catch(error){if(error?.reason)throw error;throw {reason:timedOut?'supplement_fetch_timeout':'supplement_network_error'};}
  finally{clearTimeout(timer);}
 }
 function supplementCount(value){return Number.isSafeInteger(value) && value>=0?String(value):'—';}
 function supplementShowCounts(){
  const summary=supplementValue?.summary??{},datasets=supplementManifest?.datasets??{},list=el('allegro-supplement-counts');list.replaceChildren();
  const counts=[['Rekordy category/schema cache',datasets.allegro_category_parameters_cache?.rows??supplementValue?.dataset_counts?.allegro_category_parameters_cache],['Parameter mappings',datasets.allegro_parameter_mappings?.rows??supplementValue?.dataset_counts?.allegro_parameter_mappings],['Resolved parameters',datasets.allegro_resolved_parameters?.rows??summary.resolved_parameter_rows],
   ...['complete','partial','historical_only','schema_unknown','conflict'].map(key=>[key,summary.completeness?.[key]]),['Brakujące required wg lokalnego cache',summary.missing_required_values],['Raport konfliktów — rekordy',summary.conflicts],...['COMPLETE','PARTIAL','SCHEMA_UNKNOWN','CONFLICT'].map(key=>['Część/kategoria '+key,summary.part_category_completeness?.[key]])];
  for(const [label,value] of counts){const item=document.createElement('li');item.textContent=label+': '+supplementCount(value);list.append(item);}
 }
 function supplementShow(value,run,attempt){
  if(!supplementCurrent(run,attempt))return;
  supplementValue=value;
  el('allegro-supplement-status').textContent=value.schema_mode==='legacy_local_only'?'Archiwalny pakiet lokalny — bez świeżych schematów. Najpierw wykonaj etap A.':value.done?'Pakiet parametrów Allegro gotowy. Pobierz manifesty, checksumy i wszystkie wolumeny ZIP.':'Supplement: '+value.phase+' | shard: '+(value.shard??'—')+' | wiersz: '+(value.row??'—')+' | następny krok: '+value.next_action;
  if(!value.done){el('allegro-supplement-downloads').replaceChildren();el('allegro-supplement-downloads').hidden=true;}
  supplementShowCounts();supplementButtons();
 }
 function supplementLink(list,run,attempt,key,label,descriptor={}){
  if(!/^(manifest|checksums|summary|archive-manifest|volume-[0-9]{6})$/.test(key))return;
  const item=document.createElement('li'),link=document.createElement('a');link.textContent=label;
  link.href=base+'/'+encodeURIComponent(run)+'/allegro-parameters-supplement?'+new URLSearchParams({parent_attempt_id:attempt,download:key,...(schemaValue?.done && schemaValue.plan_id?{fresh_plan_id:schemaValue.plan_id}:{})});
  item.append(link);if(descriptor.bytes!=null)item.append(document.createTextNode(' | '+supplementCount(descriptor.bytes)+' B'));
  if(typeof descriptor.sha256==='string' && /^[a-f0-9]{64}$/i.test(descriptor.sha256))item.append(document.createTextNode(' | SHA-256: '+descriptor.sha256));list.append(item);
 }
 async function supplementLoadPackage(run,attempt){
  if(!supplementCurrent(run,attempt) || !supplementValue?.done || supplementPackageKey===run+':'+attempt)return;
  const epoch=supplementEpoch;
  const list=el('allegro-supplement-downloads');list.replaceChildren();list.hidden=false;
  for(const [key,label] of [['manifest','Pobierz manifest supplementu'],['checksums','Pobierz checksumy'],['summary','Pobierz podsumowanie'],['archive-manifest','Pobierz manifest archiwów']])supplementLink(list,run,attempt,key,label);
  const [manifest,archive]=await Promise.all([supplementRequest(run,attempt,{download:'manifest'}),supplementRequest(run,attempt,{download:'archive-manifest'})]);
  if(!supplementCurrent(run,attempt) || epoch!==supplementEpoch)return;
  if(manifest?.format!==supplementFormat || manifest.parent_run_id!==run || manifest.parent_attempt_id!==attempt || (schemaValue?.done && manifest.schema_refresh?.plan_id!==schemaValue.plan_id))throw {reason:'supplement_manifest_identity_mismatch',http:409};
  if(archive?.format!=='gpswiss-browser-zip-volumes-1' || !Array.isArray(archive.volumes) || !Number.isSafeInteger(archive.volume_count) || archive.volume_count!==archive.volumes.length || archive.volumes.some(v=>!/^volume-[0-9]{6}$/.test(v?.key??'')) || new Set(archive.volumes.map(v=>v.key)).size!==archive.volumes.length)throw {reason:'supplement_invalid_archive_manifest',http:409};
  supplementManifest=manifest;supplementShowCounts();
  const contents=el('allegro-supplement-contents');contents.replaceChildren();
  for(const name of supplementDatasets){const item=document.createElement('li');item.textContent=name+'.csv / .jsonl — w ZIP | rekordy: '+supplementCount(manifest.datasets?.[name]?.rows);contents.append(item);}
  for(const volume of archive.volumes)supplementLink(list,run,attempt,volume.key,'Pobierz '+(typeof volume.name==='string'?volume.name:volume.key+'.zip'),volume);
  supplementPackageKey=run+':'+attempt;
 }
 async function supplementReadStatus(force=false){
  if(!id || !dataOnlyAvailable())return;
  const run=id,attempt=state.snapshot_attempt_id,key=run+':'+attempt;
  if(supplementViewKey!==key){supplementViewKey=key;supplementStopped=true;supplementCancelDelay();supplementResetView();}
  el('allegro-supplement-identity').textContent='Run: '+run+' | parent_attempt_id: '+attempt;
  if(supplementWorking || supplementLoading || (!force && supplementLoaded===key))return;
  supplementLoaded=key;supplementLoading=true;supplementButtons();const epoch=supplementEpoch;
  try{const value=await supplementRequest(run,attempt);if(epoch!==supplementEpoch)return;supplementShow(value,run,attempt);if(value.done)await supplementLoadPackage(run,attempt);}
  catch(error){if(supplementCurrent(run,attempt))el('allegro-supplement-status').textContent=error.http===404 && error.reason==='supplement_not_started'?'Supplement nie został przygotowany. Użyj „Przygotuj pakiet parametrów Allegro”.':'Odczyt supplementu: '+supplementError(error)+'. Odśwież status lub użyj Wznów.';}
  finally{supplementLoading=false;supplementButtons();}
 }
 function loadSupplementStatus(){supplementReadStatus().catch(()=>{el('allegro-supplement-status').textContent='supplement_local_status_error — odśwież status.';});}
 async function supplementFinish(run,attempt){
  try{await supplementLoadPackage(run,attempt);}
  catch(error){if(supplementCurrent(run,attempt))el('allegro-supplement-status').textContent='Pakiet gotowy; odczyt listy plików: '+supplementError(error)+'. Użyj Odśwież status supplementu.';}
 }
 const supplementCheckpoint=value=>JSON.stringify([value?.phase,value?.shard,value?.row,value?.summary,value?.done]);
 async function supplementDrive(start){
  await boot;if(schemaValue?.done!==true || supplementWorking || supplementLoading || !id || !dataOnlyAvailable() || sessionExpired || accessBlocked || supplementValue?.done)return;
  const run=id,attempt=state.snapshot_attempt_id;supplementWorking=true;supplementStopped=false;supplementCancelDelay();supplementButtons();
  let action=start?'start':null,failures=0;
  try{
   while(!supplementStopped && supplementCurrent(run,attempt)){
    try{
     const value=await supplementRequest(run,attempt,action?{action}:{});supplementShow(value,run,attempt);failures=0;
     if(value.done){await supplementFinish(run,attempt);return;}
     action='tick';if(!supplementStopped)await supplementDelay(150);
    }catch(error){
     if(!supplementTransient(error) || ++failures>4)throw error;
     if(supplementStopped || !supplementCurrent(run,attempt))break;
     el('allegro-supplement-status').textContent='Supplement: ponowienie '+failures+'/4 | '+supplementError(error)+' | checkpoint zachowany';
     await supplementDelay(Math.min(4000,500*2**(failures-1)));
     if(supplementStopped || !supplementCurrent(run,attempt))break;
     try{
      const before=supplementCheckpoint(supplementValue),value=await supplementRequest(run,attempt);supplementShow(value,run,attempt);
      if(supplementCheckpoint(value)!==before)failures=0;
      if(value.done){await supplementFinish(run,attempt);return;}
      action='tick';
     }catch(statusError){if(!(action==='start' && statusError.http===404 && statusError.reason==='supplement_not_started') && !supplementTransient(statusError))throw statusError;}
    }
   }
   if(supplementCurrent(run,attempt))el('allegro-supplement-status').textContent='Lokalna pętla supplementu zatrzymana. Checkpoint zachowany; użyj Wznów.';
  }catch(error){if(supplementCurrent(run,attempt))el('allegro-supplement-status').textContent='Supplement zatrzymany: '+supplementError(error)+'. Checkpoint zachowany; odśwież status lub użyj Wznów.';}
  finally{supplementWorking=false;supplementButtons();}
 }
 function schemaButtons(){
  const disabled=!ready || !id || !dataOnlyAvailable() || sessionExpired || accessBlocked || schemaWorking || supplementWorking;
  el('allegro-schema-plan').disabled=disabled || (!!schemaValue && !schemaValue.done);
  el('allegro-schema-plan').textContent=schemaValue?.done?'Przygotuj nowy plan odświeżenia':'Przygotuj plan';
  el('allegro-schema-start').disabled=disabled || schemaValue?.phase!=='planned' || !el('allegro-schema-confirm').checked;
  el('allegro-schema-resume').disabled=disabled || !schemaValue || ['planned','completed'].includes(schemaValue.phase);
  el('allegro-schema-stop').disabled=!schemaWorking || schemaStopped;
  el('allegro-schema-status-refresh').disabled=disabled;
 }
 function schemaDelay(ms){return new Promise(resolve=>{schemaWaiting={resolve,timer:setTimeout(()=>{schemaWaiting=null;resolve();},ms)};});}
 function schemaStop(){schemaStopped=true;if(schemaWaiting){clearTimeout(schemaWaiting.timer);schemaWaiting.resolve();schemaWaiting=null;}schemaButtons();}
 async function schemaRequest(run,attempt,action=null){
  const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),20000),endpoint=base+'/'+encodeURIComponent(run)+'/allegro-schema-refresh';
  try{
   const response=await fetch(action?endpoint:endpoint+'?'+new URLSearchParams({parent_attempt_id:attempt}),{method:action?'POST':'GET',credentials:'same-origin',cache:'no-store',redirect:'error',signal:controller.signal,headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},...(action?{body:JSON.stringify({parent_attempt_id:attempt,action,...(action==='start'?{plan_digest:schemaValue?.plan_digest,confirm:true}:{})})}:{})});
   let value;try{value=await response.json();}catch(_){throw {reason:'schema_invalid_response',http:response.status};}
   if(!response.ok)throw {reason:reasonOf(value,'http_'+response.status),http:response.status};
   if(value.parent_run_id!==run || value.parent_attempt_id!==attempt)throw {reason:'schema_identity_mismatch',http:409};
   if(!['planning','preview','planned','fetch','blocked','completed'].includes(value.phase) || typeof value.done!=='boolean')throw {reason:'schema_contract_mismatch',http:409};
   return value;
  }catch(error){if(error.reason)throw error;throw {reason:'schema_network_or_timeout'};}
  finally{clearTimeout(timer);}
 }
 function schemaShow(value,run,attempt){
  if(!supplementCurrent(run,attempt))return;
  const changedPlan=schemaValue?.plan_id && value.plan_id!==schemaValue.plan_id;
  const finished=value.done && schemaValue!==null && (schemaValue.done!==true || changedPlan);schemaValue=value;
  el('allegro-schema-status').textContent='Definicje: '+value.phase+' | kategorie '+value.processed+' / '+value.total+' | krok planu: '+(value.stage??'—')+' | shard/wiersz: '+(value.shard??'—')+'/'+(value.row??'—')+' | plan GET: '+value.expected_gets+' | wykonano GET: '+supplementCount(value.performed_gets)+' | sukcesy: '+value.successes+' | 404: '+value.not_found_404+' | 429: '+value.provider_429+' | błędy: '+value.errors+' | brakujące schematy: '+value.missing_schemas+' | ostatni refresh: '+(value.completed_at??value.previous_completed_at??'—')+(value.blocked_reason?' | blokada: '+value.blocked_reason:'');
  el('allegro-schema-plan-preview').textContent=JSON.stringify({plan_id:value.plan_id,plan_digest:value.plan_digest,categories:value.categories},null,2);
  schemaButtons();supplementButtons();
  if(finished || changedPlan){el('allegro-schema-confirm').checked=false;supplementLoaded=null;supplementViewKey=null;supplementResetView();loadSupplementStatus();}
 }
 function loadSchemaStatus(force=false){
  if(!id || !dataOnlyAvailable() || schemaWorking)return;
  const run=id,attempt=state.snapshot_attempt_id,key=run+':'+attempt;
  if(!force && schemaLoaded===key)return;
  if(schemaLoaded!==key){schemaValue=null;schemaStop();}
  schemaLoaded=key;
  schemaRequest(run,attempt).then(value=>schemaShow(value,run,attempt)).catch(error=>{if(supplementCurrent(run,attempt))el('allegro-schema-status').textContent=error.http===404 && error.reason==='schema_plan_not_started'?'Przygotuj plan — wejście na stronę nie pobiera danych z Allegro.':'Status definicji: '+supplementError(error);}).finally(schemaButtons);
 }
 async function schemaDrive(action){
  await boot;if(schemaWorking || !id || !dataOnlyAvailable() || sessionExpired || accessBlocked || (action==='start' && schemaValue?.phase==='planned' && !el('allegro-schema-confirm').checked))return;
  const run=id,attempt=state.snapshot_attempt_id;schemaWorking=true;schemaStopped=false;schemaButtons();let failures=0;
  try{
   while(!schemaStopped && supplementCurrent(run,attempt)){
    try{
     const value=await schemaRequest(run,attempt,action);schemaShow(value,run,attempt);failures=0;
     if(['planned','blocked','completed'].includes(value.phase))return;
     action='tick';if(!schemaStopped)await schemaDelay(Math.max(150,Math.min(30000,(value.wait_seconds??0)*1000)));
    }catch(error){
     if(!supplementTransient(error) || ++failures>4)throw error;
     await schemaDelay(Math.min(4000,500*2**failures));
     if(schemaStopped)break;
     try{const value=await schemaRequest(run,attempt);schemaShow(value,run,attempt);if(['planned','blocked','completed'].includes(value.phase))return;action='tick';}catch(statusError){if(!supplementTransient(statusError))throw statusError;}
    }
   }
   if(supplementCurrent(run,attempt))el('allegro-schema-status').textContent+=' | lokalna pętla zatrzymana; użyj Wznów';
  }catch(error){if(supplementCurrent(run,attempt))el('allegro-schema-status').textContent='Definicje zatrzymane: '+supplementError(error);}
  finally{schemaWorking=false;schemaButtons();}
 }
 el('allegro-schema-plan').onclick=()=>schemaDrive(schemaValue?.done?'new_plan':'plan');
 el('allegro-schema-start').onclick=()=>schemaDrive('start');
 el('allegro-schema-resume').onclick=()=>schemaDrive(schemaValue?.phase==='blocked'?'start':null);
 el('allegro-schema-stop').onclick=schemaStop;
 el('allegro-schema-confirm').onchange=schemaButtons;
 el('allegro-schema-status-refresh').onclick=()=>loadSchemaStatus(true);
 el('allegro-supplement-start').onclick=()=>supplementDrive(true);
 el('allegro-supplement-resume').onclick=()=>supplementDrive(false);
 el('allegro-supplement-status-refresh').onclick=()=>supplementReadStatus(true);
 el('allegro-supplement-stop').onclick=()=>{supplementStopped=true;supplementCancelDelay();supplementButtons();};

 el('consolidated-csv').onclick=prepareCsv;
 el('consolidated-csv-stop').onclick=()=>{csvStop=true;};
 el('data-only').onclick=prepareDataOnly;
 el('accept-parts-drift').onclick=acceptPartsDrift;el('parts-drift-confirm').onchange=buttons;el('parts-drift-token').oninput=buttons;
 el('diagnose-conflict').onclick=async()=>{
  await boot;if(working || inFlight || !datasetRecoverable())return;
  try{el('dataset-conflict').textContent=JSON.stringify(await request(base+'/'+encodeURIComponent(id)+'?format=dataset-conflict'),null,2);}
  catch(error){el('dataset-conflict').textContent=JSON.stringify({reason_code:error.reason});if(error.reason==='SESSION_EXPIRED')stop(error);}
 };
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
