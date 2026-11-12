const {chromium}=require('playwright'),fs=require('fs'),assert=require('node:assert/strict');
const base='/admin/tools/product-migration-export',id='20000000-0000-4000-8000-000000000001';
const html=fs.readFileSync(__dirname+'/../../resources/views/admin/tools/product-migration-export.blade.php','utf8').replace("@json(route('admin.tools.product-migration-export', [], false))",JSON.stringify(base)).replace('@json((string)auth()->id())','"7"').replace('{{ csrf_token() }}','csrf-test');
(async()=>{const browser=await chromium.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox']});try{
 for(const nonJson of [false,true]){
 const p=await browser.newPage();let prepared=false;
 const diagnostic={export_id:id,action:'capture',phase:'schema_engine_check',reason_code:'product_export_operation_failed',backend_exception_class:'ErrorException',safe_exception_category:'php_runtime_warning'};
 await p.route('**/*',async route=>{const r=route.request();if(r.isNavigationRequest())return route.fulfill({contentType:'text/html',body:html});const b=JSON.parse(r.postData()||'{}');
 if(b.action==='prepare'){prepared=true;return route.fulfill({contentType:'application/json',body:JSON.stringify({export_id:id,state:'capturing',mode:'sample',total:0,processed:0,images:0,listings:0,issues:0,sample_part_ids:[],files:{}})});}
 if(b.action==='capture')return route.fulfill(nonJson?{status:500,contentType:'text/html',body:'<h1>Server Error SECRET</h1>'}:{status:500,contentType:'application/json',body:JSON.stringify({error:'product_export_operation_failed',export_id:id,diagnostic})});
 throw Error('Unexpected request');});
 await p.goto('https://gpswiss.pl'+base);await p.click('#prepare');await p.waitForFunction(()=>document.querySelector('#error').textContent.length>0);
 assert(prepared);const text=await p.locator('#diagnostics').textContent();assert(!text.includes('SECRET'));
 assert(text.includes(nonJson?'non_json_server_response':'schema_engine_check'));assert(await p.locator('#resume').isDisabled());
 await p.close();
 }
 console.log('PASS failed-operation receipt and non-JSON server failure shown safely');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1)});
