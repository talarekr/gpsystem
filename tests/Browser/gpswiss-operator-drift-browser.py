"""Operator acceptance and byte/fragment recovery; every request is intercepted locally."""
import copy
import sys
from pathlib import Path
from playwright.sync_api import sync_playwright
HTML=(Path(__file__).parents[2]/'resources/views/admin/tools/gpswiss-migration-export.blade.php').read_text().replace("@json(route('admin.tools.gpswiss-migration-export', [], false))", "'/admin/tools/gpswiss-migration-export'")
BASE='https://audit.test/admin/tools/gpswiss-migration-export'
RUN='8de6e27c-ad7c-470b-830b-b9ecee9446ba'; ATT='9cb33c92-3f1e-4db0-b08d-052715aa5f4c'; BOUNDARY='a42db372-cf85-4f46-99e7-ccacce2bc6c3'
TOKEN='ACCEPT-PARTS-DRIFT-AND-FINALIZE'


def case(browser,mode):
    context=browser.new_context(); page=context.new_page()
    context.add_init_script('const native=setTimeout;window.setTimeout=(fn,ms,...args)=>native(fn,ms>=250 && ms<=8000?5:ms,...args);')
    actions=[]; accepts=0; snapshot_ticks=0; photo_ticks=0; archive_ticks=0; status_failures=0; allow=False; committed=[]
    state=dict(run_id=RUN,snapshot_attempt_id=ATT,schema_version='gpswiss-wystawczesc-3',snapshot_mode='browser',state='snapshot_running',snapshot_state='snapshot_running',
               phase='snapshot_recovery_preflight',current_dataset='parts',processed=79957,total=79957,exported_records=79957,verification_processed=2254,cursor={'id':8926},
               next_action='snapshot_tick',busy=False,last_error=None,blocked_reason=None,resume_allowed=True,operator_parts_drift_allowed=True,
               dataset_recovery=dict(dataset='parts',new_generation=1,dataset_boundary_id=BOUNDARY,preflight_shard=45,preflight_total_shards=1296))
    if mode=='corruption': state.update(operator_parts_drift_allowed=False,state='snapshot_failed',last_error='snapshot_shard_checksum_mismatch',next_action='blocked')

    def photo_step():
        nonlocal photo_ticks
        photo_ticks+=1
        old=state['cursor'].get('current_image_offset',0)
        if mode=='photo_stalled' and not allow:return False
        new=old+65536; assert new not in committed; committed.append(new)
        state['cursor']={'task_offset':0,'current_image_offset':new,'current_copy_index':0,'current_image_phase':'source'}
        if (mode=='photo_lost' and photo_ticks>=8) or (mode!='photo_lost' and photo_ticks>=2):
            state.update(state='archive',phase='archive_volumes',current_dataset='archive_volumes',cursor={'index_offset':0,'current_fragment_offset':0,'volume_count':0},processed=0,total=None)
        return True

    def route(r):
        nonlocal accepts,snapshot_ticks,archive_ticks,status_failures
        req=r.request
        if req.is_navigation_request():r.fulfill(content_type='text/html',body=HTML);return
        if req.url.endswith('?format=artifacts'):r.fulfill(json={'run_id':RUN,'state':'completed','download_artifacts':[]});return
        if req.method=='GET':
            assert req.url in [BASE+'?format=compact',BASE+'/'+RUN+'?format=compact'],req.url
            if mode=='photo_kick' and state['state']=='photos' and status_failures<4 and req.url==BASE+'/'+RUN+'?format=compact':
                status_failures+=1;r.abort('connectionreset');return
            r.fulfill(json={'existing_run_found':True,'run':copy.deepcopy(state)} if req.url==BASE+'?format=compact' else copy.deepcopy(state));return
        body=req.post_data_json; action='kick' if req.url.endswith('/kick') else body['action'];actions.append(action)
        assert action in ['accept_parts_drift','snapshot_tick','tick','pause_snapshot','resume','kick'],action
        assert state['snapshot_attempt_id']==ATT
        if action=='accept_parts_drift':
            accepts+=1;assert accepts==1 and body=={'action':action,'expected_snapshot_attempt':ATT,'confirm_parts_drift':TOKEN}
            if mode=='419':r.fulfill(status=419,body='expired');return
            state.update(phase='snapshot_operator_finalize',operator_parts_drift_allowed=False,dataset_recovery=None,
                         consistency_exception=dict(accepted_by_operator=True,dataset='parts',recorded_ids=['2262'],known_count_from_operator=2,
                         remaining_operator_declared_without_recorded_id=1,generation_at_acceptance=1,boundary_at_acceptance=BOUNDARY))
            if mode=='lost_accept':r.abort('connectionreset');return
        elif action=='snapshot_tick':
            assert body=={'action':action,'expected_snapshot_attempt':ATT};snapshot_ticks+=1
            if mode=='artifact_lost' and snapshot_ticks<=7:
                state['cursor']={'dataset_index':0,'shard':snapshot_ticks*5,'artifacts_checked':snapshot_ticks*5}
                r.fulfill(status=500,body='committed artifact check');return
            step=snapshot_ticks-7 if mode=='artifact_lost' else snapshot_ticks
            state['phase']=['relation_audit','photo_inventory','photos'][min(step-1,2)]
            if step>=3:state.update(state='photos',snapshot_state='snapshot_completed',current_dataset='photos',next_action='tick',processed=0,total=1,cursor={'task_offset':0,'current_image_offset':0,'current_copy_index':0,'current_image_phase':'source'})
        elif action=='pause_snapshot':
            assert state['state']=='photos';state.update(state='pipeline_paused',next_action='resume')
        elif action=='resume':
            assert state['state']=='pipeline_paused';state.update(state='photos',next_action='tick')
        elif action=='kick':
            assert mode=='photo_kick' and body=={'attempt_id':ATT};photo_step();r.fulfill(json={**state,'kick_reason':'tick_completed'});return
        elif state['state']=='photos':
            success=photo_step()
            if not success:r.fulfill(status=500,body='temporary');return
            if mode=='photo_lost' and photo_ticks<=7:r.abort('connectionreset');return
        elif state['state']=='archive':
            archive_ticks+=1; state['cursor']={'index_offset':archive_ticks*512,'current_fragment_offset':archive_ticks*65536,'volume_count':archive_ticks}
            if mode=='archive_lost' and archive_ticks<=7:r.fulfill(status=500,body='lost after commit');return
            state.update(state='completed',phase='completed',next_action='download')
        else:raise AssertionError(state)
        r.fulfill(json=copy.deepcopy(state))

    context.route('**/*',route);page.goto(BASE)
    page.wait_for_function("document.querySelector('#state').textContent.includes('79957')")
    if mode=='corruption':
        assert page.locator('#parts-drift-actions').is_hidden() and not actions;context.close();print('PASS operator drift',mode,flush=True);return
    assert page.locator('#accept-parts-drift').is_disabled()
    page.locator('#parts-drift-confirm').check();page.locator('#parts-drift-token').fill('WRONG')
    assert page.locator('#accept-parts-drift').is_disabled()
    if mode=='bad_token':
        assert not actions;context.close();print('PASS operator drift',mode,flush=True);return
    page.locator('#parts-drift-token').fill(TOKEN)
    page.evaluate("document.querySelector('#accept-parts-drift').click();"+("document.querySelector('#accept-parts-drift').click();" if mode=='double_click' else ''))
    if mode=='419':
        page.wait_for_function("document.querySelector('#message').textContent.includes('SESSION_EXPIRED')")
        assert actions==['accept_parts_drift'];context.close();print('PASS operator drift',mode,flush=True);return
    page.wait_for_function("document.querySelector('#message').textContent.includes('Zdjęcia gotowe')",timeout=15000)
    assert photo_ticks==0 and accepts==1 and snapshot_ticks==(10 if mode=='artifact_lost' else 3)
    assert state['consistency_exception']['recorded_ids']==['2262']
    if mode=='reload':
        page.reload();page.wait_for_function("document.querySelector('#state').textContent.includes('photos')")
        assert photo_ticks==0
    page.locator('#resume').click()
    if mode=='photo_stalled':
        page.wait_for_function("document.querySelector('#message').textContent.includes('retry_exhausted')",timeout=15000);assert photo_ticks==5 and not committed
        allow=True;page.locator('#resume').click()
    if mode=='pause_reload':
        page.wait_for_function("document.querySelector('#state').textContent.includes('65536')")
        page.locator('#pause').click();page.wait_for_function("document.querySelector('#state').textContent.includes('pipeline_paused')")
        before=state['cursor'];page.reload();page.wait_for_function("document.querySelector('#state').textContent.includes('pipeline_paused')")
        assert state['cursor']==before;page.locator('#resume').click()
    page.wait_for_function("document.querySelector('#message').textContent.includes('Eksport gotowy')",timeout=15000)
    assert accepts==1 and state['run_id']==RUN and state['snapshot_attempt_id']==ATT
    if mode=='photo_lost':assert photo_ticks==8
    if mode=='archive_lost':assert archive_ticks==8
    if mode=='photo_kick':assert status_failures==4 and actions.count('kick')==1
    assert state['consistency_exception']['generation_at_acceptance']==1 and state['consistency_exception']['boundary_at_acceptance']==BOUNDARY
    context.close();print('PASS operator drift',mode,flush=True)

with sync_playwright() as pw:
    browser=pw.chromium.launch(executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    for mode in (sys.argv[1:] or ['normal','lost_accept','artifact_lost','double_click','bad_token','419','corruption','reload','photo_lost','photo_stalled','pause_reload','photo_kick','archive_lost']):case(browser,mode)
    browser.close()
