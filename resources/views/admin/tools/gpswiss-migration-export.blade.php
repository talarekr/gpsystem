<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>GPSwiss Migration Runner</title><style>
:root{color-scheme:light;--ink:#172b3a;--muted:#526577;--line:#dce4e9;--accent:#126b61}*{box-sizing:border-box}body{max-width:1380px;margin:0 auto;padding:24px;background:#f3f6f8;color:var(--ink);font:15px/1.5 system-ui,sans-serif}h1{font-size:1.5rem;margin:0 0 12px}h2{font-size:1.1rem;margin:0 0 8px}h3{font-size:1rem;margin:12px 0 6px}p{margin:8px 0}button,a.download{display:inline-block;border:1px solid var(--line);border-radius:6px;background:#fff;color:#174b66;padding:8px 12px;cursor:pointer;text-decoration:none;font:inherit}button:hover:enabled{background:#e7f2f0}button:disabled{opacity:.48;cursor:default}button.primary{background:var(--accent);color:#fff}button.primary:hover:enabled{background:#0c544c}a{color:#165d92;overflow-wrap:anywhere}label{display:block;margin:8px 0}select,input[type=search]{max-width:100%;padding:8px;border:1px solid var(--line);border-radius:6px;font:inherit}.runner,.task-card,.events,.diagnostics{background:#fff;border:1px solid var(--line);border-radius:10px;padding:20px;margin-bottom:18px;box-shadow:0 2px 5px #11223305}.runner{border-top:4px solid var(--accent)}.runner-controls{position:sticky;top:8px;z-index:20;display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:#fff;border:1px solid var(--line);border-radius:8px;padding:10px 14px;margin-bottom:18px;box-shadow:0 3px 12px #11223312}.runner-controls label{margin:0}.runner-controls select{max-width:270px}.runner-heading,.row,.controls{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.runner-heading{justify-content:space-between}.runner-identity{color:var(--muted);font-size:.85rem;overflow-wrap:anywhere}.runner-meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:12px}.runner-meta span{display:block;color:var(--muted);font-size:.8rem}.task-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.task-card{align-self:start;margin:0;min-width:0;scroll-margin-top:130px}.task-card .controls{margin-top:14px}.badge{display:inline-block;border-radius:15px;background:#edf1f4;padding:3px 10px;font-size:.75rem;font-weight:700;letter-spacing:.03em}.badge[data-state=RUNNING]{background:#dceefa;color:#175f88}.badge[data-state=COMPLETED]{background:#dff3e7;color:#24613c}.badge[data-state=FAILED],.badge[data-state=BLOCKED]{background:#fde8e5;color:#9d3022}.badge[data-state=PAUSED]{background:#fff1cd;color:#795709}progress{width:100%;height:12px;accent-color:var(--accent);margin-top:10px}.progress-label,.task-summary{font-size:.9rem;color:var(--muted)}.task-error{color:#9d3022;min-height:1em}.task-options{border-top:1px solid var(--line);margin-top:12px;padding-top:10px}summary{cursor:pointer;color:#174b66;font-weight:600;padding:5px 0}.downloads{padding:0;list-style:none}.downloads li{margin:8px 0}.file-table{min-width:470px;width:100%;border-collapse:collapse;font-size:.85rem}.file-table th,.file-table td{text-align:left;padding:7px 4px;border-bottom:1px solid var(--line);overflow-wrap:anywhere}.file-table th,.file-table td:last-child a{white-space:nowrap}.table-scroll{overflow:auto}.pagination{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px}.muted{color:var(--muted)}.events{margin-top:18px}.events li{padding:5px 0;font-size:.85rem}.technical{font-size:.85rem}pre{white-space:pre-wrap;overflow-wrap:anywhere;background:#f1f4f6;padding:16px;max-height:480px;overflow:auto}.technical-controls{display:flex;gap:8px;flex-wrap:wrap}#technical-diagnostics:not([open]) .diagnostic-content{display:none}[hidden]{display:none!important}@media(max-width:760px){body{padding:12px}.task-grid,.runner-meta{grid-template-columns:1fr}.runner,.task-card{padding:16px}.controls button{flex:1}h1{font-size:1.2rem}}</style></head><body>
<section class="runner" aria-labelledby="runner-heading">
<div class="runner-heading"><h1 id="runner-heading">GPSwiss → WystawCzesc Migration Runner</h1><span id="runner-status" class="badge">IDLE</span></div>
<p class="runner-identity">Run: <span id="runner-run-id">—</span><br>Attempt: <span id="runner-attempt-id">—</span></p>
<div class="row"><span id="runner-active-tasks" class="muted"></span></div>
<p id="runner-current-task"></p><progress id="runner-progress" max="100" value="0"></progress><p id="runner-progress-label" class="progress-label">0 / —</p>

<div class="runner-meta"><div><span>Ostatnia poprawna operacja</span><strong id="runner-last-success">—</strong></div><div><span>Ostatni błąd</span><strong id="runner-last-error">—</strong></div><div><span>Następna akcja</span><strong id="runner-next">—</strong></div></div>
<p class="muted">Pliki są prywatne. Eksport nie zmienia produktów ani ofert. Odczyty Allegro wymagają osobnego planu i zatwierdzenia.</p>
</section><nav class="runner-controls" aria-label="Sterowanie runnerem"><label>Aktualne zadanie <select id="runner-task" aria-label="Aktualne zadanie"></select></label><div class="controls"><button id="runner-start" class="primary" disabled>START</button><button id="runner-stop" disabled>STOP</button><button id="runner-resume" disabled>RESUME</button><button id="runner-refresh">Odśwież status</button><button id="runner-open-task">Przejdź do zadania</button></div></nav><div class="task-grid">
<section class="task-card" id="task-snapshot" aria-labelledby="task-snapshot-heading"><div class="runner-heading"><h2 id="task-snapshot-heading">A. Snapshot danych</h2><span id="task-snapshot-status" class="badge">IDLE</span></div><p>Zapis części, samochodów, zamówień i powiązań.</p><p id="task-snapshot-summary" class="task-summary"></p><progress id="task-snapshot-progress" max="100" value="0"></progress><p id="task-snapshot-progress-label" class="progress-label">0 / —</p><p id="task-snapshot-error" class="task-error" role="status"></p><div class="controls"><button id="start" disabled>Start</button><button id="capture-resume" disabled>Resume</button><button id="capture-stop" disabled>Stop</button></div></section><section class="task-card" id="task-data" aria-labelledby="task-data-heading"><div class="runner-heading"><h2 id="task-data-heading">B. DATA ONLY</h2><span id="task-data-status" class="badge">IDLE</span></div><p>Pakiet zapisanych danych bez zdjęć. Zawiera dane klientów — przekazuj go prywatnie.</p><p id="task-data-summary" class="task-summary"></p><progress id="task-data-progress" max="100" value="0"></progress><p id="task-data-progress-label" class="progress-label">0 / —</p><p id="task-data-error" class="task-error" role="status"></p><div class="controls"><button id="data-only" disabled>Start</button><button id="data-only-resume" disabled>Resume</button><button id="data-only-stop" disabled>Stop</button></div><ul id="data-only-downloads" class="downloads" hidden></ul></section><section class="task-card" id="task-csv" aria-labelledby="task-csv-heading"><div class="runner-heading"><h2 id="task-csv-heading">C. Zbiorcze CSV</h2><span id="task-csv-status" class="badge">IDLE</span></div><p>Gotowe tabele części, samochodów, zdjęć i powiązań ofert. Importuj identyfikatory jako tekst.</p><p id="task-csv-summary" class="task-summary"></p><progress id="task-csv-progress" max="100" value="0"></progress><p id="task-csv-progress-label" class="progress-label">0 / —</p><p id="task-csv-error" class="task-error" role="status"></p><div class="controls"><button id="consolidated-csv" disabled>Start</button><button id="consolidated-csv-resume" disabled>Resume</button><button id="consolidated-csv-stop" disabled>Stop</button></div><ul id="consolidated-csv-downloads" class="downloads" hidden></ul></section><section class="task-card" id="task-schema" aria-labelledby="task-schema-heading"><div class="runner-heading"><h2 id="task-schema-heading">D. Definicje parametrów Allegro — krok A</h2><span id="task-schema-status" class="badge">IDLE</span></div><p>Aktualne schematy kategorii używanych w eksporcie. Tylko odczyt Allegro.</p><p id="task-schema-summary" class="task-summary"></p><progress id="task-schema-progress" max="100" value="0"></progress><p id="task-schema-progress-label" class="progress-label">0 / —</p><p id="task-schema-error" class="task-error" role="status"></p><div class="controls"><button id="allegro-schema-start" disabled>Start</button><button id="allegro-schema-resume" disabled>Resume</button><button id="allegro-schema-stop" disabled>Stop</button></div><div class="task-options"><button id="allegro-schema-plan" disabled>Przygotuj plan</button><button id="allegro-schema-status-refresh" disabled>Odśwież status</button><p id="schema-plan-summary" class="task-summary"></p><label><input id="allegro-schema-confirm" type="checkbox"> Zatwierdzam pokazany plan GET definicji.</label><details id="schema-category-details"><summary>Pokaż szczegóły kategorii</summary><div class="row"><input id="schema-category-search" type="search" placeholder="Szukaj category_id" aria-label="Szukaj category_id"><select id="schema-category-filter" aria-label="Filtr kategorii"><option value="all">Wszystkie</option><option value="success">Sukces</option><option value="error">Błąd</option><option value="missing">Brak schematu</option><option value="429">429</option><option value="old">Stary cache</option><option value="new">Nowy cache</option></select></div><div id="schema-category-table" class="table-scroll"></div></details></div></section><section class="task-card" id="task-readback" aria-labelledby="task-readback-heading"><div class="runner-heading"><h2 id="task-readback-heading">E. Parametry istniejących ofert Allegro — krok B</h2><span id="task-readback-status" class="badge">IDLE</span></div><p>Pobranie zapisanych wartości parametrów istniejących ofert. Tylko odczyt Allegro.</p><p id="task-readback-summary" class="task-summary"></p><progress id="task-readback-progress" max="100" value="0"></progress><p id="task-readback-progress-label" class="progress-label">0 / —</p><p id="task-readback-error" class="task-error" role="status"></p><div class="controls"><button id="allegro-readback-start" disabled>Start</button><button id="allegro-readback-resume" disabled>Resume</button><button id="allegro-readback-stop" disabled>Stop</button></div><div class="task-options"><button id="allegro-readback-plan" disabled>Przygotuj plan</button><button id="allegro-readback-status-refresh" disabled>Odśwież status</button><p id="readback-plan-summary" class="task-summary"></p><label><input id="allegro-readback-confirm" type="checkbox"> Zatwierdzam pokazany plan GET istniejących ofert.</label></div></section><section class="task-card" id="task-supplement" aria-labelledby="task-supplement-heading"><div class="runner-heading"><h2 id="task-supplement-heading">F. Supplement parametrów Allegro — krok C</h2><span id="task-supplement-status" class="badge">IDLE</span></div><p>Łączy zakończone odczyty z danymi części i samochodów. Konflikty zostają w raporcie.</p><p id="task-supplement-summary" class="task-summary"></p><progress id="task-supplement-progress" max="100" value="0"></progress><p id="task-supplement-progress-label" class="progress-label">0 / —</p><p id="task-supplement-error" class="task-error" role="status"></p><div class="controls"><button id="allegro-supplement-start" disabled>Start</button><button id="allegro-supplement-resume" disabled>Resume</button><button id="allegro-supplement-stop" disabled>Stop</button></div><div class="task-options"><button id="allegro-supplement-plan" disabled>Przygotuj plan pakietu</button><button id="allegro-supplement-status-refresh" disabled>Odśwież status</button><p id="supplement-plan-summary" class="task-summary"></p><label><input id="allegro-supplement-confirm" type="checkbox"> Zatwierdzam pakiet z zakończonych kroków A i B.</label></div><ul id="allegro-supplement-downloads" class="downloads" hidden></ul><details><summary>Podsumowanie parametrów i plików</summary><ul id="allegro-supplement-counts"></ul><ul id="allegro-supplement-contents"></ul></details></section><section class="task-card" id="task-photos" aria-labelledby="task-photos-heading"><div class="runner-heading"><h2 id="task-photos-heading">G. Zdjęcia</h2><span id="task-photos-status" class="badge">IDLE</span></div><p>Kopia oryginalnych plików do prywatnej paczki. Oryginały pozostają w GPSwiss.</p><p id="task-photos-summary" class="task-summary"></p><progress id="task-photos-progress" max="100" value="0"></progress><p id="task-photos-progress-label" class="progress-label">0 / —</p><p id="task-photos-error" class="task-error" role="status"></p><div class="controls"><button id="photos-start" disabled>Start</button><button id="photos-resume" disabled>Resume</button><button id="photos-stop" disabled>Stop</button></div></section><section class="task-card" id="task-finalize" aria-labelledby="task-finalize-heading"><div class="runner-heading"><h2 id="task-finalize-heading">H. Finalizacja</h2><span id="task-finalize-status" class="badge">IDLE</span></div><p>Kontrola zapisanych danych i przygotowanie archiwów.</p><p id="task-finalize-summary" class="task-summary"></p><progress id="task-finalize-progress" max="100" value="0"></progress><p id="task-finalize-progress-label" class="progress-label">0 / —</p><p id="task-finalize-error" class="task-error" role="status"></p><div class="controls"><button id="finalize-start" disabled>Start</button><button id="finalize-resume" disabled>Resume</button><button id="finalize-stop" disabled>Stop</button></div><a id="sample" hidden>Pobierz próbkę ZIP</a><a id="full" hidden>Pobierz pełny ZIP</a><ul id="download-artifacts" class="downloads" hidden></ul></section></div><section class="events"><h2>Ostatnie zdarzenia</h2><ol id="runner-events"></ol><p class="muted">Ostatnie 20 zmian statusu. Trwałe wyniki operacji pozostają w checkpointach, raportach i manifestach.</p></section>
<details class="diagnostics" id="technical-diagnostics"><summary>Diagnostyka techniczna</summary><div class="diagnostic-content technical"><p>Podgląd jest ograniczony do 20 000 znaków na blok. Pełne dostępne dane pobierzesz jako prywatny plik JSON.</p><button id="diagnostics-download">Pobierz diagnostykę JSON</button><a id="full-debug" hidden target="_blank" rel="noreferrer">Pełny status serwera</a><p id="allegro-supplement-identity"></p><p id="snapshot-worker" hidden></p><p id="progress"></p><p id="message" role="status">Szukam istniejącego eksportu…</p><a id="login" href="/admin/login" hidden>Zaloguj się ponownie</a>
<p id="data-only-status"></p><p id="consolidated-csv-status"></p><p id="allegro-schema-status"></p><p id="allegro-readback-status"></p><p id="allegro-supplement-status"></p>
<details><summary>Status i checkpoint</summary><pre id="state"></pre></details><details><summary>Plan definicji</summary><pre id="allegro-schema-plan-preview"></pre></details><details><summary>Plan odczytu ofert</summary><pre id="allegro-readback-plan-preview"></pre></details><details><summary>Plan supplementu</summary><pre id="allegro-supplement-plan-preview"></pre></details><details><summary>Żądania i błędy</summary><pre id="request-diagnostic"></pre><pre id="resume-diagnostic"></pre></details><details><summary>Powiązania i konflikt źródła</summary><pre id="consolidated-csv-listing-audit"></pre><pre id="dataset-conflict"></pre></details>
<div class="technical-controls"><button id="resume" disabled>Wznów główny pipeline</button><button id="stop-local" disabled>Stop głównej pętli</button><button id="diagnose-conflict" hidden>Diagnozuj konflikt datasetu</button><button id="pause" disabled>Pauza snapshotu na serwerze</button></div><section id="parts-drift-actions" hidden>
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
<details><summary>Format i ograniczenia eksportu</summary><p>Eksport tworzy prywatne pliki. Nie zmienia części, zdjęć, cen, stanów, ofert ani synchronizacji. Nie wykonuje importu ani API Allegro/eBay/Ovoko.</p>
<p>Panel wykonuje krótkie partie i zapisuje postęp na serwerze. Zamrożone granice odczytu oraz sumy kontrolne tworzą wersjonowany snapshot logiczny; nie jest to jedna transakcja bazy utrzymywana między żądaniami HTTP. Granice i ograniczenia spójności znajdziesz w manifeście.</p>
<p>Próbka obejmuje do 5 rzeczywistych części, a pełny eksport używa tego samego formatu. Zdjęcia zachowują oryginalne nazwy w katalogach ID części; kolizje w obrębie części otrzymują podkatalog ID zdjęcia. Brakujące/zmienione pliki są raportowane.</p>
<p>Paczka zawiera kody, kategorię, jakość, pozycję, magazyn, opis, lokalne/Ovoko ID auta, ceny sklep/Allegro/Ovoko/eBay, Kurier Allegro, lokalne parametry i linki ofert. Zawiera wszystkie samochody, zamówienia i potrzebne dane klientów — pobieranie wyłącznie prywatnie przez zalogowanego właściciela eksportu. Bez haseł, tokenów i pełnych danych kart. Nie udostępniaj archiwum ani katalogu publicznie.</p>
<p>Części i listingi mogą zmieniać się podczas sprzedaży. Recovery części zachowuje kompletny eksport i uzgadnia tylko zmienione partie. Dotychczasowe pliki listingów i attempt pozostają zachowane. Licznik weryfikacji jest oddzielony od liczby wyeksportowanych rekordów. Rekoncyliacja ma limit trzech rund i jawny raport niestabilności.</p>
<p>Snapshot bazy nie jest globalnym snapshotem filesystemu. Kopie plików są weryfikowane podczas kopiowania; finalny cutover wymaga osobnej procedury freeze/snapshotu. Nie zatrzymujemy synchronizacji automatycznie.</p>
<p>Próbka wybiera sprzedaną część (status sold), część do wystawienia (needs_listing), oferty eBay/Ovoko i galerię — gdy takie przypadki są zapisane. Powiązania Allegro są wyłącznie historyczne. Import, publikacja, synchronizacja i wiadomości do klientów pozostają wyłączone.</p>
</details></div></details>
<script>
(()=>{
 const el=id=>document.getElementById(id),base=@json(route('admin.tools.gpswiss-migration-export', [], false));
 let id=new URLSearchParams(location.hash.slice(1)).get('run'),state=null,active=false,working=false,ready=false,sessionExpired=false,accessBlocked=false,inFlight=false,pauseRequested=false,pausing=false;
 let loopDone=null,finishLoop=null,manualResuming=false,resumeTraceActive=false,retryExhausted=false,stopBeforePhotos=false,kickDiagnostic=null;
 let dataOnlyWorking=false,dataOnlyLoaded=null;
 let csvWorking=false,csvStop=false,csvLoaded=null;
 let readbackValue=null,readbackWorking=false,readbackStopped=false,readbackLoaded=null,readbackWaiting=null,supplementPlan=null;
 let schemaValue=null,schemaWorking=false,schemaStopped=false,schemaLoaded=null,schemaWaiting=null;
 const supplementFormat='gpswiss-allegro-parameters-supplement-v1';
 const supplementDatasets=['allegro_category_parameters_cache','allegro_parameter_mappings','allegro_resolved_parameters','conflicts','completeness','allegro_product_parameters_cache','allegro_parameter_selections','schema_freshness','allegro_existing_offer_parameters','allegro_existing_offer_readbacks','allegro_offer_readback_freshness','allegro_parameter_completeness','allegro_parameter_conflicts','allegro_schema_freshness','allegro_parametry_czesci'];
 let supplementWorking=false,supplementStopped=false,supplementLoading=false,supplementLoaded=null,supplementValue=null,supplementManifest=null,supplementPackageKey=null,supplementWait=null,supplementViewKey=null,supplementEpoch=0;
 const supplementCurrent=(run,attempt)=>id===run && state?.snapshot_attempt_id===attempt;
 const taskSnapshots={},diagnostics={},fileViews=new Map(),eventHistory=[];
 let selectedTask=null,lastRunnerSignature='',categoryPage=0,dataOnlyStopped=false;
 const taskDefinitions=[['snapshot','Snapshot danych',['start','capture-stop','capture-resume']],['data','DATA ONLY',['data-only','data-only-stop','data-only-resume']],['csv','Zbiorcze CSV',['consolidated-csv','consolidated-csv-stop','consolidated-csv-resume']],['schema','Definicje parametrów Allegro',['allegro-schema-start','allegro-schema-stop','allegro-schema-resume']],['readback','Parametry istniejących ofert Allegro',['allegro-readback-start','allegro-readback-stop','allegro-readback-resume']],['supplement','Supplement Allegro',['allegro-supplement-start','allegro-supplement-stop','allegro-supplement-resume']],['photos','Zdjęcia',['photos-start','photos-stop','photos-resume']],['finalize','Finalizacja',['finalize-start','finalize-stop','finalize-resume']]];
 function diagnosticSet(key,value){diagnostics[key]=value;renderDiagnostic(key);}
 function renderDiagnostic(key){const node=el(key);if(!node)return;const container=node.closest('details');if(!el('technical-diagnostics').open || (container!==el('technical-diagnostics') && !container.open)){node.textContent='';return;}const text=typeof diagnostics[key]==='string'?diagnostics[key]:JSON.stringify(diagnostics[key],null,2);node.textContent=(text??'').slice(0,20000)+((text?.length??0)>20000?'\n… Pełne dane: Pobierz diagnostykę JSON.':'');}
 function diagnosticDownload(){const body=JSON.stringify({exported_at:new Date().toISOString(),run_id:id,parent_attempt_id:state?.snapshot_attempt_id,diagnostics,recent_events:eventHistory},null,2),url=URL.createObjectURL(new Blob([body],{type:'application/json'})),a=document.createElement('a');a.href=url;a.download='gpswiss-migration-diagnostics-'+(id??'local')+'.json';a.click();setTimeout(()=>URL.revokeObjectURL(url),30000);}
 function fileRow(file){const tr=document.createElement('tr');for(const text of [file.name,file.rows==null?'—':String(file.rows),file.bytes==null?'—':Number(file.bytes).toLocaleString('pl-PL')+' B']){const td=document.createElement('td');td.textContent=text;tr.append(td);}const td=document.createElement('td'),a=document.createElement('a');a.href=file.href;a.textContent='Pobierz';td.append(a);if(file.sha256){const d=document.createElement('details'),sm=document.createElement('summary'),code=document.createElement('code');sm.textContent='Szczegóły';code.textContent='SHA-256: '+file.sha256;d.append(sm,code);td.append(d);}tr.append(td);return tr;}
 function fileTable(files){const wrap=document.createElement('div'),table=document.createElement('table'),head=document.createElement('thead'),row=document.createElement('tr'),body=document.createElement('tbody');wrap.className='table-scroll';table.className='file-table';for(const text of ['Plik','Rekordy','Rozmiar','']){const th=document.createElement('th');th.textContent=text;row.append(th);}head.append(row);for(const f of files)body.append(fileRow(f));table.append(head,body);wrap.append(table);return wrap;}
 function pageControls(parent,page,count,change){const bar=document.createElement('div'),prev=document.createElement('button'),next=document.createElement('button'),label=document.createElement('span');bar.className='pagination';prev.textContent='Poprzednia';next.textContent='Następna';prev.disabled=page===0;next.disabled=(page+1)*25>=count;label.textContent='Strona '+(page+1)+' / '+Math.max(1,Math.ceil(count/25))+' · '+count+' pozycji';prev.onclick=()=>change(page-1);next.onclick=()=>change(page+1);bar.append(prev,label,next);parent.append(bar);}
 function filesPresent(key,files,{volumes=false}={}){
  const node=el(key),signature=JSON.stringify(files);
  const old=fileViews.get(key),view={signature,files,volumes,page:old?.page??0,open:old?.open??false};fileViews.set(key,view);node.replaceChildren();node.hidden=files.length===0;if(!files.length)return;
  const fixed=volumes?files.filter(f=>!f.volume):files.slice(0,25),paged=volumes?files.filter(f=>f.volume):files.length>25?files:[];
  if(fixed.length)node.append(fileTable(fixed));
  if(paged.length){const details=document.createElement('details'),summary=document.createElement('summary'),content=document.createElement('div');summary.textContent=(volumes?'Pokaż wolumeny':'Pokaż wszystkie pliki')+' ('+paged.length+')';details.append(summary,content);details.open=view.open;const draw=()=>{content.replaceChildren();if(!details.open)return;view.page=Math.min(view.page,Math.max(0,Math.ceil(paged.length/25)-1));content.append(fileTable(paged.slice(view.page*25,(view.page+1)*25)));pageControls(content,view.page,paged.length,page=>{view.page=page;draw();});};details.ontoggle=()=>{view.open=details.open;draw();};node.append(details);draw();}
  const summary=document.createElement('p');summary.className='task-summary';summary.textContent=(volumes?'Wolumeny: '+paged.length+' · ':'Pliki: '+files.length+' · ')+'Łączny rozmiar: '+files.reduce((sum,f)=>sum+(Number(f.bytes)||0),0).toLocaleString('pl-PL')+' B';node.prepend(summary);
 }
 function categoryTable(){const node=el('schema-category-table');node.replaceChildren();if(!el('schema-category-details').open)return;
  const term=el('schema-category-search').value.trim(),filter=el('schema-category-filter').value;
  const rows=(schemaValue?.categories??[]).filter(c=>{const results=Object.values(c.endpoints??{}),success=results.length===2&&results.every(r=>r.ok),error=results.some(r=>r.ok===false),fresh=results.some(r=>r.ok);return String(c.category_id).includes(term)&&(filter==='all'||filter==='success'&&success||filter==='error'&&error||filter==='missing'&&!success||filter==='429'&&results.some(r=>r.count_429>0)||filter==='old'&&c.local_cache_present||filter==='new'&&fresh);});
  categoryPage=Math.min(categoryPage,Math.max(0,Math.ceil(rows.length/25)-1));const table=document.createElement('table');table.className='file-table';const head=document.createElement('tr');for(const text of ['Kategoria','Wynik','Lokalny cache','Nowy odczyt']){const th=document.createElement('th');th.textContent=text;head.append(th);}table.append(head);
  for(const c of rows.slice(categoryPage*25,(categoryPage+1)*25)){const values=Object.values(c.endpoints??{}),row=document.createElement('tr');for(const text of [c.category_id,values.length===2&&values.every(r=>r.ok)?'Sukces':values.some(r=>r.ok===false)?'Błąd':'Oczekuje',c.local_fetched_at??(c.local_cache_present?'Obecny':'Brak'),values.map(r=>r.fetched_at).filter(Boolean).sort().at(-1)??'—']){const td=document.createElement('td');td.textContent=text;row.append(td);}table.append(row);}node.append(table);pageControls(node,categoryPage,rows.length,page=>{categoryPage=page;categoryTable();});
 }
 function runnerData(){
  const parentPhase=state?.phase??'',parentBusy=working||state?.busy===true,photoPhase=/photo/.test(parentPhase),finalPhase=/final|relation|archive|package/.test(parentPhase),captured=dataOnlyAvailable(),photo=state?.photo_progress??{},snap=state?.snapshot_progress??{};
  const parentTask=photoPhase?'photos':finalPhase?'finalize':'snapshot';
  const values={snapshot:{processed:state?.exported_records??snap.processed??state?.processed??0,total:state?.record_capture_total??snap.total??state?.total,done:captured,phase:parentPhase,last_success:state?.last_success},data:taskSnapshots.data,csv:taskSnapshots.csv,schema:schemaValue,readback:readbackValue,supplement:supplementValue,photos:{processed:photo.processed??state?.processed_images??0,total:state?.total_images??state?.source_counts?.photo_rows,done:photo.done===true||state?.state==='completed',phase:photoPhase?parentPhase:null,last_success:photo.last_success},finalize:{processed:state?.relation_audit?.processed??state?.archive_progress?.artifact_count??0,total:state?.relation_audit?.total,done:state?.state==='completed',phase:finalPhase?parentPhase:null,last_success:state?.last_success}};
  const running={snapshot:parentBusy&&parentTask==='snapshot',photos:parentBusy&&parentTask==='photos',finalize:parentBusy&&parentTask==='finalize',data:dataOnlyWorking,csv:csvWorking,schema:schemaWorking,readback:readbackWorking||readbackValue?.busy===true,supplement:supplementWorking};
  const technical={snapshot:el('message').textContent,photos:el('message').textContent,finalize:el('message').textContent,data:el('data-only-status').textContent,csv:el('consolidated-csv-status').textContent,schema:el('allegro-schema-status').textContent,readback:el('allegro-readback-status').textContent,supplement:el('allegro-supplement-status').textContent};
  return taskDefinitions.map(([key,label,controls])=>{const value=values[key],local=running[key],parent=['snapshot','photos','finalize'].includes(key),stopped=key==='data'?dataOnlyStopped:key==='csv'?csvStop:key==='schema'?schemaStopped:key==='readback'?readbackStopped:key==='supplement'?supplementStopped:!active;
   const blocker=value?.blocked_reason||(parent&&parentTask===key?state?.blocked_reason:null),failed=parent&&parentTask===key&&(/failed/.test(state?.state??'')||state?.last_error),localError=/network|timeout|http_[45]|server_exception|retry_exhausted|zatrzyman.*błąd/i.test(technical[key]??'');
   let status=value?.done?'COMPLETED':blocker?'BLOCKED':failed?'FAILED':local?'RUNNING':localError?'FAILED':value&&(value.phase||value.processed>0)?'PAUSED':'IDLE';
   if(parent&&!state)status='IDLE';
   let done=key==='data'?value?.records:key==='csv'?value?.processed:key==='supplement'?null:value?.processed,total=key==='data'?value?.total_records:key==='supplement'?null:value?.total;
   if(value?.done&&done==null&&key==='supplement'){done=1;total=1;}if(value?.done&&total==null)total=done;
   const note=key==='schema'?('Kategorie: '+(value?.total??0)+' · GET: '+(value?.performed_gets??0)+' / '+(value?.expected_gets??0)+' · Sukces: '+(value?.successes??0)+' · 404: '+(value?.not_found_404??0)+' · 429: '+(value?.provider_429??0)+' · Błędy: '+(value?.errors??0)+' · Brak schematu: '+(value?.missing_schemas??0)+' · Ostatni refresh: '+(value?.completed_at??'—')):key==='readback'?('Oferty: '+(value?.total??0)+' · GET: '+(value?.performed_gets??0)+' / '+(value?.expected_gets??0)+' · Sukces: '+(value?.successes??0)+' · 404: '+(value?.not_found_404??0)+' · 429: '+(value?.provider_429??0)):key==='data'?('Wolumeny: '+(value?.volume_count??0)+' · Pliki: '+(value?.files_checked??0)):key==='supplement'?('Parametry: '+(value?.summary?.resolved_parameter_rows??0)+' · Konflikty: '+(value?.summary?.conflicts??0)):key==='snapshot'&&captured?'Zapis danych zakończony.':key==='finalize'&&state?.relation_audit&&!state.relation_audit.done?'Sprawdzanie powiązań zapisanych danych.':status==='PAUSED'?'Zapisany postęp. Użyj Resume, aby kontynuować.':status==='IDLE'?'Jeszcze nie uruchomiono.':status==='RUNNING'?'Przetwarzanie danych…':status==='COMPLETED'?'Gotowe.':'';
   return {key,label,controls,status,done:done??0,total:total??null,note,error:status==='BLOCKED'?'Wymagane działanie operatora. Szczegóły w diagnostyce.':status==='FAILED'?'Operacja zatrzymana po błędzie. Zapisany postęp pozostaje zachowany.':'',last:value?.completed_at??value?.last_success??value?.last_tick?.completed_at??null,next:status==='COMPLETED'?'Pobierz gotowe pliki':status==='BLOCKED'?'Sprawdź diagnostykę':status==='RUNNING'?'Automatyczna kontynuacja':status==='PAUSED'?'Resume':key==='schema'||key==='readback'||key==='supplement'?'Przygotuj plan i zatwierdź':'Start'};
  });
 }
 function runnerRefresh(){
  const tasks=runnerData(),parentPhase=state?.phase??'',parentIsPhoto=/photo/.test(parentPhase),parentIsFinal=/final|relation|archive|package/.test(parentPhase);
  el('capture-resume').disabled=dataOnlyAvailable() || parentIsPhoto || parentIsFinal || el('resume').disabled;el('capture-stop').disabled=dataOnlyAvailable() || parentIsPhoto || parentIsFinal || el('stop-local').disabled;
  for(const key of ['photos','finalize'])for(const action of ['start','resume','stop']){const allowed=key==='photos'?parentIsPhoto:parentIsFinal;el(key+'-'+action).disabled=!allowed||action==='start'||(action==='stop'?el('stop-local').disabled:el('resume').disabled);}
  el('data-only-resume').disabled=el('data-only').disabled||!taskSnapshots.data||taskSnapshots.data.done;el('data-only-stop').disabled=!dataOnlyWorking||dataOnlyStopped;
  el('consolidated-csv-resume').disabled=el('consolidated-csv').disabled||!taskSnapshots.csv||taskSnapshots.csv.done;
  const running=tasks.filter(t=>t.status==='RUNNING');if(!selectedTask)selectedTask=running[0]?.key??(parentIsPhoto?'photos':parentIsFinal?'finalize':'snapshot');
  const selected=tasks.find(t=>t.key===selectedTask)??tasks[0];el('runner-task').value=selected.key;
  const signature=JSON.stringify([id,state?.snapshot_attempt_id,tasks,selected.key,taskDefinitions.map(t=>t[2].map(button=>el(button).disabled))]);if(signature===lastRunnerSignature)return;lastRunnerSignature=signature;
  el('runner-run-id').textContent=id??'—';el('runner-attempt-id').textContent=state?.snapshot_attempt_id??'—';el('runner-active-tasks').textContent=running.length?'Aktywne zadania: '+running.length:'Brak aktywnej lokalnej pętli';
  for(const t of tasks){const badge=el('task-'+t.key+'-status');badge.textContent=t.status;badge.dataset.state=t.status;el('task-'+t.key+'-summary').textContent=t.note;el('task-'+t.key+'-error').textContent=t.error;progressPresent('task-'+t.key+'-progress','task-'+t.key+'-progress-label',t);const logKey=t.status+':'+t.done+':'+t.total;if(taskSnapshots['_last_'+t.key]!==logKey){taskSnapshots['_last_'+t.key]=logKey;if(t.status!=='IDLE')eventHistory.push({timestamp:new Date().toISOString(),task:t.label,status:t.status,message:t.error||t.note});}}
  if(eventHistory.length>20)eventHistory.splice(0,eventHistory.length-20);const events=el('runner-events');events.replaceChildren();for(const e of [...eventHistory].reverse()){const li=document.createElement('li');li.textContent=new Date(e.timestamp).toLocaleTimeString('pl-PL')+' · '+e.task+' · '+e.status+' · '+e.message.slice(0,160);events.append(li);}
  el('runner-status').textContent=selected.status;el('runner-status').dataset.state=selected.status;el('runner-current-task').textContent=selected.label+' — '+selected.note;el('runner-last-success').textContent=selected.last??'—';el('runner-last-error').textContent=selected.error||'—';el('runner-next').textContent=selected.next;progressPresent('runner-progress','runner-progress-label',selected);
  ['start','stop','resume'].forEach((action,index)=>el('runner-'+action).disabled=el(selected.controls[index]).disabled);
 }
 function progressPresent(barId,labelId,task){const bar=el(barId),known=Number.isFinite(task.total)&&task.total>=0,percent=known?(task.total===0?(task.status==='COMPLETED'?100:0):Math.min(100,Math.max(0,task.done/task.total*100))):null;bar.setAttribute('aria-label',task.label+' — postęp');if(percent===null&&task.status==='RUNNING')bar.removeAttribute('value');else bar.value=percent??(task.status==='COMPLETED'?100:0);el(labelId).textContent=task.done.toLocaleString('pl-PL')+' / '+(known?task.total.toLocaleString('pl-PL'):'—')+(percent===null?'':' · '+percent.toFixed(1)+'%');}
 function runnerInit(){
  for(const [key,label] of taskDefinitions){const option=document.createElement('option');option.value=key;option.textContent=label;el('runner-task').append(option);}
  el('runner-open-task').onclick=()=>el('task-'+selectedTask)?.scrollIntoView({block:'start'});
  el('runner-task').onchange=()=>{selectedTask=el('runner-task').value;runnerRefresh();};
  ['start','stop','resume'].forEach((action,index)=>el('runner-'+action).onclick=()=>{const task=taskDefinitions.find(t=>t[0]===selectedTask);if(task&&!el(task[2][index]).disabled){el(task[2][index]).click();runnerRefresh();}});
  el('runner-refresh').onclick=()=>{loadSchemaStatus(true);loadReadbackStatus(true);supplementReadStatus(true);dataOnlyLoaded=null;csvLoaded=null;loadDataOnlyStatus();if(id&&!working)request(base+'/'+encodeURIComponent(id)+'?format=compact').then(render).catch(()=>{});};
  for(const key of ['photos','finalize']){el(key+'-start').onclick=()=>el('resume').click();el(key+'-resume').onclick=()=>el('resume').click();el(key+'-stop').onclick=()=>el('stop-local').click();}
  el('capture-resume').onclick=()=>el('resume').click();el('capture-stop').onclick=()=>el('stop-local').click();
  el('data-only-resume').onclick=prepareDataOnly;el('data-only-stop').onclick=()=>{dataOnlyStopped=true;runnerRefresh();};el('consolidated-csv-resume').onclick=prepareCsv;
  el('diagnostics-download').onclick=diagnosticDownload;el('technical-diagnostics').addEventListener('toggle',()=>{for(const key of Object.keys(diagnostics))renderDiagnostic(key);});for(const d of el('technical-diagnostics').querySelectorAll('details'))d.addEventListener('toggle',()=>{for(const key of Object.keys(diagnostics))renderDiagnostic(key);});
  el('schema-category-details').ontoggle=categoryTable;el('schema-category-search').oninput=()=>{categoryPage=0;categoryTable();};el('schema-category-filter').onchange=()=>{categoryPage=0;categoryTable();};
  setInterval(runnerRefresh,500);runnerRefresh();
 }

 const waits=new Map(),resumeEvents=[];
 const wait=ms=>new Promise(resolve=>{const timer=setTimeout(()=>{waits.delete(timer);resolve();},ms);waits.set(timer,resolve);});
 function cancelBackoff(){for(const [timer,resolve] of waits){clearTimeout(timer);resolve();}waits.clear();}
 function runnerContext(){return {phase:state?.phase??null,state:state?.state??null,next_action:nextAction(),busy:state?.busy??false,resume_allowed:state?.resume_allowed??null,blocked_reason:state?.blocked_reason??state?.last_error??null,runner_decision:runnerDecision()};}
 function resumeEvent(event,blocked_reason=null){const context=runnerContext();resumeEvents.push({...context,event,blocked_reason:blocked_reason??context.blocked_reason});if(resumeEvents.length>30)resumeEvents.splice(3,1);diagnosticSet('resume-diagnostic',{events:resumeEvents,retry_exhausted:retryExhausted,...context,kick_result:kickDiagnostic});}
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
  readbackButtons();
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
  loadReadbackStatus();
  loadSupplementStatus();
  loadDataOnlyStatus();
  diagnosticSet('state',state?{...state,local_runner_active:active,local_request_in_flight:inFlight}:data);
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
  filesPresent('download-artifacts',state?.state==='completed'?artifacts.filter(a=>typeof a.key==='string'&&/^[A-Za-z0-9][A-Za-z0-9_.-]{0,179}$/.test(a.key)).map(a=>({...a,name:a.name??a.key,volume:/volume|part-/.test(a.name??a.key),href:base+'/'+encodeURIComponent(id)+'?download=artifact&artifact='+encodeURIComponent(a.key)})):[],{volumes:true});
  const phase=snapshotState();el('snapshot-worker').hidden=!phase || phase==='snapshot_completed';
  el('snapshot-worker').textContent=browserSnapshot()?'Tryb panelowy: każda partia zapisuje checkpoint. Wznów kontynuuje tę samą próbę; Ponów snapshot świadomie ustala nowe granice.':phase?'To próba utworzona w starszym trybie. Jeśli nie działa, użyj „Ponów snapshot w trybie panelowym”, aby zachować run_id.':'';
  buttons();
 }
 let artifactsLoadedFor=null;
 const requestFailures=[],statusAttempts=[];
 let latestRequestDiagnostic=null,lastOperationFailure=null;
 function showRequestDiagnostic(){diagnosticSet('request-diagnostic',{latest:latestRequestDiagnostic,last_operation_failure:lastOperationFailure,status_attempts:statusAttempts,recent_failures:requestFailures});}
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
  el('consolidated-csv').disabled=taskSnapshots.csv?.done===true || csvWorking || sessionExpired || accessBlocked || !id || !dataOnlyAvailable();
  el('consolidated-csv-stop').disabled=!csvWorking;el('data-only').disabled=taskSnapshots.data?.done===true || dataOnlyWorking || sessionExpired || accessBlocked || !id || !dataOnlyAvailable();}
 function showDataOnly(value,run,attempt){
  if(id!==run || state?.snapshot_attempt_id!==attempt)return;
  taskSnapshots.data=value;
  el('data-only-status').textContent=value.done?'Pakiet danych bez zdjęć gotowy. Pobierz oba manifesty i wszystkie wolumeny ZIP.':'DATA ONLY: '+value.phase+' | rekordy '+value.records+'/'+value.total_records+' | pliki '+value.files_checked+' | wolumeny '+value.volume_count;
  filesPresent('data-only-downloads',value.done?(value.downloads??[]).filter(f=>/^(data-only-package-manifest|data-only-archive-manifest|volume-[0-9]{6})$/.test(f.key??'')).map(f=>({...f,volume:f.key.startsWith('volume-'),href:base+'/'+encodeURIComponent(run)+'/data-only?parent_attempt_id='+encodeURIComponent(attempt)+'&download='+encodeURIComponent(f.key)})):[],{volumes:true});
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
  selectedTask='data';dataOnlyWorking=true;dataOnlyStopped=false;dataOnlyButtons();el('data-only-downloads').hidden=true;
  let action='start',failures=0;
  try{
   while(!dataOnlyStopped && id===run && state?.snapshot_attempt_id===attempt){
    try{
     const value=await dataOnlyRequest(run,attempt,action);showDataOnly(value,run,attempt);failures=0;
     if(value.done || dataOnlyStopped)return;action='tick';
     await new Promise(resolve=>setTimeout(resolve,150));
    }catch(error){
     if((error.http && error.http<500 && error.message!=='data_only_busy') || ++failures>4)throw error;
     el('data-only-status').textContent='DATA ONLY retry '+failures+'/4 — checkpoint zachowany';
     await new Promise(resolve=>setTimeout(resolve,Math.min(4000,500*2**failures)));
     try{const value=await dataOnlyRequest(run,attempt);showDataOnly(value,run,attempt);if(value.done || dataOnlyStopped)return;action='tick';}catch(_){/* Repeat only this package operation. */}
    }
   }
   if(!dataOnlyStopped)throw new Error('data_only_parent_changed');
  }catch(error){el('data-only-status').textContent='DATA ONLY zatrzymany: '+(error.message??'network_error')+'. Kliknij ponownie, aby kontynuować własny checkpoint.';}
  finally{dataOnlyWorking=false;dataOnlyButtons();}
 }

 const csvNames=['czesci.csv','sprzedane.csv','do_wystawienia.csv','moje_samochody.csv','zdjecia_czesci.csv','zdjecia_czesci_issues.csv','marketplace_listingi_czesci.csv','marketplace_listingi_czesci_issues.csv'];
 function showCsv(value,run,attempt){
  if(run!==id || attempt!==state?.snapshot_attempt_id)return;
  taskSnapshots.csv=value;
  el('consolidated-csv-status').textContent=value.done?'Zbiorcze CSV gotowe. Pobierz pliki części, samochodów, zdjęć i listingów oraz raporty problemów.':'CSV: '+value.phase+' | przetworzone wiersze źródłowe: '+(value.processed??0)+' | shard: '+(value.shard??0)+' | wiersz: '+(value.row??0);
  diagnosticSet('consolidated-csv-listing-audit',value.listing_audit??{});
  filesPresent('consolidated-csv-downloads',value.done?(value.downloads??[]).filter(f=>csvNames.includes(f.key)).map(f=>({...f,name:f.key,href:base+'/'+encodeURIComponent(run)+'/consolidated-csv?parent_attempt_id='+encodeURIComponent(attempt)+'&download='+encodeURIComponent(f.key)})):[]);
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
  selectedTask='csv';const run=id,attempt=state.snapshot_attempt_id;csvWorking=true;csvStop=false;dataOnlyButtons();
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
  const disabled=!ready || sessionExpired || accessBlocked || !id || !dataOnlyAvailable() || schemaWorking || readbackWorking || supplementWorking || supplementLoading;
  el('allegro-supplement-plan').disabled=disabled || schemaValue?.done!==true || readbackValue?.done!==true;
  el('allegro-supplement-start').disabled=disabled || schemaValue?.done!==true || readbackValue?.done!==true || !supplementPlan || !el('allegro-supplement-confirm').checked || supplementValue?.done===true;
  el('allegro-supplement-resume').disabled=disabled || schemaValue?.done!==true || readbackValue?.done!==true || !supplementValue || supplementValue.done===true;
  el('allegro-supplement-status-refresh').disabled=disabled;
  el('allegro-supplement-stop').disabled=!supplementWorking || supplementStopped;
 }
 function supplementCancelDelay(){if(supplementWait){clearTimeout(supplementWait.timer);supplementWait.resolve();supplementWait=null;}}
 function supplementDelay(ms){return new Promise(resolve=>{supplementWait={resolve,timer:setTimeout(()=>{supplementWait=null;resolve();},ms)};});}
 function supplementResetView(){
  supplementEpoch++;
  supplementPlan=null;el('allegro-supplement-confirm').checked=false;diagnosticSet('allegro-supplement-plan-preview',{});
  supplementValue=null;supplementManifest=null;supplementPackageKey=null;supplementLoaded=null;
  el('allegro-supplement-downloads').replaceChildren();el('allegro-supplement-downloads').hidden=true;
  el('allegro-supplement-contents').replaceChildren();el('allegro-supplement-counts').replaceChildren();
  el('allegro-supplement-status').textContent='Pakiet nie został jeszcze odczytany.';
 }
 function supplementError(error){
  const reason=typeof error?.reason==='string' && /^[A-Za-z0-9_]{1,80}$/.test(error.reason)?error.reason:'supplement_network_error';
  return reason+(error.http?' / HTTP '+error.http:'');
 }
 const supplementTransient=error=>!error.http || [429,500,502,503,504].includes(error.http) || ['supplement_busy','schema_refresh_busy','offer_readback_busy'].includes(error.reason);
 async function supplementRequest(run,attempt,{action=null,download=null}={}){
  const endpoint=base+'/'+encodeURIComponent(run)+'/allegro-parameters-supplement',controller=new AbortController();let timedOut=false;
  const timer=setTimeout(()=>{timedOut=true;controller.abort();},action?30000:15000);
  const fresh={...(schemaValue?.done && schemaValue.plan_id?{fresh_plan_id:schemaValue.plan_id}:{}),...(readbackValue?.done && readbackValue.plan_id?{readback_plan_id:readbackValue.plan_id}:{})};
  const query=new URLSearchParams({parent_attempt_id:attempt,...fresh});if(download)query.set('download',download);
  try{
   const response=await fetch(action?endpoint:endpoint+'?'+query,{method:action?'POST':'GET',credentials:'same-origin',cache:'no-store',redirect:'error',headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},...(action?{body:JSON.stringify({parent_attempt_id:attempt,action,...fresh,...(action==='start'?{plan_digest:supplementPlan?.plan_digest,confirm:el('allegro-supplement-confirm').checked}:{})})}:{}),signal:controller.signal});
   if(response.redirected || [401,419].includes(response.status))throw {reason:'supplement_session_expired',http:response.status};
   let value;try{value=await response.json();}catch(_){throw {reason:'supplement_invalid_response',http:response.status};}
   if(!response.ok)throw {reason:reasonOf(value,'http_'+response.status),http:response.status};
   if(!download && fresh.fresh_plan_id && value.fresh_plan_id!==fresh.fresh_plan_id)throw {reason:'supplement_refresh_plan_changed',http:409};
   if(action==='plan')return value;
   if(!download && fresh.readback_plan_id && value.readback_plan_id!==fresh.readback_plan_id)throw {reason:'supplement_offer_plan_changed',http:409};
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
  el('allegro-supplement-status').textContent=value.schema_mode==='legacy_local_only'?'Archiwalny pakiet lokalny — bez świeżych schematów. Najpierw wykonaj etap A.':value.contract_revision<3?'Archiwalny pakiet bez odczytu istniejących ofert. Wykonaj krok B.':value.done?'Pakiet parametrów Allegro gotowy. Pobierz manifesty, checksumy i wszystkie wolumeny ZIP.':'Supplement: '+value.phase+' | shard: '+(value.shard??'—')+' | wiersz: '+(value.row??'—')+' | następny krok: '+value.next_action;
  if(!value.done){el('allegro-supplement-downloads').replaceChildren();el('allegro-supplement-downloads').hidden=true;}
  supplementShowCounts();supplementButtons();
 }
 function supplementLink(list,run,attempt,key,label,descriptor={}){
  if(!/^(manifest|checksums|summary|archive-manifest|parameters-csv|volume-[0-9]{6})$/.test(key))return;
  const item=document.createElement('li'),link=document.createElement('a');link.textContent=label;
  link.href=base+'/'+encodeURIComponent(run)+'/allegro-parameters-supplement?'+new URLSearchParams({parent_attempt_id:attempt,download:key,...(schemaValue?.done && schemaValue.plan_id?{fresh_plan_id:schemaValue.plan_id}:{}),...(readbackValue?.done && readbackValue.plan_id?{readback_plan_id:readbackValue.plan_id}:{})});
  item.append(link);if(descriptor.bytes!=null)item.append(document.createTextNode(' | '+supplementCount(descriptor.bytes)+' B'));
  if(typeof descriptor.sha256==='string' && /^[a-f0-9]{64}$/i.test(descriptor.sha256))item.append(document.createTextNode(' | SHA-256: '+descriptor.sha256));list.append(item);
 }
 async function supplementLoadPackage(run,attempt){
  if(!supplementCurrent(run,attempt) || !supplementValue?.done || supplementPackageKey===run+':'+attempt)return;
  const epoch=supplementEpoch;
  const list=el('allegro-supplement-downloads');list.replaceChildren();list.hidden=false;
  for(const [key,label] of [['manifest','Pobierz manifest supplementu'],['checksums','Pobierz checksumy'],['summary','Pobierz podsumowanie'],['archive-manifest','Pobierz manifest archiwów'],...(supplementValue?.readback_plan_id?[['parameters-csv','Pobierz allegro_parametry_czesci.csv']]:[])])supplementLink(list,run,attempt,key,label);
  const [manifest,archive]=await Promise.all([supplementRequest(run,attempt,{download:'manifest'}),supplementRequest(run,attempt,{download:'archive-manifest'})]);
  if(!supplementCurrent(run,attempt) || epoch!==supplementEpoch)return;
  if(manifest?.format!==supplementFormat || manifest.parent_run_id!==run || manifest.parent_attempt_id!==attempt || (schemaValue?.done && manifest.schema_refresh?.plan_id!==schemaValue.plan_id) || (readbackValue?.done && manifest.offer_readback?.plan_id!==readbackValue.plan_id))throw {reason:'supplement_manifest_identity_mismatch',http:409};
  if(archive?.format!=='gpswiss-browser-zip-volumes-1' || !Array.isArray(archive.volumes) || !Number.isSafeInteger(archive.volume_count) || archive.volume_count!==archive.volumes.length || archive.volumes.some(v=>!/^volume-[0-9]{6}$/.test(v?.key??'')) || new Set(archive.volumes.map(v=>v.key)).size!==archive.volumes.length)throw {reason:'supplement_invalid_archive_manifest',http:409};
  supplementManifest=manifest;supplementShowCounts();
  const contents=el('allegro-supplement-contents');contents.replaceChildren();
  for(const name of supplementDatasets){const item=document.createElement('li');item.textContent=name+'.csv / .jsonl — w ZIP | rekordy: '+supplementCount(manifest.datasets?.[name]?.rows);contents.append(item);}
  const fixed=Array.from(list.querySelectorAll('li')).map(li=>({name:li.querySelector('a')?.textContent,href:li.querySelector('a')?.getAttribute('href')})).filter(f=>f.href);
  const query={parent_attempt_id:attempt,...(schemaValue?.done?{fresh_plan_id:schemaValue.plan_id}:{}),...(readbackValue?.done?{readback_plan_id:readbackValue.plan_id}:{})};
  filesPresent('allegro-supplement-downloads',[...fixed,...archive.volumes.map(v=>({...v,name:v.name??v.key,volume:true,href:base+'/'+encodeURIComponent(run)+'/allegro-parameters-supplement?'+new URLSearchParams({...query,download:v.key})}))],{volumes:true});
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
  await boot;if(schemaValue?.done!==true || readbackValue?.done!==true || (start && (!supplementPlan || !el('allegro-supplement-confirm').checked)) || supplementWorking || supplementLoading || !id || !dataOnlyAvailable() || sessionExpired || accessBlocked || supplementValue?.done)return;
  selectedTask='supplement';const run=id,attempt=state.snapshot_attempt_id;supplementWorking=true;supplementStopped=false;supplementCancelDelay();supplementButtons();
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
  const disabled=!ready || !id || !dataOnlyAvailable() || sessionExpired || accessBlocked || schemaWorking || readbackWorking || supplementWorking;
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
  diagnosticSet('allegro-schema-plan-preview',{plan_id:value.plan_id,plan_digest:value.plan_digest,categories:value.categories});el('schema-plan-summary').textContent='Kategorie: '+value.total+' · Plan GET: '+value.expected_gets;categoryTable();
  schemaButtons();readbackButtons();supplementButtons();
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
  await boot;if(schemaWorking || readbackWorking || supplementWorking || !id || !dataOnlyAvailable() || sessionExpired || accessBlocked || (action==='start' && schemaValue?.phase==='planned' && !el('allegro-schema-confirm').checked))return;
  selectedTask='schema';const run=id,attempt=state.snapshot_attempt_id;schemaWorking=true;schemaStopped=false;schemaButtons();let failures=0;
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
  finally{schemaWorking=false;schemaButtons();readbackButtons();supplementButtons();}
 }
 function readbackButtons(){
  const disabled=schemaValue?.done!==true || !ready || !id || !dataOnlyAvailable() || sessionExpired || accessBlocked || readbackWorking || schemaWorking || supplementWorking;
  el('allegro-readback-plan').disabled=disabled || (!!readbackValue && !readbackValue.done);
  el('allegro-readback-plan').textContent=readbackValue?.done?'Przygotuj nowy plan odświeżenia':'Przygotuj plan';
  el('allegro-readback-start').disabled=disabled || readbackValue?.phase!=='planned' || !el('allegro-readback-confirm').checked;
  el('allegro-readback-resume').disabled=disabled || !readbackValue || ['planned','completed'].includes(readbackValue.phase);
  el('allegro-readback-stop').disabled=!readbackWorking || readbackStopped;
  el('allegro-readback-status-refresh').disabled=disabled;
 }
 function readbackDelay(ms){return new Promise(resolve=>{readbackWaiting={resolve,timer:setTimeout(()=>{readbackWaiting=null;resolve();},ms)};});}
 function readbackStop(){readbackStopped=true;if(readbackWaiting){clearTimeout(readbackWaiting.timer);readbackWaiting.resolve();readbackWaiting=null;}readbackButtons();}
 async function readbackRequest(run,attempt,action=null){
  const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),20000),endpoint=base+'/'+encodeURIComponent(run)+'/allegro-offer-readback';
  try{
   const response=await fetch(action?endpoint:endpoint+'?'+new URLSearchParams({parent_attempt_id:attempt}),{method:action?'POST':'GET',credentials:'same-origin',cache:'no-store',redirect:'error',signal:controller.signal,headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},...(action?{body:JSON.stringify({parent_attempt_id:attempt,action,...(action==='start'?{plan_digest:readbackValue?.plan_digest,confirm:true}:{})})}:{})});
   let value;try{value=await response.json();}catch(_){throw {reason:'readback_invalid_response',http:response.status};}
   if(!response.ok)throw {reason:reasonOf(value,'http_'+response.status),http:response.status};
   if(value.parent_run_id!==run || value.parent_attempt_id!==attempt)throw {reason:'readback_identity_mismatch',http:409};
   if(!['planning','preview','planned','fetch','blocked','completed'].includes(value.phase) || typeof value.done!=='boolean')throw {reason:'readback_contract_mismatch',http:409};
   return value;
  }catch(error){if(error.reason)throw error;throw {reason:'offer_network_or_timeout'};}
  finally{clearTimeout(timer);}
 }
 function readbackShow(value,run,attempt){
  if(!supplementCurrent(run,attempt))return;
  const changedPlan=readbackValue?.plan_id && value.plan_id!==readbackValue.plan_id;
  const finished=value.done && readbackValue!==null && (readbackValue.done!==true || changedPlan);readbackValue=value;
  el('allegro-readback-status').textContent='Odczyt ofert: '+value.phase+' | oferty '+value.processed+' / '+value.total+' | GET '+value.performed_gets+' / '+value.expected_gets+' | sukcesy '+value.successes+' | 404 '+value.not_found_404+' | 429 '+value.provider_429+' | błędy '+value.errors+(value.blocked_reason?' | blokada: '+value.blocked_reason:'');
  diagnosticSet('allegro-readback-plan-preview',{plan_id:value.plan_id,plan_digest:value.plan_digest,parts_with_allegro:value.parts_with_allegro,unique_offer_ids:value.unique_offer_ids,expected_gets:value.expected_gets,invalid_offer_ids:value.invalid_offer_ids,duplicate_offer_ids:value.duplicate_offer_ids,parts_with_multiple_offers:value.parts_with_multiple_offers,offers_linked_to_multiple_parts:value.offers_linked_to_multiple_parts,orphan_listings_excluded:value.orphan_listings_excluded,unsupported_accounts:value.unsupported_accounts});el('readback-plan-summary').textContent='Części z Allegro: '+value.parts_with_allegro+' · Unikalne oferty / GET: '+value.expected_gets+' · Niepoprawne ID: '+value.invalid_offer_ids+' · Duplikaty ID: '+value.duplicate_offer_ids+' · Części z wieloma ofertami: '+value.parts_with_multiple_offers+' · ID współdzielone między częściami: '+supplementCount(value.offers_linked_to_multiple_parts)+' · Orphan: '+value.orphan_listings_excluded+' · Inne konto: '+value.unsupported_accounts;
  readbackButtons();supplementButtons();
  if(finished || changedPlan){el('allegro-readback-confirm').checked=false;supplementLoaded=null;supplementViewKey=null;supplementResetView();loadSupplementStatus();}
 }
 function loadReadbackStatus(force=false){
  if(!id || !dataOnlyAvailable() || readbackWorking)return;
  const run=id,attempt=state.snapshot_attempt_id,key=run+':'+attempt;
  if(!force && readbackLoaded===key)return;
  if(readbackLoaded!==key){readbackValue=null;readbackStop();}
  readbackLoaded=key;
  readbackRequest(run,attempt).then(value=>readbackShow(value,run,attempt)).catch(error=>{if(supplementCurrent(run,attempt))el('allegro-readback-status').textContent=error.http===404 && error.reason==='offer_plan_not_started'?'Przygotuj plan — wejście na stronę nie pobiera danych z Allegro.':'Status odczytu ofert: '+supplementError(error);}).finally(readbackButtons);
 }
 async function readbackDrive(action){
  await boot;if(schemaValue?.done!==true || readbackWorking || schemaWorking || supplementWorking || !id || !dataOnlyAvailable() || sessionExpired || accessBlocked || (action==='start' && readbackValue?.phase==='planned' && !el('allegro-readback-confirm').checked))return;
  selectedTask='readback';const run=id,attempt=state.snapshot_attempt_id;readbackWorking=true;readbackStopped=false;readbackButtons();let failures=0;
  try{
   while(!readbackStopped && supplementCurrent(run,attempt)){
    try{
     const value=await readbackRequest(run,attempt,action);readbackShow(value,run,attempt);failures=0;
     if(['planned','blocked','completed'].includes(value.phase))return;
     action='tick';if(!readbackStopped)await readbackDelay(Math.max(150,Math.min(30000,(value.wait_seconds??0)*1000)));
    }catch(error){
     if(!supplementTransient(error) || ++failures>4)throw error;
     await readbackDelay(Math.min(4000,500*2**failures));
     if(readbackStopped)break;
     try{const before=JSON.stringify([readbackValue?.phase,readbackValue?.processed,readbackValue?.performed_gets]),value=await readbackRequest(run,attempt);readbackShow(value,run,attempt);if(JSON.stringify([value.phase,value.processed,value.performed_gets])!==before)failures=0;if(['planned','blocked','completed'].includes(value.phase))return;action='tick';}catch(statusError){if(!supplementTransient(statusError))throw statusError;}
    }
   }
   if(supplementCurrent(run,attempt))el('allegro-readback-status').textContent+=' | lokalna pętla zatrzymana; użyj Wznów';
  }catch(error){if(supplementCurrent(run,attempt))el('allegro-readback-status').textContent='Odczyt ofert zatrzymany: '+supplementError(error);}
  finally{readbackWorking=false;readbackButtons();schemaButtons();supplementButtons();}
 }
 el('allegro-schema-plan').onclick=()=>schemaDrive(schemaValue?.done?'new_plan':'plan');
 el('allegro-schema-start').onclick=()=>schemaDrive('start');
 el('allegro-schema-resume').onclick=()=>schemaDrive(schemaValue?.phase==='blocked'?'start':null);
 el('allegro-schema-stop').onclick=schemaStop;
 el('allegro-schema-confirm').onchange=schemaButtons;
 el('allegro-schema-status-refresh').onclick=()=>loadSchemaStatus(true);
 el('allegro-readback-plan').onclick=()=>readbackDrive(readbackValue?.done?'new_plan':'plan');
 el('allegro-readback-start').onclick=()=>readbackDrive('start');
 el('allegro-readback-resume').onclick=()=>readbackDrive(readbackValue?.phase==='blocked'?'start':null);
 el('allegro-readback-stop').onclick=readbackStop;
 el('allegro-readback-confirm').onchange=readbackButtons;
 el('allegro-readback-status-refresh').onclick=()=>loadReadbackStatus(true);
 el('allegro-supplement-confirm').onchange=supplementButtons;
 el('allegro-supplement-plan').onclick=async()=>{
  if(supplementWorking || supplementLoading)return;
  supplementLoading=true;supplementButtons();
  const run=id,attempt=state.snapshot_attempt_id;
  try{const value=await supplementRequest(run,attempt,{action:'plan'});if(!supplementCurrent(run,attempt))return;supplementPlan=value;el('allegro-supplement-confirm').checked=false;diagnosticSet('allegro-supplement-plan-preview',value);el('supplement-plan-summary').textContent='Kategorie: '+value.categories+' · Odczytane oferty: '+value.offers+' · Bez dalszych GET';}
  catch(error){el('allegro-supplement-status').textContent='Plan pakietu: '+supplementError(error);}
  finally{supplementLoading=false;supplementButtons();}
 };
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
  try{diagnosticSet('dataset-conflict',await request(base+'/'+encodeURIComponent(id)+'?format=dataset-conflict'));}
  catch(error){diagnosticSet('dataset-conflict',{reason_code:error.reason});if(error.reason==='SESSION_EXPIRED')stop(error);}
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
 runnerInit();
})();
</script></body></html>
