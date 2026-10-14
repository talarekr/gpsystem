// Runs the actual shared Blade script in an offline DOM/HTTP harness.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const script = fs.readFileSync('resources/views/admin/tools/storefront/_fr-translation-runner.blade.php', 'utf8').replace(/^<script>\s*/, '').replace(/<\/script>\s*$/, '');
class Element {
  constructor() { this.textContent=''; this.value=''; this.disabled=false; this.listeners={}; }
  setAttribute() {} append() {} replaceChildren() {}
  addEventListener(event, listener) { this.listeners[event]=listener; }
  click() { assert.equal(this.disabled,false); this.listeners.click(); }
}
function root() { const elements=new Map(); return {elements, querySelector(selector) { if(!elements.has(selector))elements.set(selector,new Element()); return elements.get(selector); }}; }
let state={run_id:'295dd611-17df-46ee-9646-4c941f7e497f',runner_mode:'browser',status:'stopped_on_error',total:21,processed:18,translated:13,failed:5,remaining:3,scope:'jarek_gearboxes',resume_available:true,run_storage_ready:true};
let ticks=0, reads=0;
const intervals=[];
const context={console,Date,AbortController,setTimeout,clearTimeout,document:{querySelector:()=>({content:'offline-csrf'}),createElement:()=>new Element()},window:{setTimeout,clearTimeout,setInterval:fn=>intervals.push(fn)},fetch:async (url,options)=>{
  if(url==='status'){reads++;}
  if(url==='resume')state={...state,status:'running'};
  if(url==='pause')state={...state,status:'paused'};
  if(url==='stop')state={...state,status:'stopped'};
  if(url==='tick'){
    ticks++; assert.equal(state.status,'running');
    state={...state,processed:state.processed+1,remaining:state.remaining-1,failed:state.failed+(ticks===1?1:0),translated:state.translated+(ticks===1?0:1)};
    if(state.remaining===0)state.status='completed';
  }
  return {status:200,ok:true,redirected:false,json:async()=>({...state})};
}};
vm.createContext(context);vm.runInContext(script,context);
const overview={enabled:true,provider_ready:true,schema_ready:true,catalog:{inventory:{translated:13}}};
const urls={status:'status',resume:'resume',tick:'tick',pause:'pause',stop:'stop'};
const first=root();context.mountFrTranslationSection(first,overview,{...state},urls,'TRANSLATE-JAREK-GEARBOXES-FR','jarek-',[['jarek_gearboxes','Jarek']]);
const settle=()=>new Promise(resolve=>setImmediate(resolve));
(async()=>{
  await intervals[0](); assert.equal(ticks,0); // Reload/read does not authorize API work.
  first.querySelector('#jarek-resume').click(); await settle();
  assert.equal(first.querySelector('#jarek-run-active_timer').textContent,'true');
  await intervals[0](); assert.equal(ticks,1); assert.equal(state.failed,6);
  assert.equal(first.querySelector('#jarek-run-active_timer').textContent,'true'); // Record error keeps timer alive.
  first.querySelector('#jarek-pause').click(); await settle(); await intervals[0](); assert.equal(ticks,1);
  first.querySelector('#jarek-resume').click(); await settle(); await intervals[0](); assert.equal(ticks,2);
  const saved={...state}, second=root();context.mountFrTranslationSection(second,overview,{...saved},urls,'TRANSLATE-JAREK-GEARBOXES-FR','jarek-',[['jarek_gearboxes','Jarek']]);
  await intervals[1](); assert.equal(ticks,2); assert.equal(second.querySelector('#jarek-run-active_timer').textContent,'false');
  second.querySelector('#jarek-resume').click(); await settle(); await intervals[1](); assert.equal(ticks,3);
  assert.equal(state.status,'completed'); assert.equal(state.processed,21); assert.equal(state.run_id,saved.run_id);
  assert.equal(second.querySelector('#jarek-run-active_timer').textContent,'false');
  await intervals[1](); assert.equal(ticks,3); assert.ok(reads>0);
  console.log('OK: real browser script continues after record failure; Pause/Resume/reload/completion preserve run. No network.');
})().catch(error=>{console.error(error);process.exitCode=1;});
