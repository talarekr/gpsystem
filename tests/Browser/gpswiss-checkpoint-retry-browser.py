"""Recorded technical checkpoint, locally mocked HTTP only; no source/export files touched."""
import hashlib
import json
import sys
import time
from pathlib import Path
from playwright.sync_api import sync_playwright

HTML = (Path(__file__).parents[2] / 'resources/views/admin/tools/gpswiss-migration-export.blade.php').read_text().replace(
    "@json(route('admin.tools.gpswiss-migration-export', [], false))", "'/admin/tools/gpswiss-migration-export'")
BASE = 'https://audit.test/admin/tools/gpswiss-migration-export'
RUN = '8de6e27c-ad7c-470b-830b-b9ecee9446ba'
ATT = '9cb33c92-3f1e-4db0-b08d-052715aa5f4c'
BOUNDARY = 'a42db372-cf85-4f46-99e7-ccacce2bc6c3'


def case(browser, mode):
    phase, failure = mode.split('_', 1)
    context = browser.new_context()
    # Accelerate backoff only; retain request abort timers and the normal scheduling delay.
    context.add_init_script('const native=setTimeout;window.setTimeout=(fn,ms,...args)=>native(fn,ms>=250 && ms<=8000?5:ms,...args);')
    page = context.new_page()
    calls = 0; commits = []; polls_after_failure = 0; allow = False; failure_pending = False; actions = []
    state = dict(run_id=RUN, snapshot_attempt_id=ATT, snapshot_mode='browser', state='snapshot_running', snapshot_state='snapshot_running',
                 phase='snapshot_recovery_preflight' if phase == 'preflight' else 'snapshot_'+phase, current_dataset='parts',
                 processed=79957 if phase != 'export' else 2528, total=79957, exported_records=79957 if phase != 'export' else 2528,
                 verification_processed=2254, cursor={'id': 8926 if phase == 'preflight' else 2257}, next_action='snapshot_tick',
                 busy=False, resume_allowed=True, blocked_reason=None, last_error=None, dataset_generation=1, dataset_boundary_id=BOUNDARY,
                 mutable_round=1, checkpoint_fingerprint={'ino': 1, 'mtime': 1}, last_tick={'reason_code': 'tick_completed'})
    if phase == 'preflight':
        state['dataset_recovery'] = dict(dataset='parts', previous_generation=0, new_generation=1, dataset_boundary_id=BOUNDARY,
                                       strategy='adopt_complete_shards_then_reconcile', preflight_shard=40, preflight_rows=313,
                                       preflight_total_shards=1296, preflight_chain=hashlib.sha256(b'fixture-40').hexdigest())
    stalled = failure in ['stalled', 'stalled_reload', 'fingerprint', 'metadata']
    if stalled: state['dataset_recovery']['preflight_shard'] = 45
    start_chain = state.get('dataset_recovery', {}).get('preflight_chain')

    def checkpoint():
        return state['dataset_recovery']['preflight_shard'] if phase == 'preflight' else state['cursor']['id']

    def commit():
        before = checkpoint()
        if phase == 'preflight':
            work = state['dataset_recovery']; work['preflight_shard'] += 5; work['preflight_rows'] += 40
            work['preflight_chain'] = hashlib.sha256(('fixture-'+str(work['preflight_shard'])).encode()).hexdigest()
            state['last_tick'] = dict(action='artifact_preflight', reason_code='tick_completed', dataset_generation=1,
                                     preflight_shard_before=before, preflight_shard_after=checkpoint(), artifacts_checked=5,
                                     backend_exception_class=None, duration_ms=268)
        else:
            state['cursor'] = {'id': before+5}
            key = 'processed' if phase == 'export' else 'verification_processed'; state[key] += 5
            if phase == 'export': state['exported_records'] += 5
        commits.append((before, checkpoint()))
        assert len({a for a, b in commits}) == len(commits), 'Duplicate committed checkpoint'
        assert state['run_id'] == RUN and state['snapshot_attempt_id'] == ATT
        assert state['dataset_generation'] == 1 and state['dataset_boundary_id'] == BOUNDARY

    def route(r):
        nonlocal calls, polls_after_failure, failure_pending
        req = r.request
        if req.is_navigation_request():
            r.fulfill(content_type='text/html', body=HTML.replace('isStatus?10000:45000', 'isStatus?10000:50') if failure == 'timeout' else HTML); return
        if req.url.endswith('?format=artifacts'):
            r.fulfill(json={'run_id': RUN, 'state': 'completed', 'download_artifacts': []}); return
        if req.method == 'GET':
            assert req.url in [BASE+'?format=compact', BASE+'/'+RUN+'?format=compact']
            if failure_pending:
                polls_after_failure += 1
                if failure == 'polls_fail': r.fulfill(status=503, body='temporary status failure'); return
                failure_pending = False
                if failure == 'changed_attempt': state['snapshot_attempt_id'] = '11111111-1111-4111-8111-111111111111'
                if failure == 'changed_boundary': state['dataset_recovery'].update(new_generation=2, dataset_boundary_id='11111111-1111-4111-8111-111111111111')
                if failure == 'corrupt': state.update(state='snapshot_failed', next_action='blocked', blocked_reason='snapshot_shard_checksum_mismatch', last_error='snapshot_shard_checksum_mismatch')
                if failure == 'busy': state.update(busy=True, next_action='wait')
            elif state['busy']: state.update(busy=False, next_action='snapshot_tick')
            state['checkpoint_fingerprint']['mtime'] += 1  # Not evidence of semantic progress.
            response_state = json.loads(json.dumps(state))
            if failure == 'metadata' and calls == 0:
                # First status omits identity metadata; learning it later is not work progress.
                for key in ['dataset_generation', 'dataset_boundary_id']: response_state.pop(key)
                for key in ['new_generation', 'dataset_boundary_id']: response_state['dataset_recovery'].pop(key)
            r.fulfill(json={'existing_run_found': True, 'run': response_state} if req.url == BASE+'?format=compact' else response_state); return
        body = req.post_data_json; actions.append(body['action'])
        assert body == {'action': 'snapshot_tick', 'expected_snapshot_attempt': ATT}, body
        assert not state['busy']; calls += 1
        if failure == '419': r.fulfill(status=419, body='expired'); return
        if not allow and (stalled or failure == 'progress_then_stall' and calls > 1):
            failure_pending = True; r.fulfill(status=500, body='temporary no commit'); return
        if failure == 'fifth_commit' and calls < 5:
            failure_pending = True; r.fulfill(status=500, body='temporary no commit'); return
        if failure in ['changed_attempt', 'changed_boundary', 'corrupt', 'polls_fail']:
            failure_pending = True; r.fulfill(status=500, body='temporary'); return
        commit()
        if not allow and calls <= (5 if failure == 'fifth_commit' else 7):
            failure_pending = True
            if failure == 'network': r.abort('connectionreset'); return
            if failure == 'timeout': time.sleep(.08); r.fulfill(json=state); return
            if failure == 'malformed': r.fulfill(status=200, body='{broken'); return
            if failure in ['502', '503', '504']: r.fulfill(status=int(failure), body='temporary proxy failure'); return
            r.fulfill(status=500, json={'reason_code': 'post_commit_response_failed', 'backend_exception_class': 'RuntimeException',
                                      'message': 'SECRET_RESPONSE_MESSAGE', 'stack': 'SECRET_STACK',
                                      'tick_diagnostic': {'action': 'artifact_preflight', 'backend_exception_class': 'RuntimeException', 'message': 'SECRET_NESTED_MESSAGE'}}); return
        state.update(state='completed', next_action='download')
        r.fulfill(json=state)

    context.route('**/*', route); page.goto(BASE)
    page.wait_for_function("document.querySelector('#state').textContent.includes('79957')")
    page.locator('#resume').click()
    if stalled or failure == 'progress_then_stall':
        page.wait_for_function("document.querySelector('#message').textContent.includes('retry_exhausted')", timeout=15000)
        assert calls == (6 if failure == 'progress_then_stall' else 5), (mode, calls)
        assert polls_after_failure == calls, (mode, polls_after_failure, calls)  # Poll even the fifth failed request.
        assert checkpoint() == 45
        if not commits: assert state['dataset_recovery']['preflight_chain'] == start_chain
        if failure == 'stalled_reload':
            page.reload(); page.wait_for_function("document.querySelector('#state').textContent.includes('79957')")
        allow = True
        page.evaluate("document.querySelector('#resume').click();document.querySelector('#resume').click();")
        page.wait_for_function("document.querySelector('#message').textContent.includes('Eksport gotowy')", timeout=10000)
        assert commits[-1] == (45, 50)
        if failure != 'stalled_reload':
            last = json.loads(page.locator('#request-diagnostic').inner_text())['last_operation_failure']
            assert last['retry_number'] == 5 and last['http_status'] == 500
            assert last['checkpoint_before']['preflight_shard'] == last['checkpoint_after']['preflight_shard'] == 45
            assert last['checkpoint_progress_detected'] is False  # Manual resume retains historical diagnostics only.
    elif failure in ['changed_attempt', 'changed_boundary', 'corrupt', '419', 'polls_fail']:
        reason = {'changed_attempt': 'run_or_attempt_changed', 'changed_boundary': 'dataset_boundary_changed', 'corrupt': 'snapshot_shard_checksum_mismatch',
                  '419': 'SESSION_EXPIRED', 'polls_fail': 'status_fetch_retry_exhausted'}[failure]
        page.wait_for_function('document.querySelector("#message").textContent.includes('+json.dumps(reason)+')', timeout=15000)
        assert calls == 1 and not commits
    elif failure == 'fifth_commit':
        page.wait_for_function("document.querySelector('#message').textContent.includes('Eksport gotowy')", timeout=15000)
        assert calls == 6 and polls_after_failure == 5 and commits == [(40, 45), (45, 50)]
        failures = json.loads(page.locator('#request-diagnostic').inner_text())['recent_failures']
        assert [f['retry_number'] for f in failures] == [1, 2, 3, 4, 5]
        assert [f['checkpoint_progress_detected'] for f in failures] == [False, False, False, False, True]
    else:
        page.wait_for_function("document.querySelector('#message').textContent.includes('Eksport gotowy')", timeout=15000)
        assert calls == 8 and polls_after_failure == 7, (mode, calls, polls_after_failure)
        assert len(commits) == 8
        trace = page.locator('#resume-diagnostic').inner_text()
        assert 'checkpoint_progress_detected' in trace and 'retry_counter_reset' in trace
        assert 'retry_exhausted' not in page.locator('#message').inner_text()
        diag = json.loads(page.locator('#request-diagnostic').inner_text())
        failures = [f for f in diag['recent_failures'] if f['request_kind'] == 'operation']
        assert len(failures) == 7
        assert all(f['retry_number'] == 1 and f['checkpoint_progress_detected'] for f in failures)
        if phase == 'preflight':
            assert failures[0]['checkpoint_before']['preflight_shard'] == 40
            assert failures[0]['checkpoint_after']['preflight_shard'] == 45
        if failure == '500':
            last = diag['last_operation_failure']; assert last['http_status'] == 500 and last['action'] == 'snapshot_tick'
            assert last['endpoint'] == '/admin/tools/gpswiss-migration-export/'+RUN
            assert last['duration_ms'] >= 0 and last['response_bytes'] > 0
            assert last['server_reason_code'] == 'post_commit_response_failed' and last['backend_exception_class'] == 'RuntimeException'
    assert not set(actions).intersection(['start', 'resume', 'retry_snapshot', 'kick'])
    assert 'SECRET_' not in page.locator('#request-diagnostic').inner_text()
    context.close(); print('PASS checkpoint retry', mode, flush=True)


with sync_playwright() as pw:
    browser = pw.chromium.launch(executable_path='/usr/bin/chromium', args=['--no-sandbox'])
    modes = ['preflight_'+m for m in ['500', '502', '503', '504', 'network', 'timeout', 'malformed', 'stalled', 'stalled_reload',
             'progress_then_stall', 'fifth_commit', 'fingerprint', 'metadata', 'busy', 'changed_attempt', 'changed_boundary', 'corrupt', '419', 'polls_fail']]+['export_500', 'verify_500']
    for mode in (sys.argv[1:] or modes): case(browser, mode)
    browser.close()
