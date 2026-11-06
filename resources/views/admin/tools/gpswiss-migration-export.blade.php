<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="csrf-token" content="{{ csrf_token() }}"><title>Eksport GPSwiss → Wystawczesc</title>
<style>body{max-width:1100px;margin:30px auto;padding:20px;font:16px system-ui}button,a{margin:8px;padding:10px}pre{white-space:pre-wrap;background:#f1f5f4;padding:18px}</style></head><body>
<h1>Prywatny eksport części, samochodów i zamówień GPSwiss → Wystawczesc</h1>
<p>Eksport tworzy prywatne pliki. Nie zmienia części, zdjęć, cen, stanów, ofert ani synchronizacji. Nie wykonuje importu ani API Allegro/eBay/Ovoko.</p>
<p>Jeden spójny snapshot bazy → próbka do 5 rzeczywistych części i pełna paczka w tym samym formacie. Zdjęcia zachowują oryginalne nazwy w katalogach ID części; kolizje w obrębie części otrzymują podkatalog ID zdjęcia. Brakujące/zmienione pliki są raportowane.</p>
<p>Paczka zawiera kody, kategorię, jakość, pozycję, magazyn, opis, lokalne/Ovoko ID auta, ceny sklep/Allegro/Ovoko/eBay, Kurier Allegro, lokalne parametry i linki ofert. Zawiera wszystkie samochody, zamówienia i potrzebne dane klientów — pobieranie wyłącznie prywatnie przez zalogowanego właściciela eksportu. Bez haseł, tokenów i pełnych danych kart. Nie udostępniaj archiwum ani katalogu publicznie.</p>
<p>Snapshot bazy nie jest globalnym snapshotem filesystemu. Kopie plików są weryfikowane podczas kopiowania; finalny cutover wymaga osobnej procedury freeze/snapshotu. Nie zatrzymujemy synchronizacji automatycznie.</p>
<p>Próbka wybiera sprzedaną część (status sold), część do wystawienia (needs_listing), oferty eBay/Ovoko i galerię — gdy takie przypadki są zapisane. Powiązania Allegro są wyłącznie historyczne. Import, publikacja, synchronizacja i wiadomości do klientów pozostają wyłączone.</p>
<button id="start" disabled>Przygotuj nowy eksport</button><button id="resume" disabled>Wznów istniejący eksport</button><button id="pause">Zatrzymaj lokalną pętlę</button>
<p id="message" role="status">Szukam niedokończonego eksportu właściciela…</p><a id="login" href="/admin/login" hidden>Zaloguj się ponownie, potem odśwież ten panel</a><pre id="state"></pre>
<a id="sample" hidden>Pobierz próbkę ZIP</a><a id="full" hidden>Pobierz pełny ZIP</a>
<p>Dla dużego archiwum zalecane CLI: <code>php artisan gps:migration-export --owner-id=ID_OPERATORA</code>. Wznowienie: <code>--run=UUID</code>. Nie uruchamiaj drugiego eksportu, gdy trwa pierwszy.</p>
<script>
(()=>{
 const el=id=>document.getElementById(id),base=@json(route('admin.tools.gpswiss-migration-export', [], false));
 let id=new URLSearchParams(location.hash.slice(1)).get('run'),state=null,active=false,working=false,ready=false,sessionExpired=false,accessBlocked=false,inFlight=false;
 const wait=ms=>new Promise(resolve=>setTimeout(resolve,ms));
 const reasonOf=(data,fallback)=>typeof data?.reason_code==='string' && /^[A-Za-z0-9_]{1,80}$/.test(data.reason_code)?data.reason_code:fallback;
 function buttons(){
  el('start').disabled=!ready || working || sessionExpired || accessBlocked || !!(state && state.state!=='completed');
  el('resume').disabled=!ready || working || sessionExpired || accessBlocked || !state || (state.resume_allowed===false && !state.busy) || state.state==='completed';
  el('pause').disabled=!working;
 }
 function render(data){
  const value=data?.run ?? (data?.run_id && data?.state?data:null);
  if(value){
   const processed=value.processed ?? value.processed_images;
   if(state?.run_id===value.run_id && Number.isFinite(processed) && processed<(state.processed ?? state.processed_images ?? 0))return;
   state=value;id=value.run_id;history.replaceState(null,'','#run='+id);
   el('state').textContent=JSON.stringify({...value,local_runner_active:active,local_request_in_flight:inFlight},null,2);
  }else if(data?.existing_run_found===false){state=null;el('state').textContent=JSON.stringify(data,null,2);}
  for(const kind of ['sample','full']){el(kind).hidden=state?.state!=='completed';if(id)el(kind).href=base+'/'+encodeURIComponent(id)+'?download='+kind;}
  buttons();
 }
 async function request(url,action){
  inFlight=true;const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),45000);
  try{
   const response=await fetch(url,{method:action?'POST':'GET',credentials:'same-origin',cache:'no-store',redirect:'error',signal:controller.signal,headers:{Accept:'application/json',...(action?{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content}:{})},...(action?{body:JSON.stringify({action})}:{})});
   let data;try{data=await response.json();}catch(_){data=null;}
   if([401,419].includes(response.status))throw Object.assign(new Error('SESSION_EXPIRED'),{reason:'SESSION_EXPIRED',http:response.status});
   if(!response.ok)throw Object.assign(new Error(reasonOf(data,response.status===404?'route_not_found':'http_error')),{reason:reasonOf(data,response.status===404?'route_not_found':'http_error'),http:response.status});
   if(!data || typeof data!=='object')throw Object.assign(new Error('invalid_response'),{reason:'invalid_response',http:response.status});
   return data;
  }catch(error){if(error.reason)throw error;throw Object.assign(new Error('network_error'),{reason:'network_error'});}
  finally{clearTimeout(timer);inFlight=false;}
 }
 function stop(error){
  active=false;
  if(error.reason==='SESSION_EXPIRED'){
   sessionExpired=true;el('login').hidden=false;
   el('message').textContent='SESSION_EXPIRED / HTTP '+(error.http ?? 419)+' — checkpoint pozostaje na serwerze. Zaloguj się ponownie i odśwież panel; potem Wznów istniejący eksport.';
  }else if(error.reason==='run_owner_mismatch'){
   accessBlocked=true;el('message').textContent='BLOCK / run_owner_mismatch — ten eksport nie należy do bieżącego właściciela. Nie tworzę nowego runu.';
  }else if(error.reason==='run_not_found' && !state){
   el('message').textContent='run_not_found — brak zapisanego eksportu dla tego UUID i brak niedokończonego eksportu właściciela. UUID w adresie sam nie potwierdza utworzenia checkpointu; POST odrzucony przez 419 mógł nie utworzyć runu.';
  }else el('message').textContent='Eksport zatrzymany: '+error.reason+(error.http?' / HTTP '+error.http:'')+'. Checkpoint i pliki pozostają zachowane. Wznów po usunięciu blokady.';
  buttons();
 }
 async function discover(){const data=await request(base+'?format=status');render(data);return !!state;}
 async function readCurrent(){const data=await request(base+'/'+encodeURIComponent(id));render(data);return state;}
 async function recoverOnce(error,attempt){
  if(error.reason==='SESSION_EXPIRED' || error.reason==='run_owner_mismatch' || [403,422].includes(error.http))throw error;
  if(error.http===404 || error.reason==='existing_export_unfinished'){
   el('message').textContent=error.reason+' — szukam istniejącego eksportu właściciela';
   if(await discover())return;
   throw Object.assign(new Error('run_not_found'),{reason:'run_not_found',http:404});
  }
  if(error.reason!=='network_error' && error.http!==429 && !(error.http>=500))throw error;
  el('message').textContent='network_recovery '+attempt+'/4 — odczytuję checkpoint tego samego eksportu';
  await wait(500*2**(attempt-1));
  try{if(id)await readCurrent();else await discover();}
  catch(next){if(next.http===404 && next.reason!=='run_owner_mismatch'){if(await discover())return;}throw next;}
 }
 async function recover(error,attempt){
  let last=error;
  for(let n=attempt;n<=4;n++){
   try{await recoverOnce(last,n);return;}
   catch(next){if(next.reason==='network_error' || next.http>=500){last=next;if(n<4)continue;}throw next;}
  }
  throw last;
 }
 async function restore(){
  for(let attempt=1;attempt<=4;attempt++){
   try{
    if(id){try{await readCurrent();}catch(error){if(error.http!==404 || error.reason==='run_owner_mismatch')throw error;if(!await discover())throw Object.assign(new Error('run_not_found'),{reason:'run_not_found',http:404});}}
    else await discover();
    el('message').textContent=state?.state==='completed'?'Eksport ukończony — pobierz zapisane paczki.':state?'Masz niedokończony eksport: '+id+'. Kliknij Wznów istniejący eksport.':'Brak niedokończonego eksportu właściciela.';
    break;
   }catch(error){if(error.reason==='network_error' && attempt<4){await wait(500*2**(attempt-1));continue;}stop(error);break;}
  }
  ready=true;buttons();
 }
 const boot=restore();
 async function drive(start){
  await boot;
  if(working || sessionExpired || accessBlocked)return;
  working=true;active=true;buttons();let attempts=0,needsResume=!start;
  try{
   if(start){
    // Re-check durable discovery before allocating any UUID. Start is always a conscious action.
    await discover();if(state && state.state!=='completed'){el('message').textContent='Masz niedokończony eksport '+id+' — Wznów istniejący eksport.';return;}
    id=crypto.randomUUID();state=null;history.replaceState(null,'','#run='+id);
    try{render(await request(base+'/'+id,'start'));}catch(error){await recover(error,++attempts);}
   }else{
    try{if(id)await readCurrent();else if(!await discover())throw Object.assign(new Error('run_not_found'),{reason:'run_not_found',http:404});}
    catch(error){await recover(error,++attempts);}
   }
   while(active && state){
    if(state.state==='completed'){el('message').textContent='Obie paczki gotowe. Sprawdź manifest i missing-originals.json.';break;}
    if(state.busy){
     await wait(1000);try{await readCurrent();}catch(error){if(++attempts>=4)throw error;await recover(error,attempts);}continue;
    }
    if(state.resume_allowed===false){el('message').textContent='Wznowienie zablokowane: '+(state.blocked_reason ?? state.last_error ?? state.state);break;}
    if(needsResume){
     try{render(await request(base+'/'+encodeURIComponent(id),'resume'));needsResume=!!state.busy;attempts=0;}
     catch(error){if(++attempts>=4)throw error;await recover(error,attempts);}
     continue;
    }
    try{render(await request(base+'/'+encodeURIComponent(id),'tick'));attempts=0;if(state.last_error)throw Object.assign(new Error(state.last_error),{reason:state.last_error});}
    catch(error){if(++attempts>=4)throw error;await recover(error,attempts);}
    await wait(150);
   }
   if(!active && !sessionExpired)el('message').textContent='Zatrzymano lokalną pętlę. Checkpoint i pliki zachowane; Wznów istniejący eksport.';
  }catch(error){stop(error);}
  finally{working=false;active=false;buttons();if(state)render(state);}
 }
 el('start').onclick=()=>drive(true);el('resume').onclick=()=>drive(false);el('pause').onclick=()=>{active=false;};
})();
</script></body></html>
