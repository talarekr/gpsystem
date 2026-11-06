"""Production checkpoint after #1515: manual continuation without a working status GET.
HTTP is intercepted locally; no production access or export data is used.
"""
import json
import time
from pathlib import Path
from playwright.sync_api import sync_playwright

HTML = (Path(__file__).parents[2] / 'resources/views/admin/tools/gpswiss-migration-export.blade.php').read_text().replace(
    "@json(route('admin.tools.gpswiss-migration-export', [], false))", "'/admin/tools/gpswiss-migration-export'")
BASE = 'https://audit.test/admin/tools/gpswiss-migration-export'
RUN = '8de6e27c-ad7c-470b-830b-b9ecee9446ba'
ATT = '9cb33c92-3f1e-4db0-b08d-052715aa5f4c'


def case(browser, mode):
    context = browser.new_context()
    page = context.new_page()
    calls, rows, status_counts = [], [], []
    kicks = 0
    state = dict(run_id=RUN, snapshot_attempt_id=ATT, snapshot_mode='browser', state='snapshot_running',
                 snapshot_state='snapshot_running', phase='snapshot_export', current_dataset='marketplace_listings',
                 processed=50428, total=79957, remaining=29529, cursor={'id': 15640}, next_action='snapshot_tick',
                 busy=False, last_error=None, last_tick=dict(reason_code='tick_completed', records_attempted=5,
                 records_completed=5, duration_ms=104, cursor_before={'id': 15635}, cursor_after={'id': 15640}))
    if mode == 'no_proof':
        state['last_tick']['reason_code'] = 'server_exception'

    def advance():
        cursor = state['cursor']['id']
        rows.extend(range(cursor + 1, cursor + 6))
        state.update(processed=state['processed'] + 5, remaining=state['remaining'] - 5, cursor={'id': cursor + 5})
        if state['processed'] >= 50438:
            state.update(state='completed', snapshot_state='snapshot_completed', next_action='download')

    def route(r):
        nonlocal kicks
        req = r.request
        if req.is_navigation_request():
            r.fulfill(content_type='text/html', body=HTML.replace('isStatus?10000:45000', 'isStatus?30:45000') if mode == 'timeout' else HTML)
            return
        body = req.post_data_json if req.method == 'POST' else None
        calls.append((req.method, req.url, body))
        if req.url == BASE + '?format=compact':
            r.fulfill(json={'existing_run_found': True, 'run': state})
            return
        if req.url.endswith('?format=artifacts'):
            r.fulfill(json={'run_id': RUN, 'state': 'completed', 'download_artifacts': []})
            return
        if req.method == 'GET':
            assert req.url == BASE + '/' + RUN + '?format=compact', req.url
            status_counts.append(1)
            if mode == '419':
                r.fulfill(status=419, body='session')
            elif mode == '503':
                r.fulfill(status=503, body='temporary')
            elif mode == 'malformed':
                r.fulfill(content_type='application/json', body='{broken')
            elif mode == 'timeout':
                time.sleep(.1)
                r.fulfill(json=state)
            else:
                r.abort('connectionreset')
            return
        assert req.headers.get('x-csrf-token') is not None
        if req.url.endswith('/kick'):
            assert body == {'attempt_id': ATT}, body  # No client cursor, action or boundary supplied.
            kicks += 1
            if mode == 'busy':
                r.fulfill(json={**state, 'busy': True, 'next_action': 'wait', 'kick_reason': 'busy', 'blocked_reason': 'busy'})
                return
            if mode == 'mismatch':
                r.fulfill(status=409, json={'reason_code': 'attempt_mismatch'})
                return
            if mode in ['completed', 'paused', 'failed']:
                reason = {'completed': 'completed', 'paused': 'paused', 'failed': 'run_failed'}[mode]
                state.update(state={'completed': 'completed', 'paused': 'snapshot_paused', 'failed': 'snapshot_failed'}[mode],
                             next_action='download' if mode == 'completed' else 'blocked')
                r.fulfill(json={**state, 'kick_reason': reason, 'blocked_reason': reason})
                return
            advance()
            if mode == 'lost_response' and kicks == 1:
                r.abort('connectionreset')
                return
            r.fulfill(json={**state, 'kick_reason': 'tick_completed', 'blocked_reason': None})
            return
        assert req.url == BASE + '/' + RUN and body == {'action': 'snapshot_tick', 'expected_snapshot_attempt': ATT}, calls
        advance()
        r.fulfill(json=state)

    context.route('**/*', route)
    page.goto(BASE)
    page.wait_for_function("document.querySelector('#state').textContent.includes('50428')")
    if mode == 'reload':
        page.reload()
        page.wait_for_function("document.querySelector('#message').textContent.includes('status_fetch_retry_exhausted')", timeout=15000)
        assert kicks == 0  # Restore only reads; operator must explicitly Resume.
    page.evaluate("document.querySelector('#resume').click();" + ("document.querySelector('#resume').click();" if mode == 'double_click' else ''))
    if mode == 'lost_response':
        page.wait_for_function("JSON.parse(document.querySelector('#resume-diagnostic').textContent).events.some(e=>e.event==='blocked' && e.blocked_reason)", timeout=20000)
        assert kicks == 1 and state['processed'] == 50433
        page.locator('#resume').click()
    if mode in ['419', 'no_proof', 'busy', 'mismatch', 'paused', 'failed']:
        expected = {'419': 'SESSION_EXPIRED', 'no_proof': 'status_fetch_retry_exhausted', 'busy': 'status_fetch_retry_exhausted',
                    'mismatch': 'attempt_mismatch', 'paused': 'paused', 'failed': 'run_failed'}[mode]
        page.wait_for_function("document.querySelector('#message').textContent.includes(" + json.dumps(expected) + ")", timeout=20000)
        assert not rows
        assert kicks == (0 if mode in ['419', 'no_proof'] else 1), calls
    else:
        page.wait_for_function("document.querySelector('#message').textContent.includes('Eksport gotowy')", timeout=20000)
        assert kicks == (2 if mode == 'lost_response' else 1)
        if mode != 'completed':
            assert state['processed'] == 50438 and state['cursor'] == {'id': 15650}
            assert rows == list(range(15641, 15651)) and len(rows) == len(set(rows))
        else:
            assert not rows
        trace = json.loads(page.locator('#resume-diagnostic').inner_text())
        names = [e['event'] for e in trace['events']]
        for event in ['resume_started', 'local_retry_state_reset', 'status_fetch_retry_exhausted', 'fallback_kick']:
            assert event in names, names
        assert 'status_loaded' not in names, names
        if mode != 'completed':
            assert 'tick_completed' in names
        diagnostics = json.loads(page.locator('#request-diagnostic').inner_text())
        attempts = diagnostics['status_attempts']
        assert [d['attempt'] for d in attempts] == [1, 2, 3, 4], attempts
        for d in attempts:
            for key in ['http_status', 'duration_ms', 'response_bytes', 'content_length', 'content_type', 'reason_code', 'browser_online']:
                assert key in d, d
    assert state['run_id'] == RUN and state['snapshot_attempt_id'] == ATT
    assert all(not (body and body.get('action') in ['start', 'retry_snapshot', 'resume']) for _, _, body in calls)
    context.close()
    print('PASS resume kick', mode)


with sync_playwright() as pw:
    browser = pw.chromium.launch(executable_path='/usr/bin/chromium', args=['--no-sandbox'])
    for mode in ['network', '503', 'malformed', 'timeout', 'double_click', 'reload', 'lost_response', 'busy', 'completed', 'paused', 'failed', 'mismatch', '419', 'no_proof']:
        case(browser, mode)
    browser.close()
