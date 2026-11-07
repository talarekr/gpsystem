"""Completed canonical checkpoint / stale root, with intercepted HTTP only."""
import copy
import json
from pathlib import Path
from playwright.sync_api import sync_playwright
HTML=(Path(__file__).parents[2]/'resources/views/admin/tools/gpswiss-migration-export.blade.php').read_text().replace("@json(route('admin.tools.gpswiss-migration-export', [], false))", "'/admin/tools/gpswiss-migration-export'")
BASE='https://audit.test/admin/tools/gpswiss-migration-export'
RUN='8de6e27c-ad7c-470b-830b-b9ecee9446ba'; ATT='9cb33c92-3f1e-4db0-b08d-052715aa5f4c'


def case(browser,mode):
    context=browser.new_context();page=context.new_page()
    context.add_init_script('const native=setTimeout;window.setTimeout=(fn,ms,...args)=>native(fn,ms>=250 && ms<=8000?5:ms,...args);')
    accepted=dict(accepted_by_operator=True,dataset='parts',recorded_ids=['1937','2262'],known_count_from_operator=2,remaining_operator_declared_without_recorded_id=0)
    state=dict(run_id=RUN,snapshot_attempt_id=ATT,snapshot_mode='browser',schema_version='gpswiss-wystawczesc-3',state='snapshot_running',snapshot_state='snapshot_running',
               phase='snapshot_operator_finalize',snapshot_stage='completed',operator_finalize_stage='snapshot_seal',current_dataset='operator_finalize:snapshot_seal',
               processed=79957,total=79957,exported_records=79957,record_capture_total=79957,verification_processed=2155,next_action='snapshot_tick',busy=False,
               last_error=None,blocked_reason=None,resume_allowed=True,snapshot_retry_allowed=False,consistency_exception=copy.deepcopy(accepted),
               operator_finalization=dict(dataset_index=39,shard=0,artifacts_checked=15629,artifact_integrity_complete=True),
               cursor=dict(dataset_index=39,shard=0,artifacts_checked=15629,artifact_integrity_complete=True))
    if mode=='late_transition':
        state.update(snapshot_stage='operator_finalize',operator_finalize_stage='artifact_integrity',current_dataset='marketplace_account_identities',
                     cursor=dict(dataset_index=38,shard=0,artifacts_checked=15628))
    calls=0;fails=0;allow=False;actions=[];handoffs=0;photo_calls=0
    def route(r):
        nonlocal calls,fails,handoffs,photo_calls
        req=r.request
        if req.is_navigation_request():r.fulfill(content_type='text/html',body=HTML);return
        if req.method=='GET':
            assert req.url in [BASE+'?format=compact',BASE+'/'+RUN+'?format=compact'],req.url
            r.fulfill(json={'existing_run_found':True,'run':copy.deepcopy(state)} if req.url==BASE+'?format=compact' else copy.deepcopy(state));return
        body=req.post_data_json;actions.append(body['action']);assert body['action'] in ['snapshot_tick','tick']
        if body['action']=='tick':
            photo_calls+=1;state.update(busy=True,next_action='wait');r.fulfill(json=copy.deepcopy(state));return
        assert body=={'action':'snapshot_tick','expected_snapshot_attempt':ATT};calls+=1
        if mode=='blocked':
            state.update(state='snapshot_failed',last_error='operator_finalization_incomplete',blocked_reason='operator_finalization_incomplete',next_action='blocked')
            r.fulfill(status=409,json={'reason_code':'operator_finalization_incomplete','operator_finalize_diagnostic':{'operator_finalize_stage':'snapshot_seal','safe_exception_category':'controlled_blocker'}});return
        if mode=='stalled' and not allow:
            fails+=1;r.fulfill(status=500,json={'reason_code':'server_exception','backend_exception_class':'RuntimeException',
                 'operator_finalize_diagnostic':{'operator_finalize_stage':'snapshot_seal','dataset_index':39,'artifact_offset_before':state['cursor'],'artifact_offset_after':state['cursor'],'safe_exception_category':'infrastructure'}});return
        if state['phase']=='snapshot_operator_finalize':
            if state['operator_finalize_stage']=='artifact_integrity':
                state.update(operator_finalize_stage='snapshot_seal',current_dataset='operator_finalize:snapshot_seal',cursor=dict(dataset_index=39,shard=0,artifacts_checked=15629))
                r.fulfill(status=500,json={'reason_code':'server_exception'});fails+=1;return
            handoffs+=1;assert handoffs==1
            state.update(state='snapshot_completed',snapshot_state='snapshot_completed',phase='relation_audit',operator_finalize_stage='relations',current_dataset='relation_audit',
                         cursor=dict(current_dataset_index=0,shard_index=0,row_index=0,probes=0))
            if mode in ['500','network','malformed']:
                fails+=1
                if mode=='network':r.abort('connectionreset');return
                if mode=='malformed':r.fulfill(status=200,body='{broken');return
                r.fulfill(status=500,json={'reason_code':'server_exception','backend_exception_class':'RuntimeException','message':'SECRET_BODY',
                          'operator_finalize_diagnostic':{'action':'snapshot_tick','operator_finalize_stage':'snapshot_seal','stage_after':'relations','dataset_index':39,
                          'artifact_offset_before':{'dataset_index':39,'shard':0,'artifacts_checked':15629},'artifact_offset_after':{'dataset_index':39,'shard':0,'artifacts_checked':15629},
                          'safe_exception_category':'infrastructure','backend_exception_class':'RuntimeException','message':'SECRET_NESTED'}});return
        elif state['phase']=='relation_audit':
            state.update(phase='photo_inventory',operator_finalize_stage='photo_prepare',current_dataset='photo_inventory',cursor={'dataset':0,'shard':0,'row':0})
        elif state['phase']=='photo_inventory':
            state.update(state='photos',phase='photos',operator_finalize_stage='photos',current_dataset='photos',next_action='tick',processed=0,total=1,cursor={'task_offset':0,'current_image_offset':0})
        else:raise AssertionError(state)
        assert state['consistency_exception']==accepted
        r.fulfill(json=copy.deepcopy(state))
    context.route('**/*',route);page.goto(BASE)
    page.wait_for_function("document.querySelector('#state').textContent.includes('79957')")
    assert 'Etap finalizacji: ' in page.locator('#progress').inner_text()
    if mode=='reload':page.reload();page.wait_for_function("document.querySelector('#state').textContent.includes('79957')")
    page.evaluate("document.querySelector('#resume').click();"+("document.querySelector('#resume').click();" if mode=='double_click' else ''))
    if mode=='blocked':
        page.wait_for_function("document.querySelector('#message').textContent.includes('operator_finalization_incomplete')")
        assert calls==1;context.close();print('PASS operator handoff',mode,flush=True);return
    if mode=='stalled':
        page.wait_for_function("document.querySelector('#message').textContent.includes('retry_exhausted')",timeout=15000)
        assert calls==fails==5
        assert json.loads(page.locator('#resume-diagnostic').inner_text())['retry_exhausted'] is True
        allow=True;page.locator('#resume').click()
    page.wait_for_function("JSON.parse(document.querySelector('#state').textContent).phase==='photos'",timeout=15000)
    page.locator('#stop-local').click()
    assert handoffs==1 and photo_calls<=1 and state['run_id']==RUN and state['snapshot_attempt_id']==ATT
    assert state['consistency_exception']==accepted and state['exported_records']==79957 and state['verification_processed']==2155
    assert 'Wyeksportowane rekordy: 79957 / 79957' in page.locator('#progress').inner_text()
    trace=page.locator('#resume-diagnostic').inner_text()
    if mode in ['500','network','malformed','late_transition']:assert 'checkpoint_progress_detected' in trace and 'retry_counter_reset' in trace
    diag=page.locator('#request-diagnostic').inner_text();assert 'SECRET_' not in diag
    if mode=='500':
        failure=json.loads(diag)['last_operation_failure']
        assert failure['checkpoint_progress_detected'] and failure['checkpoint_before']['operator_finalize_stage']=='snapshot_seal'
        assert failure['checkpoint_after']['operator_finalize_stage']=='relations'
        assert failure['operator_finalize']['artifact_offset_before']['artifacts_checked']==15629
        assert failure['operator_finalize']['safe_exception_category']=='infrastructure'
    context.close();print('PASS operator handoff',mode,flush=True)

with sync_playwright() as pw:
    browser=pw.chromium.launch(executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    for mode in ['normal','500','network','malformed','late_transition','stalled','reload','double_click','blocked']:case(browser,mode)
    browser.close()
