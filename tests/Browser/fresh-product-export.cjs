// Intercepted Chromium UX test: no application or provider traffic.
const {chromium}=require('playwright'), fs=require('fs'), assert=require('node:assert/strict');
const base='/admin/tools/product-migration-export', id='10000000-0000-4000-8000-000000000001';
const html=fs.readFileSync(__dirname+'/../../resources/views/admin/tools/product-migration-export.blade.php','utf8')
.replace("@json(route('admin.tools.product-migration-export', [], false))",JSON.stringify(base))
.replace('@json((string)auth()->id())','"7"').replace('{{ csrf_token() }}','csrf-test');
(async()=>{const browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH||'/usr/bin/chromium',headless:true,args:['--no-sandbox']});try{
 const page=await browser.newPage(), errors=[];page.on('pageerror',e=>errors.push(e.message));
 let state={export_id:id,type:'czesci',mode:'sample',state:'capturing',total:5,processed:0,images:0,listings:0,issues:0,sample_part_ids:['1','2','3','4','5'],files:{}},fail=true,captures=0,ticks=0;
 await page.route('**/*',async route=>{
  const r=route.request(),u=new URL(r.url());
  if(r.isNavigationRequest())return route.fulfill({contentType:'text/html',body:html});
  assert(u.pathname.startsWith(base),'Unexpected request '+u.pathname);
  if(r.method()==='POST'){
   assert.equal(r.headers()['x-csrf-token'],'csrf-test');const b=JSON.parse(r.postData());
   if(b.action==='prepare'){assert.equal(b.mode,'sample');assert.equal(b.type,'czesci');}
   else if(b.action==='capture'){captures++;state.state='ready';}
   else if(b.action==='stop')state.state='stopped';
   else if(b.action==='resume')state.state='ready';
   else if(b.action==='tick'){ticks++;if(fail){fail=false;return route.fulfill({status:503,contentType:'application/json',body:'{"error":"temporary_failure"}'});}state.processed=5;state.images=10;state.listings=5;state.state='done';state.files={'czesci.csv':{},'zdjecia_czesci.csv':{},'manifest.json':{}};}
   else throw Error('Unexpected action '+b.action);
  }
  return route.fulfill({contentType:'application/json',body:JSON.stringify(state)});
 });
 await page.goto('https://gpswiss.pl'+base);await page.click('#prepare');await page.waitForFunction(()=>document.querySelector('#error').textContent.includes('temporary_failure'));
 assert.equal(captures,1);await page.reload();await page.waitForFunction(()=>document.querySelector('#counts').textContent.includes('0/5'));
 await page.click('#stop');await page.waitForFunction(()=>document.querySelector('#status').textContent==='Zatrzymany');
 await page.reload();await page.waitForFunction(()=>document.querySelector('#status').textContent==='Zatrzymany');
 await page.click('#resume');await page.waitForFunction(()=>document.querySelector('#status').textContent==='Gotowy do pobrania');
 assert.equal(captures,1);assert.equal(ticks,2);assert.equal(await page.locator('#files a').count(),3);
 assert.match(await page.locator('#files a').first().getAttribute('href'),new RegExp(id));
 await page.reload();await page.waitForFunction(()=>document.querySelectorAll('#files a').length===3);assert.deepEqual(errors,[]);
 console.log('PASS fresh export UI: prepare, capture, failure, reload, stop, resume, download links');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1)});
