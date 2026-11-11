// Chromium with intercepted HTTP only: no application server, DB or provider requests.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require('playwright');
const base='/admin/tools/gpswiss-migration-export',run='10000000-0000-4000-8000-000000000001',attempt='20000000-0000-4000-8000-000000000002';
const format='gpswiss-allegro-parameters-supplement-v1';
const template=fs.readFileSync(path.join(__dirname,'../../resources/views/admin/tools/gpswiss-migration-export.blade.php'),'utf8');
const html=template.replace("@json(route('admin.tools.gpswiss-migration-export', [], false))",JSON.stringify(base)).replace('{{ csrf_token() }}','csrf-test');
const parent={schema_version:'gpswiss-wystawczesc-3',run_id:run,snapshot_attempt_id:attempt,snapshot_mode:'browser',snapshot_state:'snapshot_completed',snapshot_stage:'completed',state:'photos',phase:'photo_copy',exported_records:79957,next_action:'tick',resume_allowed:true,busy:false};
const parentFrozen=JSON.stringify(parent);
const summary={resolved_parameter_rows:456,conflicts:2,missing_required_values:9,completeness:{complete:444,partial:6,historical_only:3,schema_unknown:2,conflict:1}};
const datasets={allegro_category_parameters_cache:{rows:7},allegro_parameter_mappings:{rows:11},allegro_resolved_parameters:{rows:456},conflicts:{rows:2},completeness:{rows:120}};
const readyState=progress=>({format,parent_run_id:run,parent_attempt_id:attempt,phase:progress>=3?'completed':'resolve',shard:Math.floor(progress),row:progress*50,done:progress>=3,next_action:progress>=3?'download':'tick',summary,provider_calls:false,business_writes:false});
const manifest={format,parent_run_id:run,parent_attempt_id:attempt,datasets,summary,provider_calls:false,business_writes:false,automatic_publish:false};

async function scenario(browser,options={}){
 const {existing=false,completed=false,volumes=2,lost=false,transient=false,stop=false,initialStatusError=null,metadataFailure=false,badIdentity=false,badArchive=false,parentRunning=false,exhausted=false,cached=false,stopBackoff=false}=options;
 const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 const calls={start:0,tick:0,status:0,manifest:0,archive:0,parent:0,forbidden:[]};
 let prepared=existing||completed,progress=completed?3:0,lostPending=lost,transientPending=exhausted?5:(transient||stopBackoff?1:0),statusPending=initialStatusError,metadataPending=metadataFailure;
 const archive={format:'gpswiss-browser-zip-volumes-1',volume_count:volumes,volumes:Array.from({length:volumes},(_,i)=>({key:'volume-'+String(i+1).padStart(6,'0'),name:'volume-'+String(i+1).padStart(6,'0')+'.zip',path:'archive-volumes/volume-'+String(i+1).padStart(6,'0')+'.zip',bytes:12345,sha256:'a'.repeat(64)}))};
 if(badArchive)archive.volumes[0].key='javascript:alert(1)';
 await page.route('**/*',async route=>{
  const request=route.request(),url=new URL(request.url()),body=request.postData()?JSON.parse(request.postData()):{};
  const json=(data,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(data)});
  if(request.isNavigationRequest())return route.fulfill({contentType:'text/html',body:html});
  if(url.pathname.endsWith('/allegro-parameters-supplement')){
   assert.equal(request.headers()['x-csrf-token'],'csrf-test');assert.equal(request.headers().accept,'application/json');
   if(request.method()==='POST'){
    assert.equal(body.parent_attempt_id,attempt);assert.deepEqual(Object.keys(body).sort(),['action','parent_attempt_id']);
    if(body.action==='start'){calls.start++;prepared=true;return json(readyState(progress));}
    assert.equal(body.action,'tick');calls.tick++;
    if(transientPending){transientPending--;return json({reason_code:'supplement_server_exception',message:'DO_NOT_RENDER'},503);}
    if(stop || parentRunning)await new Promise(resolve=>setTimeout(resolve,200));
    progress++;const value=readyState(progress);
    if(lostPending){lostPending=false;return route.abort('failed');}
    return json(value);
   }
   assert.equal(request.method(),'GET');assert.equal(url.searchParams.get('parent_attempt_id'),attempt);
   const key=url.searchParams.get('download');
   if(key){
    assert(progress>=3,'downloads must wait for done=true');
    if(key==='manifest'){calls.manifest++;if(metadataPending){metadataPending=false;return json({reason_code:'supplement_server_exception'},503);}return json(manifest);}
    if(key==='archive-manifest'){calls.archive++;return json(archive);}
    assert(['checksums','summary'].includes(key)||/^volume-[0-9]{6}$/.test(key));return json({});
   }
   calls.status++;
   if(statusPending){const code=statusPending;statusPending=null;return json({reason_code:'DO NOT RENDER SENSITIVE MESSAGE',message:'DO_NOT_RENDER'},code);}
   if(badIdentity)return json({...readyState(progress),parent_attempt_id:'other-attempt'});
   return prepared?json(readyState(progress)):json({reason_code:'supplement_not_started'},404);
  }
  if(url.pathname.endsWith('/data-only') || url.pathname.endsWith('/consolidated-csv')){
   assert.equal(request.method(),'GET','supplement must not start DATA ONLY or CSV');return json({reason_code:'not_started'},404);
  }
  if(url.pathname.startsWith(base)){
   if(request.method()==='POST'){
    if(parentRunning && ['resume','tick','snapshot_tick'].includes(body.action)){
     if(body.action!=='resume')calls.parent++;
    }else calls.forbidden.push({path:url.pathname,action:body.action});
   }
   return json({...parent,processed_images:calls.parent});
  }
  calls.forbidden.push({path:url.pathname});return route.abort();
 });
 if(cached)await page.addInitScript(saved=>sessionStorage.setItem('gpswiss-export-last-operational-state',JSON.stringify(saved)),parent);
 await page.goto('http://gpsystem.test'+base+'#run='+run);
 await page.waitForFunction(()=>!document.getElementById('allegro-supplement-status-refresh').disabled).catch(async error=>{console.error({options,errors,calls,status:await page.locator('#allegro-supplement-status').textContent()});throw error;});
 assert.equal(await page.locator('#allegro-supplement-heading').textContent(),'Parametry Allegro — pakiet uzupełniający');
 assert((await page.locator('#allegro-supplement-identity').textContent()).includes(attempt));
 if(initialStatusError){
  const expected=[401,419].includes(initialStatusError)?'supplement_session_expired':'http_'+initialStatusError;
  assert((await page.locator('#allegro-supplement-status').textContent()).includes(expected));
  assert.equal(calls.start,0);assert.equal(calls.tick,0);
  await page.click('#allegro-supplement-status-refresh');
  await page.waitForFunction(()=>!document.getElementById('allegro-supplement-status-refresh').disabled);
 }
 if(badIdentity){
  assert((await page.locator('#allegro-supplement-status').textContent()).includes('supplement_identity_or_contract_mismatch'));
  assert.equal(calls.start,0);assert.equal(calls.tick,0);assert.equal(await page.locator('#allegro-supplement-downloads a').count(),0);
 }else{
  if(metadataFailure && completed){
   assert((await page.locator('#allegro-supplement-status').textContent()).includes('supplement_server_exception'));
   await page.click('#allegro-supplement-status-refresh');
  }
  if(parentRunning){await page.click('#resume');await page.waitForFunction(()=>document.getElementById('state').textContent.includes('"local_runner_active": true'));}
  if(!completed){
   const button=existing?'allegro-supplement-resume':'allegro-supplement-start';
   await page.evaluate(button=>{document.getElementById(button).click();document.getElementById(button).click();},button);
   if(exhausted){
    await page.waitForFunction(()=>document.getElementById('allegro-supplement-status').textContent.includes('Supplement zatrzymany:') && !document.getElementById('allegro-supplement-resume').disabled,{},{timeout:20000});
    assert.equal(calls.tick,5);assert.equal(progress,0);await page.click('#allegro-supplement-resume');
   }
   if(stopBackoff){
    await page.waitForFunction(()=>document.getElementById('allegro-supplement-status').textContent.includes('ponowienie 1/4'));
    await page.click('#allegro-supplement-stop');await page.waitForFunction(()=>!document.getElementById('allegro-supplement-resume').disabled);
    const before=calls.tick;await page.waitForTimeout(600);assert.equal(calls.tick,before);await page.click('#allegro-supplement-resume');
   }
   if(stop || parentRunning){
    await page.waitForFunction(()=>document.getElementById('allegro-supplement-status').textContent.includes('wiersz: 50'));
    await page.click('#allegro-supplement-stop');
    await page.waitForFunction(()=>!document.getElementById('allegro-supplement-resume').disabled);
    const paused=calls.tick,photosBefore=calls.parent;await page.waitForTimeout(400);assert.equal(calls.tick,paused,'supplement stop must halt only its own ticks');
    if(parentRunning)assert(calls.parent>photosBefore,'photo runner must continue while supplement is stopped');
    await page.click('#allegro-supplement-resume');
   }
  }
  if(badArchive){
   await page.waitForFunction(()=>document.getElementById('allegro-supplement-status').textContent.includes('supplement_invalid_archive_manifest'));
   assert.equal(await page.locator('#allegro-supplement-downloads a').count(),4);
  }else{
   await page.waitForFunction(n=>document.querySelectorAll('#allegro-supplement-downloads a').length===n,4+volumes,{timeout:15000});
   await page.waitForFunction(()=>!document.getElementById('allegro-supplement-status-refresh').disabled);
   const counts=await page.locator('#allegro-supplement-counts li').allTextContents();
   for(const text of ['Rekordy category/schema cache: 7','Parameter mappings: 11','Resolved parameters: 456','complete: 444','partial: 6','historical_only: 3','schema_unknown: 2','conflict: 1','Brakujące required wg lokalnego cache: 9'])assert(counts.includes(text),text);
   assert.equal(await page.locator('#allegro-supplement-contents li').count(),5);
   assert((await page.locator('#allegro-supplement-contents').textContent()).includes('allegro_category_parameters_cache.csv / .jsonl'));
   const links=await page.locator('#allegro-supplement-downloads a').evaluateAll(elements=>elements.map(e=>e.href));
   for(const link of links){const url=new URL(link);assert.equal(url.origin,'http://gpsystem.test');assert.equal(url.pathname,base+'/'+run+'/allegro-parameters-supplement');assert.equal(url.searchParams.get('parent_attempt_id'),attempt);assert(/^(manifest|checksums|summary|archive-manifest|volume-[0-9]{6})$/.test(url.searchParams.get('download')));}
   if(!existing&&!completed)assert.equal(calls.start,1,'double click must create one local loop');else assert.equal(calls.start,0);
   assert.equal(progress,3,'only three committed backend steps, no duplicate processing');
   assert.equal(calls.tick,completed?0:(exhausted?8:((transient||stopBackoff)?4:3)));
   if(parentRunning){await page.click('#stop-local');await page.waitForFunction(()=>!document.getElementById('resume').disabled);}
   const startsBefore=calls.start,ticksBefore=calls.tick;
   await page.reload();await page.waitForFunction(n=>document.querySelectorAll('#allegro-supplement-downloads a').length===n,4+volumes);
   assert.equal(calls.start,startsBefore,'reload must not start or regenerate the package');assert.equal(calls.tick,ticksBefore);
  }
 }
 assert.equal(JSON.stringify(parent),parentFrozen,'parent fixture unchanged');assert.deepEqual(calls.forbidden,[]);assert.deepEqual(errors,[]);
 assert(!(await page.locator('#allegro-supplement-status').textContent()).includes('DO_NOT_RENDER'));
 await page.close();
}
(async()=>{
 const executable=process.env.CHROMIUM_PATH??(fs.existsSync('/usr/bin/chromium')?'/usr/bin/chromium':undefined);
 const browser=await chromium.launch({headless:true,...(executable?{executablePath:executable}:{})});
 const options=[{}, {existing:true}, {stop:true}, {lost:true}, {transient:true}, {existing:true,initialStatusError:503}, {completed:true,volumes:74}, {completed:true,metadataFailure:true}, {existing:true,initialStatusError:403}, {existing:true,initialStatusError:419}, {badIdentity:true}, {completed:true,badArchive:true}, {parentRunning:true}, {exhausted:true}, {cached:true,existing:true}, {stopBackoff:true}];
 try{for(const config of options)await scenario(browser,config);console.log(options.length+' Chromium Allegro supplement scenarios passed: actions/resume/stop, counters, private volumes/reload, lost response/retry, auth/identity, independent photo runner.');}
 finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
