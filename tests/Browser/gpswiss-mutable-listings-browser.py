"""Failed production checkpoint: operator-only recovery of one dataset, same run/attempt.
All HTTP is locally intercepted. Source records/manifest behavior are covered by PHP tests.
"""
from pathlib import Path
from playwright.sync_api import sync_playwright

HTML = (Path(__file__).parents[2] / 'resources/views/admin/tools/gpswiss-migration-export.blade.php').read_text().replace(
    "@json(route('admin.tools.gpswiss-migration-export', [], false))", "'/admin/tools/gpswiss-migration-export'")
BASE = 'https://audit.test/admin/tools/gpswiss-migration-export'
RUN = '8de6e27c-ad7c-470b-830b-b9ecee9446ba'
ATT = '9cb33c92-3f1e-4db0-b08d-052715aa5f4c'
BOUNDARY = 'fixture-listings-generation-1'


def case(browser, mode):
    context = browser.new_context()
    page = context.new_page()
    actions = []
    reads = []
    ticks = 0
    resumes = 0
    applied = []
    state = dict(run_id=RUN, snapshot_attempt_id=ATT, snapshot_mode='browser', state='snapshot_failed', snapshot_state='snapshot_failed',
                 phase='snapshot_failed', current_dataset='marketplace_listings', processed=57363, total=79957, remaining=22594,
                 cursor={'id': 22580}, busy=False, next_action='resume', resume_allowed=True, dataset_recovery_allowed=True,
                 last_error='snapshot_row_updated_after_cutoff')
    if mode == 'nonwhitelisted':
        state.update(next_action='blocked', resume_allowed=False, dataset_recovery_allowed=False, last_error='snapshot_shard_checksum_mismatch')

    def begin():
        state.update(state='snapshot_running', snapshot_state='snapshot_running', phase='snapshot_recovery_preflight',
                     next_action='snapshot_tick', last_error=None, dataset_recovery_allowed=False,
                     dataset_recovery=dict(dataset='marketplace_listings', previous_generation=0, new_generation=1,
                         dataset_boundary_id=BOUNDARY, preflight_shard=0, preflight_total_shards=1))

    def route(r):
        nonlocal ticks, resumes
        req = r.request
        if req.is_navigation_request():
            r.fulfill(content_type='text/html', body=HTML)
            return
        if req.method == 'GET':
            reads.append(req.url)
            if req.url.endswith('?format=artifacts'):
                r.fulfill(json={'run_id': RUN, 'state': 'completed', 'download_artifacts': []})
                return
            if req.url.endswith('?format=dataset-conflict'):
                r.fulfill(json={'evidence': 'current_read_of_legacy_failure_page_not_historical_proof', 'record': {'id': 22581, 'updated_at': '2099-01-01 12:00:00'}, 'changed_business_fields': None})
                return
            assert req.url in [BASE+'?format=compact', BASE+'/'+RUN+'?format=compact'], req.url
            if mode == 'kick' and req.url == BASE+'/'+RUN+'?format=compact':
                r.abort('connectionreset')
                return
            r.fulfill(json={'existing_run_found': True, 'run': state} if req.url == BASE+'?format=compact' else state)
            return
        body = req.post_data_json
        action = 'kick' if req.url.endswith('/kick') else body.get('action')
        actions.append(action)
        assert action in ['resume', 'snapshot_tick', 'pause_snapshot', 'kick'], actions
        if action == 'resume':
            assert body == {'action': 'resume', 'expected_snapshot_attempt': ATT}
            resumes += 1
            if mode == '419':
                r.fulfill(status=419, body='session expired')
                return
            if state['state'] == 'snapshot_paused':
                state.update(state='snapshot_running', snapshot_state='snapshot_running', next_action='snapshot_tick')
            else:
                begin()
            if mode == 'lost_resume' and resumes == 1:
                r.abort('connectionreset')
                return
        elif action == 'kick':
            assert body == {'attempt_id': ATT}
            begin()
            r.fulfill(json={**state, 'kick_reason': 'recovery_started'})
            return
        elif action == 'pause_snapshot':
            state.update(state='snapshot_paused', snapshot_state='snapshot_paused', next_action='resume')
        else:
            assert body == {'action': 'snapshot_tick', 'expected_snapshot_attempt': ATT}
            ticks += 1
            if mode == 'network_tick' and ticks == 1:
                r.abort('connectionreset')
                return
            if state['phase'] == 'snapshot_recovery_preflight':
                state.update(phase='snapshot_export', dataset_recovery=None, dataset_generation=1, dataset_boundary_id=BOUNDARY,
                             previous_boundary_id='legacy', processed=57358, cursor=None)
            else:
                start = state['cursor']['id'] if state['cursor'] else 22575
                applied.extend(range(start+1, start+6))
                state.update(processed=state['processed']+5, cursor={'id': start+5})
                if len(applied) == 10:
                    state.update(state='completed', snapshot_state='snapshot_completed', next_action='download')
            if mode == 'lost_tick' and ticks == 1:
                r.abort('connectionreset')
                return
        r.fulfill(json=state)

    context.route('**/*', route)
    page.goto(BASE)
    page.wait_for_function("document.querySelector('#state').textContent.includes('57363')")
    if mode == 'nonwhitelisted':
        assert page.locator('#resume').is_disabled()
        assert not actions
        context.close()
        print('PASS mutable browser', mode)
        return
    assert page.locator('#resume').is_enabled()
    page.locator('#diagnose-conflict').click()
    page.wait_for_function("document.querySelector('#dataset-conflict').textContent.includes('22581')")
    assert not actions  # Diagnostic is strictly GET, never a recovery.
    if mode == 'reload':
        page.reload()
        page.wait_for_function("document.querySelector('#state').textContent.includes('57363')")
        assert not actions
    page.evaluate("document.querySelector('#resume').click();" + ("document.querySelector('#resume').click();" if mode == 'double_click' else ''))
    if mode == '419':
        page.wait_for_function("document.querySelector('#message').textContent.includes('SESSION_EXPIRED')")
        assert actions == ['resume'] and state['state'] == 'snapshot_failed'
    else:
        page.wait_for_function("document.querySelector('#message').textContent.includes('Eksport gotowy')", timeout=20000)
        assert resumes == (0 if mode == 'kick' else 1), actions
        assert applied == list(range(22576, 22586)) and len(applied) == len(set(applied))
        assert state['dataset_boundary_id'] == BOUNDARY
        trace = page.locator('#resume-diagnostic').inner_text()
        assert 'dataset_boundary_refresh_started' in trace
        if mode not in ['lost_resume', 'kick']:
            for event in ['recoverable_dataset_conflict', 'dataset=marketplace_listings', 'previous_generation=0', 'new_generation=1']:
                assert event in trace, trace
    assert state['run_id'] == RUN and state['snapshot_attempt_id'] == ATT
    assert not set(actions).intersection(['start', 'retry_snapshot', 'abort_snapshot'])
    context.close()
    print('PASS mutable browser', mode)


with sync_playwright() as pw:
    browser = pw.chromium.launch(executable_path='/usr/bin/chromium', args=['--no-sandbox'])
    for mode in ['normal', 'reload', 'double_click', 'lost_resume', 'network_tick', 'lost_tick', 'kick', '419', 'nonwhitelisted']:
        case(browser, mode)
    browser.close()
