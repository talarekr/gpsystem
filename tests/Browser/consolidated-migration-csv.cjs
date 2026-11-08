// Real Chromium, intercepted requests only. No application server or production traffic.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require('playwright');
const base='/admin/tools/gpswiss-migration-export',run='10000000-0000-4000-8000-000000000001',attempt='20000000-0000-4000-8000-000000000002';
const template=fs.readFileSync(path.join(__dirname,'../../resources/views/admin/tools/gpswiss-migration-export.blade.php'),'utf8');
const html=template.replace("@json(route('admin.tools.gpswiss-migration-export', [], false))",JSON.stringify(base)).replace('{{ csrf_token() }}','csrf-test');
const parent={schema_version:'gpswiss-wystawczesc-3',run_id:run,snapshot_attempt_id:attempt,snapshot_mode:'browser',snapshot_state:'snapshot_completed',snapshot_stage:'completed',state:'photos',phase:'photo_copy',exported_records:79957,next_action:'tick',resume_allowed:true,busy:false};
const files=['czesci.csv','sprzedane.csv','do_wystawienia.csv','moje_samochody.csv','zdjecia_czesci.csv','zdjecia_czesci_issues.csv'];
async function scenario(browser,{lost=false,reload=false,failure=false,stop=false}={}){
 const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 let prepared=false,done=false,starts=0,ticks=0,status=0;const forbidden=[];
 const result=()=>({run_id:run,attempt_id:attempt,phase:done?'completed':'parts',done,processed:ticks*50,shard:ticks,row:0,downloads:done?files.map((name,i)=>({key:name,name,rows:[5,1,2,3,5,3][i],bytes:100,sha256:'a'.repeat(64)})):[]});
 await page.route('**/*',async route=>{
  const request=route.request(),url=new URL(request.url()),body=request.postData()?JSON.parse(request.postData()):{};
  const json=(data,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(data)});
  if(request.isNavigationRequest())return route.fulfill({contentType:'text/html',body:html});
  if(url.pathname.endsWith('/consolidated-csv')){
   assert.equal(request.headers()['x-csrf-token'],'csrf-test');
   if(request.method()==='POST'){
    assert.equal(body.parent_attempt_id,attempt);
    if(body.action==='start'){starts++;prepared=true;return json(result());}
    assert.equal(body.action,'tick');ticks++;
    if(failure){failure=false;return json({reason_code:'service_unavailable'},503);}
    if(stop && ticks===1){await new Promise(resolve=>setTimeout(resolve,100));return json(result());}
    if(ticks>=3)done=true;
    if(lost && done){lost=false;return route.abort('failed');}
    return json(result());
   }
   assert.equal(url.searchParams.get('parent_attempt_id'),attempt);status++;
   return prepared?json(result()):json({reason_code:'csv_not_started'},404);
  }
  if(url.pathname.endsWith('/data-only')){
   assert.equal(request.method(),'GET','CSV must not start/repack DATA ONLY');
   return json({reason_code:'data_only_not_started'},404);
  }
  if(url.pathname.startsWith(base)){
   if(request.method()!=='GET')forbidden.push(body.action);
   return json(parent);
  }
  return route.abort();
 });
 await page.goto('http://gpsystem.test'+base+'#run='+run);
 await page.waitForFunction(()=>!document.getElementById('consolidated-csv').disabled);
 await page.evaluate(()=>{document.getElementById('consolidated-csv').click();document.getElementById('consolidated-csv').click();});
 if(stop){
  await page.waitForFunction(()=>document.getElementById('consolidated-csv-status').textContent.includes('CSV: parts'));
  await page.click('#consolidated-csv-stop');
  await page.waitForFunction(()=>!document.getElementById('consolidated-csv').disabled);
  const before=ticks;await page.waitForTimeout(250);assert.equal(ticks,before);
  await page.click('#consolidated-csv');
 }
 if(reload){
  await page.waitForFunction(()=>document.getElementById('consolidated-csv-status').textContent.includes('CSV: parts'));
  await page.reload();
  await page.waitForFunction(()=>!document.getElementById('consolidated-csv').disabled);
  await page.click('#consolidated-csv');
 }
 await page.waitForFunction(()=>document.querySelectorAll('#consolidated-csv-downloads a').length===6,{},{timeout:15000});
 const links=await page.locator('#consolidated-csv-downloads a').evaluateAll(elements=>elements.map(e=>({href:e.href,text:e.textContent})));
 for(const [i,link] of links.entries()){
  const url=new URL(link.href);assert.equal(url.pathname,base+'/'+run+'/consolidated-csv');assert.equal(url.searchParams.get('parent_attempt_id'),attempt);assert.equal(url.searchParams.get('download'),files[i]);assert.equal(link.text,'Pobierz '+files[i]);
 }
 assert.deepEqual(forbidden,[],'No parent snapshot, photo, marketplace, package operations');
 assert.equal(starts,reload||stop?2:1,'double clicks must not start parallel CSV loops');
 const startsBefore=starts,ticksBefore=ticks;
 await page.reload();
 await page.waitForFunction(()=>document.querySelectorAll('#consolidated-csv-downloads a').length===6);
 assert.equal(starts,startsBefore,'completed reload must GET only');assert.equal(ticks,ticksBefore);assert(status>0);assert.deepEqual(errors,[]);
 await page.close();
}
(async()=>{
 const executable=process.env.CHROMIUM_PATH??(fs.existsSync('/usr/bin/chromium')?'/usr/bin/chromium':undefined);
 const browser=await chromium.launch({headless:true,...(executable?{executablePath:executable}:{})});
 try{for(const options of [{},{lost:true},{reload:true},{failure:true},{stop:true}])await scenario(browser,options);console.log('5 Chromium consolidated CSV scenarios passed: downloads/reload, lost response, resume, 503, stop/double click.');}
 finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
