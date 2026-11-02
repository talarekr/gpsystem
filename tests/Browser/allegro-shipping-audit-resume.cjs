// Exercises the real panel script with virtual timers and durable fake server state. No dependencies.
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const source=fs.readFileSync(path.join(__dirname,'../../resources/views/admin/tools/allegro/shipping-rate-backfill.blade.php'),'utf8').split('<script>')[1].split('</script>')[0];
const flags={database_writes:false,business_writes:false,allegro_writes:false,product_writes:false,marketplace_writes:false};
const clone=x=>JSON.parse(JSON.stringify(x));
const id='e2229ef9-4db3-4c85-ab01-e7931e705e7b';
function server(total,processed=0){
 const s={seen:[],resumeCalls:0,startCalls:0,events:[],failAfter:null,failBefore:0,livePolls:0,sessionExpired:false};
 s.run={run_id:id,state:'running',phase:'scan',scan_complete:false,processed_count:processed,total,resolved:processed,provider_read_failed:0,remaining:total-processed,retry_remaining:0,current_part:null,cursor:processed,max_part_id:total,last_success:null,last_error:null,wait_seconds:0,busy:false,summary:{total_parts:processed,parts_with_one_active_offer:processed,parts_without_active_offer:0,parts_with_multiple_active_offers:0,empty_kurier_allegro:processed},preview:[],breakdown:{},catalog:[],local_options:[],tick_totals:{ticks:processed}};
 s.response=()=>({ok:true,status:200,json:async()=>{
  const r=clone(s.run);r.resume_allowed=['running','paused','stopped'].includes(r.state);
  r.next_action=r.state==='completed'?'none':r.state!=='running'?'resume':r.busy?'poll_lease':r.wait_seconds>0?'poll_cooldown':r.phase==='retry'?'retry_tick':'scan_tick';
  return {...flags,run:r,resume_allowed:r.resume_allowed,next_action:r.next_action,remaining:r.remaining,cursor:r.cursor,state:r.state};
 }});
 s.fetch=async(url,options)=>{
  const body=options.body?JSON.parse(options.body):{},action=body.action;
  if(action)assert.equal(options.headers['X-CSRF-TOKEN'],'csrf-test');
  if(url.endsWith('/sync-catalog'))return{ok:true,status:200,json:async()=>({...flags,database_writes:true,catalog_writes:true,ok:true,rates_count:18})};
  if(action==='start'){s.startCalls++;throw new Error('Existing-run scenarios must not create a run');}
  if(action==='client-event'){s.events.push(body.event);return s.response();}
  if(action==='resume'){s.resumeCalls++;s.run.state='running';s.run.stale_lease=false;}
  if(action==='pause')s.run.state='paused';
  if(action==='stop')s.run.state='stopped';
  if(!action && s.livePolls>0){s.run.busy=true;s.run.lease_expires_in=s.livePolls--;if(!s.livePolls)s.run.busy=false;}
  if(action==='batch'){
   if(s.abortNext){s.abortNext=false;const e=new Error('timeout');e.name='AbortError';throw e;}
   if(s.sessionExpired)return{ok:false,status:419};
   if(s.failBefore>0){s.failBefore--;throw new TypeError('Failed to fetch SECRET must not persist');}
   assert.equal(s.run.busy,false);
   if(s.run.processed_count===total){s.run.state='completed';s.run.scan_complete=true;return s.response();}
   s.run.cursor=s.nextIds?s.nextIds.shift():s.run.cursor+1;s.run.processed_count++;s.run.resolved++;s.run.remaining--;s.run.tick_totals.ticks++;
   s.run.summary.total_parts++;s.run.summary.parts_with_one_active_offer++;
   s.seen.push(s.run.cursor);
   if(s.brokenJsonAfter===s.run.cursor){s.brokenJsonAfter=null;return{ok:true,status:200,json:async()=>{throw new SyntaxError('truncated SECRET response')}};}
   if(s.failAfter===s.run.cursor){s.failAfter=null;throw new TypeError('Failed to fetch');}
  }
  return s.response();
 };
 return s;
}
function page(s,hash='#run='+id,holdTimers=false){
 const timers=[];
 const nodes=new Map(),messages=[];
 function node(){let text='';return{get textContent(){return text},set textContent(v){text=v;messages.push(String(v))},disabled:false,hidden:false,append(){},replaceChildren(){},createTHead(){return this},createTBody(){return this},insertRow(){return this},insertCell(){return this}};}
 const location={pathname:'/admin/tools/allegro/shipping-rate-backfill',hash};
 const c={URLSearchParams,fetch:s.fetch,setTimeout:fn=>{if(holdTimers)timers.push(fn);else queueMicrotask(fn);return timers.length+1;},clearTimeout(){},navigator:{onLine:true},document:{getElementById(k){if(!nodes.has(k))nodes.set(k,node());return nodes.get(k)},createElement:node,querySelector(){return{content:'csrf-test'}}},window:{location,history:{replaceState(a,b,h){location.hash=h}}}};
 vm.runInNewContext(source,c);return{nodes,messages,location,releaseTimers(){holdTimers=false;while(timers.length)queueMicrotask(timers.shift());}};
}
function nearEndServer(){
 const nearEnd=server(7318,7173);nearEnd.run.cursor=8690;nearEnd.run.max_part_id=8840;nearEnd.run.resolved=7014;nearEnd.run.provider_read_failed=159;
 nearEnd.run.lease_token=null;nearEnd.run.lease_token_present=false;nearEnd.run.lease_expires_in=0;nearEnd.run.blocked_reason=null;nearEnd.run.resume_reason='resumed';
 nearEnd.run.provider_read_failed_by_reason={offer_404:159};nearEnd.run.provider_offer_not_found_count=816;
 nearEnd.run.last_tick={reason:'product_completed',duration_ms:5,requested_items:1,completed_items:1,provider_requests:0};
 nearEnd.nextIds=Array.from({length:150},(_,i)=>8691+i).filter(id=>![8695,8700,8710,8720,8730].includes(id));
 return nearEnd;
}
(async()=>{
 const nearEnd=nearEndServer();
 const expected=[...nearEnd.nextIds];const endPage=page(nearEnd);await new Promise(setImmediate);
 await Promise.all([endPage.nodes.get('resume').onclick(),endPage.nodes.get('resume').onclick()]);
 assert.equal(nearEnd.run.processed_count,7318);assert.equal(nearEnd.run.remaining,0);assert.equal(nearEnd.run.cursor,8840);assert.equal(nearEnd.run.provider_read_failed,159);assert.equal(nearEnd.run.provider_offer_not_found_count,816);
 assert.equal(nearEnd.startCalls,0);assert.equal(nearEnd.resumeCalls,1);assert.deepEqual(nearEnd.seen,expected);
 assert.ok(endPage.messages.some(m=>m.includes('scan_tick_scheduled')));assert.ok(endPage.messages.some(m=>m.includes('"local_runner_active": true')));assert.ok(endPage.messages.some(m=>m.includes('"local_tick_scheduled": true')));
 for(const mode of ['reload','fetch_failed','status_poll']) {
  const s=nearEndServer();if(mode==='fetch_failed')s.failAfter=8691;if(mode==='status_poll')s.livePolls=3;
  let p=page(s);await new Promise(setImmediate);if(mode==='reload'){p=page(s,p.location.hash);await new Promise(setImmediate);}
  await p.nodes.get('resume').onclick();assert.equal(s.run.processed_count,7318);assert.equal(s.run.run_id,id);assert.equal(s.run.provider_read_failed,159);assert.equal(s.startCalls,0);assert.equal(new Set(s.seen).size,145);assert.equal(s.seen.length,145);
 }
 const stranded=nearEndServer(),wake=page(stranded,'#run='+id,true);await new Promise(setImmediate);
 const firstWake=wake.nodes.get('resume').onclick();await new Promise(setImmediate);assert.equal(stranded.seen.length,0);assert.equal(wake.nodes.get('resume').disabled,false);
 const secondWake=wake.nodes.get('resume').onclick();await new Promise(setImmediate);wake.releaseTimers();await Promise.all([firstWake,secondWake]);assert.equal(stranded.seen.length,145);assert.equal(new Set(stranded.seen).size,145);assert.equal(stranded.startCalls,0);
 const large=server(7318);large.failAfter=100;const p=page(large);await new Promise(setImmediate);
 await p.nodes.get('resume').onclick();
 assert.equal(large.run.processed_count,7318);assert.equal(large.run.state,'completed');assert.equal(large.startCalls,0);
 assert.equal(new Set(large.seen).size,7318);assert.equal(large.seen.length,7318);assert.ok(p.messages.some(x=>x.includes('auto_recovery')));
 assert.equal(large.events[0].reason,'fetch_failed');assert.ok(!JSON.stringify(large.events).includes('SECRET'));
 assert.equal(p.nodes.get('csv').hidden,false);
 const legacy=server(710,707);legacy.run.stale_lease=true;const a=page(legacy);await new Promise(setImmediate);
 await Promise.all([a.nodes.get('resume').onclick(),a.nodes.get('resume').onclick()]);
 assert.deepEqual(legacy.seen,[708,709,710]);assert.equal(legacy.resumeCalls,1);assert.equal(legacy.run.run_id,id);assert.ok(a.messages.some(x=>x.includes('resume_started')));
 const live=server(2);live.livePolls=3;const b=page(live);await new Promise(setImmediate);await b.nodes.get('resume').onclick();
 assert.equal(live.run.state,'completed');assert.ok(b.messages.some(x=>x.includes('waiting_for_live_lease')));
 const repeated=server(3,1);repeated.failBefore=4;const c=page(repeated);await new Promise(setImmediate);await c.nodes.get('resume').onclick();
 assert.match(c.nodes.get('status').textContent,/Runner zatrzymany/);assert.equal(repeated.run.processed_count,1);assert.equal(repeated.run.state,'running');
 await c.nodes.get('resume').onclick();assert.deepEqual(repeated.seen,[2,3]);assert.equal(repeated.startCalls,0);
 const session=server(3,1);session.sessionExpired=true;const d=page(session);await new Promise(setImmediate);await d.nodes.get('resume').onclick();assert.match(d.nodes.get('status').textContent,/session_expired/);assert.equal(session.run.processed_count,1);
 const timeout=server(3,1);timeout.abortNext=true;const t=page(timeout);await new Promise(setImmediate);await t.nodes.get('resume').onclick();assert.equal(timeout.run.state,'completed');assert.equal(timeout.events[0].reason,'browser_timeout');
 const malformed=server(3);malformed.brokenJsonAfter=1;const m=page(malformed);await new Promise(setImmediate);await m.nodes.get('resume').onclick();assert.equal(malformed.run.state,'completed');assert.equal(malformed.events[0].reason,'invalid_json');assert.deepEqual(malformed.seen,[1,2,3]);
 const reload=server(102,100);const before=page(reload);await new Promise(setImmediate);const after=page(reload,before.location.hash);await new Promise(setImmediate);await after.nodes.get('resume').onclick();assert.deepEqual(reload.seen,[101,102]);
 console.log('PASS: exact 7173/7318 cursor8690 ->8840, stale local active timer wake-up, 7318 logical products, auto next tick/recovery, legacy cursor 707, live/stale lease, duplicate resume clicks, retry exhaustion then manual resume, session loss, reload, no duplicate completed work.');
})().catch(e=>{console.error(e);process.exitCode=1;});
