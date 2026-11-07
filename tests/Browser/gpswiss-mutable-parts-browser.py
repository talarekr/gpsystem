"""Exact 79957/79957 parts verification failure; no HTTP escapes local mocks."""
import json
from pathlib import Path
from playwright.sync_api import sync_playwright
HTML = (Path(__file__).parents[2] / 'resources/views/admin/tools/gpswiss-migration-export.blade.php').read_text().replace(
    "@json(route('admin.tools.gpswiss-migration-export', [], false))", "'/admin/tools/gpswiss-migration-export'")
BASE = 'https://audit.test/admin/tools/gpswiss-migration-export'
RUN = '8de6e27c-ad7c-470b-830b-b9ecee9446ba'
ATT = '9cb33c92-3f1e-4db0-b08d-052715aa5f4c'
BOUNDARY = 'a42db372-cf85-4f46-99e7-ccacce2bc6c3'


def case(browser, mode):
    label = mode; pending = mode.startswith('pending_'); mode = mode.removeprefix('pending_')
    context = browser.new_context(); page = context.new_page()
    actions = []; rewritten = []; resumes = 0; ticks = 0; generations = 0
    state = dict(run_id=RUN, snapshot_attempt_id=ATT, snapshot_mode='browser', state='snapshot_failed', snapshot_state='snapshot_failed',
                 phase='snapshot_failed', current_dataset='parts', processed=79957, total=79957, remaining=0, exported_records=79957,
                 verification_processed=0, cursor={'id': 8926}, next_action='resume', busy=False, resume_allowed=True,
                 dataset_recovery_allowed=True, last_error='snapshot_row_updated_after_cutoff')
    if pending:
        generations = 1  # Already committed before root publication; resume must reuse it.
        state.update(last_error=None, dataset_recovery=dict(dataset='parts', strategy='adopt_complete_shards_then_reconcile',
                     dataset_boundary_id=BOUNDARY, previous_generation=0, new_generation=1, preflight_shard=0, preflight_total_shards=3),
                     last_tick=dict(action='snapshot_tick', reason_code='snapshot_row_updated_after_cutoff',
                                    started_at=1794006808.398781, source_conflict=dict(dataset='parts', record_id='2262')))
    if mode == 'blocked':
        state.update(last_error='snapshot_shard_checksum_mismatch', dataset_recovery_allowed=False, next_action='blocked')

    def start_reconciliation():
        nonlocal generations
        if pending:
            assert state['dataset_recovery']['dataset_boundary_id'] == BOUNDARY
            assert state['dataset_recovery']['new_generation'] == 1
        else:
            generations += 1
        assert generations == 1
        state.update(state='snapshot_running', snapshot_state='snapshot_running', phase='snapshot_recovery_preflight', next_action='snapshot_tick',
                     last_error=None, dataset_recovery_allowed=False, dataset_recovery=dict(dataset='parts', strategy='adopt_complete_shards_then_reconcile',
                     dataset_boundary_id=BOUNDARY, previous_generation=0, new_generation=1, preflight_shard=0, preflight_total_shards=3))

    def route(r):
        nonlocal ticks, resumes
        req = r.request
        if req.is_navigation_request():
            r.fulfill(content_type='text/html', body=HTML); return
        if req.method == 'GET':
            if req.url.endswith('?format=artifacts'):
                r.fulfill(json={'run_id': RUN, 'state': 'completed', 'download_artifacts': []}); return
            if req.url.endswith('?format=dataset-conflict'):
                r.fulfill(json={'evidence': 'recorded_at_failure', 'conflict': {'dataset': 'parts', 'record_id': '2262'}}); return
            assert req.url in [BASE+'?format=compact', BASE+'/'+RUN+'?format=compact'], req.url
            if mode == 'kick' and req.url == BASE+'/'+RUN+'?format=compact':
                r.abort('connectionreset'); return
            r.fulfill(json={'existing_run_found': True, 'run': state} if req.url == BASE+'?format=compact' else state); return
        body = req.post_data_json; action = 'kick' if req.url.endswith('/kick') else body.get('action'); actions.append(action)
        assert action in ['resume', 'snapshot_tick', 'pause_snapshot', 'kick'], actions
        if action == 'kick':
            assert body == {'attempt_id': ATT}
            start_reconciliation();r.fulfill(json={**state, 'kick_reason': 'recovery_started'});return
        if action == 'resume':
            assert body == {'action': 'resume', 'expected_snapshot_attempt': ATT};resumes += 1
            if mode == '419':
                r.fulfill(status=419, body='session expired');return
            if state['state'] == 'snapshot_paused':
                state.update(state='snapshot_running', snapshot_state='snapshot_running', next_action='snapshot_tick')
            else:
                start_reconciliation()
            if mode == 'lost_resume' and resumes == 1:
                r.abort('connectionreset');return
        elif action == 'pause_snapshot':
            state.update(state='snapshot_paused', snapshot_state='snapshot_paused', next_action='resume')
        else:
            assert body == {'action': 'snapshot_tick', 'expected_snapshot_attempt': ATT};ticks += 1
            if mode == 'network' and ticks == 1:
                r.abort('connectionreset');return
            if state['phase'] == 'snapshot_recovery_preflight':
                state.update(phase='snapshot_verify', dataset_recovery=None, dataset_generation=1, dataset_boundary_id=BOUNDARY,
                             recovery_strategy='adopt_complete_shards_then_reconcile', mutable_round=1)
            elif not rewritten:
                rewritten.append(2262); state.update(mutable_dirty_rows=1, mutable_dirty_shards=1, mutable_round=2, verification_processed=2)
            else:
                state.update(state='completed', snapshot_state='snapshot_completed', next_action='download', verification_processed=79957)
            assert state['processed'] == state['exported_records'] == 79957  # This mock never re-exports/zeros the completed capture.
            if mode == 'lost_tick' and ticks == 1:
                r.abort('connectionreset');return
        r.fulfill(json=state)

    context.route('**/*', route);page.goto(BASE)
    page.wait_for_function("document.querySelector('#state').textContent.includes('79957')")
    if mode == 'blocked':
        assert page.locator('#resume').is_disabled();assert not actions;context.close();print('PASS mutable parts browser', label);return
    page.locator('#diagnose-conflict').click()
    page.wait_for_function("document.querySelector('#dataset-conflict').textContent.includes('2262')")
    assert not actions
    if mode == 'reload':
        page.reload();page.wait_for_function("document.querySelector('#state').textContent.includes('79957')");assert not actions
    page.evaluate("document.querySelector('#resume').click();" + ("document.querySelector('#resume').click();" if mode == 'double_click' else ''))
    if mode == 'pause_reload':
        page.wait_for_function("document.querySelector('#state').textContent.includes('a42db372-cf85-4f46-99e7-ccacce2bc6c3')")
        page.locator('#pause').click()
        page.wait_for_function("document.querySelector('#state').textContent.includes('snapshot_paused')")
        page.reload();page.wait_for_function("document.querySelector('#state').textContent.includes('snapshot_paused')")
        page.locator('#resume').click()
    if mode == '419':
        page.wait_for_function("document.querySelector('#message').textContent.includes('SESSION_EXPIRED')");assert actions == ['resume'] and generations == (1 if pending else 0)
    else:
        page.wait_for_function("document.querySelector('#message').textContent.includes('Eksport gotowy')", timeout=20000)
        assert generations == 1 and rewritten == [2262]
        assert state['run_id'] == RUN and state['snapshot_attempt_id'] == ATT and state['dataset_boundary_id'] == BOUNDARY
        assert resumes == (0 if mode == 'kick' else 2 if mode == 'pause_reload' else 1)
        if mode != 'pause_reload':
            trace = page.locator('#resume-diagnostic').inner_text()
            assert 'reconciliation_started' in trace, trace
            if mode != 'kick':assert 'recoverable_mutable_dataset_conflict' in trace, trace
        assert 'Wyeksportowane rekordy: 79957 / 79957' in page.locator('#progress').inner_text()
        assert 'Dirty wiersze: 1' in page.locator('#progress').inner_text()
    assert not set(actions).intersection(['start', 'retry_snapshot', 'abort_snapshot'])
    context.close();print('PASS mutable parts browser', label)


with sync_playwright() as pw:
    browser = pw.chromium.launch(executable_path='/usr/bin/chromium', args=['--no-sandbox'])
    modes = ['normal', 'reload', 'double_click', 'lost_resume', 'network', 'lost_tick', 'pause_reload', 'kick', '419', 'blocked']
    for mode in modes + ['pending_'+m for m in modes]:
        case(browser, mode)
    browser.close()
