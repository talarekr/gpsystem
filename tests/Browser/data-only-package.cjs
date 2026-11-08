// Real Chromium, intercepted HTTP only. No production access or application server.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require('playwright');
const base='/admin/tools/gpswiss-migration-export';
const run='10000000-0000-4000-8000-000000000001',attempt='20000000-0000-4000-8000-000000000002';
const template=fs.readFileSync(path.join(__dirname,'../../resources/views/admin/tools/gpswiss-migration-export.blade.php'),'utf8');
const html=template.replace("@json(route('admin.tools.gpswiss-migration-export', [], false))",JSON.stringify(base)).replace('{{ csrf_token() }}','csrf-test');
const parent={schema_version:'gpswiss-wystawczesc-3',run_id:run,snapshot_attempt_id:attempt,snapshot_mode:'browser',snapshot_state:'snapshot_completed',snapshot_stage:'completed',state:'photos',phase:'photo_copy',exported_records:79957,record_capture_total:79957,verification_processed:2155,processed_images:10,next_action:'tick',resume_allowed:true,busy:false};

async function scenario(browser,{lostResponse=false,resume=false}={}){
 const page=await browser.newPage(),errors=[];page.on('pageerror',error=>errors.push(error.message));
 const calls={start:0,tick:0,photo:0,forbidden:[],status:0};let prepared=false,done=false;
 const data=()=>({parent_run_id:run,parent_attempt_id:attempt,phase:done?'completed':'archive',records:79957,total_records:79957,files_checked:15629,volume_count:done?2:0,done,downloads:done?[
  {key:'data-only-package-manifest',name:'data-only-package-manifest.json',bytes:100,sha256:'a'.repeat(64)},
  {key:'data-only-archive-manifest',name:'data-only-archive-manifest.json',bytes:100,sha256:'b'.repeat(64)},
  {key:'volume-000001',name:'gpswiss-wystawczesc-data-only-'+run+'-part-0001.zip',bytes:100,sha256:'c'.repeat(64)},
  {key:'volume-000002',name:'gpswiss-wystawczesc-data-only-'+run+'-part-0002.zip',bytes:100,sha256:'d'.repeat(64)},
 ]:[]});
 await page.route('**/*',async route=>{
  const request=route.request(),url=new URL(request.url());
  if(request.isNavigationRequest())return route.fulfill({contentType:'text/html',body:html});
  const body=request.postData()?JSON.parse(request.postData()):{};
  const json=(value,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(value)});
  if(url.pathname.endsWith('/data-only')){
   assert.equal(request.headers()['x-csrf-token'],'csrf-test');
   if(body.action){assert.equal(body.parent_attempt_id,attempt);assert.equal(request.method(),'POST');}
   if(body.action==='start'){calls.start++;prepared=true;return json(data());}
   if(body.action==='tick'){
    calls.tick++;if(calls.tick>=2)done=true;
    if(lostResponse && done){lostResponse=false;return route.abort('failed');}
    return json(data());
   }
   calls.status++;return prepared?json(data()):json({reason_code:'data_only_not_started'},404);
  }
  if(url.pathname.startsWith(base)){
   if(body.action && !['resume','tick','snapshot_tick'].includes(body.action))calls.forbidden.push(body.action);
   if(body.action==='tick' || body.action==='snapshot_tick')calls.photo++;
   return json({...parent,processed_images:10+calls.photo});
  }
  return route.abort();
 });
 await page.goto('http://gpsystem.test'+base+'#run='+run);
 await page.waitForFunction(()=>!document.getElementById('data-only').disabled);
 if(resume){await page.click('#resume');await page.waitForFunction(()=>document.getElementById('state').textContent.includes('"local_runner_active": true'));}
 await page.evaluate(()=>{document.getElementById('data-only').click();document.getElementById('data-only').click();});
 await page.waitForFunction(()=>!document.getElementById('data-only-downloads').hidden,{},{timeout:15000});
 assert.equal(calls.start,1,'double click must not start two package loops');
 assert.equal(calls.tick,2,'lost response must recover through package status, not duplicate work');
 assert.deepEqual(calls.forbidden,[],'must not restart capture, pause photos or create a run');
 assert.equal(await page.locator('#data-only-downloads a').count(),4);
 const links=await page.locator('#data-only-downloads a').evaluateAll(elements=>elements.map(el=>el.href));
 assert(links.every(link=>link.includes('/'+run+'/data-only?parent_attempt_id='+attempt)));
 if(resume)assert(calls.photo>0,'existing photo runner must keep making progress');
 await page.reload();
 await page.waitForFunction(()=>document.querySelectorAll('#data-only-downloads a').length===4);
 assert.equal(calls.start,1,'reload must load private downloads without starting another package');
 assert.deepEqual(errors,[]);
 await page.close();
}
(async()=>{
 const executable=process.env.CHROMIUM_PATH ?? (fs.existsSync('/usr/bin/chromium')?'/usr/bin/chromium':undefined);
 const browser=await chromium.launch({headless:true,...(executable?{executablePath:executable}:{})});
 try{await scenario(browser);await scenario(browser,{lostResponse:true});await scenario(browser,{resume:true});console.log('3 Chromium DATA ONLY scenarios passed: download, retry/reload, parallel photo runner.');}
 finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
