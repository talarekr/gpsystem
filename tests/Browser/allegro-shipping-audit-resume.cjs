// No dependencies: exercises the actual panel script across a lost response and a fresh page VM.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../resources/views/admin/tools/allegro/shipping-rate-backfill.blade.php'), 'utf8').split('<script>')[1].split('</script>')[0];
const clone = value => JSON.parse(JSON.stringify(value));
const flags = {database_writes:false,technical_report_writes:false,business_writes:false,allegro_writes:false,product_writes:false,marketplace_writes:false};
let checkpoint = null, lostResponse = true, catalogSyncs = 0;
const seen = [];
const runId = '11111111-1111-4111-8111-111111111111';
function response() {return {ok:true,json:async()=>({...flags,run:clone(checkpoint)})};}
async function fetch(url, options) {
    const action = options.body ? JSON.parse(options.body).action : null;
    if(action) assert.equal(options.headers['X-CSRF-TOKEN'],'csrf-test');
    if(url.endsWith('/sync-catalog')) {assert.equal(options.method,'POST');catalogSyncs++;return {ok:true,json:async()=>({...flags,database_writes:true,catalog_writes:true,ok:true,rates_count:18})};}
    if(action==='start') checkpoint={run_id:runId,state:'paused',phase:'scan',scan_complete:false,processed_count:0,total:3,resolved:0,provider_read_failed:0,remaining:3,retry_remaining:0,current_part:null,cursor:0,max_part_id:3,last_success:null,last_error:null,wait_seconds:0,busy:false,summary:{total_parts:0,parts_with_one_active_offer:0,parts_without_active_offer:0,parts_with_multiple_active_offers:0,empty_kurier_allegro:0},preview:[],breakdown:{},catalog:[],local_options:[]};
    if(action==='resume') checkpoint.state='running';
    if(action==='pause') checkpoint.state='paused';
    if(action==='stop') checkpoint.state='stopped';
    if(action==='batch') {
        if(checkpoint.processed_count===3){checkpoint.state='completed';checkpoint.scan_complete=true;return response();}
        checkpoint.cursor++;checkpoint.processed_count++;checkpoint.resolved++;checkpoint.remaining--;
        checkpoint.summary.total_parts++;checkpoint.summary.parts_with_one_active_offer++;checkpoint.summary.empty_kurier_allegro++;
        seen.push(checkpoint.cursor);
        if(checkpoint.processed_count===2 && lostResponse){lostResponse=false;throw new TypeError('Failed to fetch');}
    }
    return response();
}
function page(initialHash='') {
    const nodes=new Map();
    function node(){return {textContent:'',disabled:false,hidden:false,append(){},replaceChildren(){},createTHead(){return this},createTBody(){return this},insertRow(){return this},insertCell(){return this}};}
    const location={pathname:'/admin/tools/allegro/shipping-rate-backfill',hash:initialHash};
    const context={URLSearchParams,fetch,setTimeout,document:{getElementById(id){if(!nodes.has(id))nodes.set(id,node());return nodes.get(id)},createElement:node,querySelector(){return {content:'csrf-test'}}},window:{location,history:{replaceState(a,b,hash){location.hash=hash}}}};
    vm.runInNewContext(source,context);
    return {nodes,location};
}
(async()=>{
    const first=page();await new Promise(setImmediate);
    await first.nodes.get('sync-catalog').onclick();
    assert.equal(catalogSyncs,1);assert.equal(checkpoint,null);assert.match(first.nodes.get('status').textContent,/18 cenników/);
    await first.nodes.get('start').onclick();
    assert.equal(checkpoint.processed_count,2);
    assert.equal(checkpoint.state,'paused');
    assert.match(first.nodes.get('status').textContent,/Checkpoint pozostaje/);
    const second=page(first.location.hash);await new Promise(setImmediate);
    assert.match(second.nodes.get('progress').textContent,/"processed": 2/);
    await second.nodes.get('resume').onclick();
    assert.equal(checkpoint.state,'completed');assert.equal(checkpoint.processed_count,3);
    assert.deepEqual(seen,[1,2,3]);
    assert.equal(second.nodes.get('csv').hidden,false);
    assert.match(second.nodes.get('mapping').href,/only=mapping/);
    console.log('PASS: lost response after commit, reload, resume, no duplicate resolved reads, durable export links.');
})().catch(error=>{console.error(error);process.exitCode=1;});
