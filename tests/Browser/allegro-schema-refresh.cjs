const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require('playwright');
const base='/admin/tools/gpswiss-migration-export',run='10000000-0000-4000-8000-000000000001',attempt='20000000-0000-4000-8000-000000000002';
const html=fs.readFileSync(path.join(__dirname,'../../resources/views/admin/tools/gpswiss-migration-export.blade.php'),'utf8').replace("@json(route('admin.tools.gpswiss-migration-export', [], false))",JSON.stringify(base)).replace('{{ csrf_token() }}','csrf-test');
const parent={run_id:run,snapshot_attempt_id:attempt,snapshot_mode:'browser',snapshot_state:'snapshot_completed',snapshot_stage:'completed',state:'photos',phase:'photo_copy',next_action:'tick',exported_records:79957};
async function scenario(browser,{reload=false,blocked=false,lost=false,stopPlan=false,newPlan=false}={}){
 const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 let phase=null,planTicks=0,gets=0,generationGets=0,generation=0,stoppedOnce=false,blockedOnce=blocked,lostOnce=lost;
 const calls={plan:0,newPlan:0,start:0,tick:0,postOther:[]};
 const state=()=>({parent_run_id:run,parent_attempt_id:attempt,plan_id:'test-plan-'+generation,plan_digest:(generation?'b':'a').repeat(64),phase,done:phase==='completed',processed:Math.floor(generationGets/2),total:2,expected_gets:4,successes:Math.floor(generationGets/2),not_found_404:0,provider_429:0,errors:0,missing_schemas:0,wait_seconds:0,blocked_reason:phase==='blocked'?'oauth_refresh_required':null,categories:[{category_id:'100',local_cache_present:true,local_fetched_at:'2019-01-01',refresh_required:true},{category_id:'200',local_cache_present:false,local_fetched_at:null,refresh_required:true}]});
 await page.route('**/*',async route=>{
  const request=route.request(),url=new URL(request.url()),body=request.postData()?JSON.parse(request.postData()):{};
  const json=(v,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(v)});
  if(request.isNavigationRequest())return route.fulfill({contentType:'text/html',body:html});
  if(url.pathname.endsWith('/allegro-schema-refresh')){
   assert.equal(request.headers()['x-csrf-token'],'csrf-test');
   if(request.method()==='GET'){assert.equal(url.searchParams.get('parent_attempt_id'),attempt);return phase?json(state()):json({reason_code:'schema_plan_not_started'},404);}
   assert.equal(body.parent_attempt_id,attempt);
   if(body.action==='plan'){calls.plan++;phase='planning';return json(state());}
   if(body.action==='new_plan'){calls.newPlan++;assert.equal(phase,'completed');generation++;generationGets=0;planTicks=0;phase='planning';return json(state());}
   if(body.action==='start'){calls.start++;assert.equal(body.confirm,true);assert.equal(body.plan_digest,(generation?'b':'a').repeat(64));assert(['planned','blocked'].includes(phase));phase='fetch';return json(state());}
   assert.equal(body.action,'tick');calls.tick++;
   if(phase==='planning'){
    planTicks++;await new Promise(r=>setTimeout(r,150));if(planTicks>=2)phase='planned';return json(state());
   }
   assert.equal(phase,'fetch','tick without confirmed GET plan');
   await new Promise(r=>setTimeout(r,150));
   if(blockedOnce){blockedOnce=false;phase='blocked';return json(state());}
   gets++;generationGets++;if(generationGets===4)phase='completed';if(lostOnce){lostOnce=false;return route.abort('failed');}return json(state());
  }
  if(request.method()!=='GET')calls.postOther.push({path:url.pathname,body});
  if(url.pathname.endsWith('/allegro-parameters-supplement'))return json({reason_code:'supplement_not_started'},404);
  if(url.pathname.endsWith('/data-only')||url.pathname.endsWith('/consolidated-csv'))return json({reason_code:'not_started'},404);
  if(url.pathname.startsWith(base))return json(parent);
  calls.postOther.push({path:url.pathname});return route.abort();
 });
 await page.goto('http://gpsystem.test'+base+'#run='+run);
 await page.waitForFunction(()=>!document.getElementById('allegro-schema-plan').disabled);
 assert.equal(gets,0);assert.equal(calls.plan,0);assert(await page.locator('#allegro-supplement-start').isDisabled());
 await page.evaluate(()=>{document.getElementById('allegro-schema-plan').click();document.getElementById('allegro-schema-plan').click();});
 if(stopPlan){
  await page.waitForFunction(()=>document.getElementById('allegro-schema-status').textContent.includes('planning'));
  await page.click('#allegro-schema-stop');await page.waitForFunction(()=>!document.getElementById('allegro-schema-resume').disabled);await page.click('#allegro-schema-resume');
 }
 await page.waitForFunction(()=>document.getElementById('allegro-schema-status').textContent.includes('Definicje: planned'));
 assert.equal(gets,0);assert.equal(calls.plan,1);assert(await page.locator('#allegro-schema-start').isDisabled());
 await page.locator('#technical-diagnostics > summary').click();await page.locator('details').filter({has:page.locator('#allegro-schema-plan-preview')}).last().locator('summary').click();await page.waitForFunction(()=>document.getElementById('allegro-schema-plan-preview').textContent.includes('category_id'));const preview=await page.locator('#allegro-schema-plan-preview').textContent();assert(preview.includes('"category_id": "100"'));assert(preview.includes('2019-01-01'));assert(preview.includes('refresh_required'));
 await page.check('#allegro-schema-confirm');await page.click('#allegro-schema-start');
 if(blocked){
  await page.waitForFunction(()=>document.getElementById('allegro-schema-status').textContent.includes('oauth_refresh_required'));
  assert.equal(gets,0);await page.click('#allegro-schema-resume');
 }
 if(reload){
  await page.waitForFunction(()=>document.getElementById('allegro-schema-status').textContent.includes('Definicje: fetch'));
  await page.click('#allegro-schema-stop');await page.waitForFunction(()=>!document.getElementById('allegro-schema-resume').disabled);
  const confirmed=gets;await page.reload();await page.waitForFunction(()=>!document.getElementById('allegro-schema-resume').disabled);assert.equal(gets,confirmed);await page.click('#allegro-schema-resume');
 }
 await page.waitForFunction(()=>document.getElementById('allegro-schema-status').textContent.includes('Definicje: completed'),{},{timeout:15000});
 await page.waitForFunction(()=>!document.getElementById('allegro-readback-plan').disabled);
 assert.equal(gets,4,'four confirmed GETs only');
 if(newPlan){
  await page.click('#allegro-schema-plan');
  await page.waitForFunction(()=>document.getElementById('allegro-schema-status').textContent.includes('Definicje: planned'));
  assert.equal(gets,4);assert(await page.locator('#allegro-supplement-start').isDisabled());assert(await page.locator('#allegro-schema-start').isDisabled());
  await page.check('#allegro-schema-confirm');await page.click('#allegro-schema-start');
  await page.waitForFunction(()=>document.getElementById('allegro-schema-status').textContent.includes('Definicje: completed'));
  await page.waitForFunction(()=>!document.getElementById('allegro-readback-plan').disabled);
  assert.equal(gets,8);assert.equal(calls.newPlan,1);
 }
 assert.equal(calls.start,blocked||newPlan?2:1);
 await page.reload();await page.waitForFunction(()=>!document.getElementById('allegro-readback-plan').disabled);assert.equal(gets,newPlan?8:4);
 assert.deepEqual(calls.postOther,[]);assert.deepEqual(errors,[]);await page.close();
}
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_PATH??'/usr/bin/chromium'});
 const options=[{}, {reload:true}, {blocked:true}, {lost:true}, {stopPlan:true}, {newPlan:true}];
 try{for(const config of options)await scenario(browser,config);console.log('6 Chromium schema refresh scenarios passed: explicit plan/confirm, GET budget, reload/resume/stop, blocked auth, lost response, supplement gating.');}
 finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
