"""Snapshot recovery in Chromium; every HTTP request is intercepted, no production access."""
import copy
import os
from pathlib import Path
from playwright.sync_api import sync_playwright


def request_url(req):
    # Normalize only the new GET selector for legacy workflow assertions.
    url = req.url
    if req.method == 'GET' and url.endswith('?format=compact') and url.split('?')[0].split('/')[-1] != 'gpswiss-migration-export':
        return url.split('?')[0]
    return url

HTML = (Path(__file__).parents[2] / 'resources/views/admin/tools/gpswiss-migration-export.blade.php').read_text()
HTML = HTML.replace("@json(route('admin.tools.gpswiss-migration-export', [], false))", "'/admin/tools/gpswiss-migration-export'")
HTML = HTML.replace('{{ csrf_token() }}', 'snapshot-csrf')
BASE = 'https://audit.test/admin/tools/gpswiss-migration-export'
RUN = '8de6e27c-ad7c-470b-830b-b9ecee9446ba'
OLD = '11111111-1111-4111-8111-111111111111'
NEW = '22222222-2222-4222-8222-222222222222'


def run(browser, mode):
    context = browser.new_context()
    page = context.new_page()
    state = dict(run_id=RUN, state='snapshot_failed', phase='snapshot_failed', snapshot_state='snapshot_failed',
                 processed_images=0, processed=0, remaining=0, verification_remaining=0, busy=False,
                 snapshot_process_active=False, resume_allowed=False, blocked_reason='snapshot_process_interrupted',
                 snapshot_retry_allowed=True, snapshot_abort_allowed=True, snapshot_attempt_id=OLD,
                 snapshot_file_exists=False, snapshot_tasks_file_exists=False, owner_match=True,
                 artifacts_present=False, last_error='snapshot_process_interrupted')
    requests, errors = [], []
    rejected = False
    polls = 0
    snapshot_captures = 0
    if mode == 'live_worker':
        state.update(state='snapshot_running', phase='snapshot_running', snapshot_state='snapshot_running',
                     busy=True, snapshot_process_active=True, snapshot_retry_allowed=False, snapshot_abort_allowed=False)
    if mode == 'final_artifact':
        state.update(snapshot_file_exists=True, snapshot_retry_allowed=False)
    page.on('pageerror', lambda error: errors.append(str(error)))

    def route(r):
        nonlocal rejected, polls, snapshot_captures
        request = r.request
        if request.is_navigation_request():
            assert request_url(request) == BASE
            r.fulfill(content_type='text/html', body=HTML)
            return
        payload = request.post_data_json if request.method == 'POST' else {}
        action = (payload or {}).get('action')
        requests.append((request_url(request), action))
        assert request_url(request) in [BASE+'?format=compact', BASE+'/'+RUN], request_url(request)
        assert action in [None, 'retry_snapshot', 'abort_snapshot', 'snapshot_tick', 'resume', 'tick'], 'Never auto-start another run or call an integration'
        if request_url(request).endswith('?format=compact'):
            r.fulfill(json={'existing_run_found':True, 'run_id':RUN, 'run':copy.deepcopy(state), **copy.deepcopy(state)})
            return
        if action == 'retry_snapshot':
            assert payload['confirm_restart'] is True and payload['expected_snapshot_attempt'] == OLD
            if mode == 'session_419' and not rejected:
                rejected = True
                r.fulfill(status=419, json={'reason_code':'SESSION_EXPIRED'})
                return
            assert state['snapshot_attempt_id'] == OLD, 'Double click must never restart a pending worker'
            state.update(state='snapshot_pending', phase='snapshot_pending', snapshot_state='snapshot_pending',
                         snapshot_attempt_id=NEW, snapshot_retry_allowed=False, snapshot_abort_allowed=True,
                         snapshot_mode='browser', next_action='snapshot_tick', launcher_status='worker_launch_unavailable',
                         last_error=None, blocked_reason=None)
            if mode in ['lost_response', 'timeout']:
                r.abort('timedout' if mode == 'timeout' else 'connectionreset')
                return
        elif action == 'abort_snapshot':
            assert payload['confirm_abort'] is True and payload['expected_snapshot_attempt'] == OLD
            state.update(state='snapshot_aborted', phase='snapshot_aborted', snapshot_state='snapshot_aborted',
                         snapshot_retry_allowed=False, snapshot_abort_allowed=False)
        elif action == 'snapshot_tick':
            assert payload['expected_snapshot_attempt'] == NEW
            polls += 1
            if polls == 1:
                state.update(state='snapshot_running', phase='snapshot_running', snapshot_state='snapshot_running',
                             busy=False, snapshot_process_active=False, snapshot_abort_allowed=False)
            elif polls == 2:
                snapshot_captures += 1
                state.update(state='photos', phase='photos', snapshot_state='snapshot_completed',
                             busy=False, snapshot_process_active=False, snapshot_abort_allowed=False,
                             snapshot_file_exists=True, snapshot_tasks_file_exists=True, resume_allowed=True,
                             artifacts_present=True, remaining=2, total_images=2, last_error=None, blocked_reason=None, next_action='tick')
        elif action == 'tick':
            assert state['snapshot_state'] == 'snapshot_completed', 'No photo work before atomic snapshot completion'
            state['processed_images'] += 1
            state['processed'] = state['processed_images']
            state['remaining'] -= 1
            if not state['remaining']:
                state.update(state='completed', phase='completed', resume_allowed=False, next_action='download',
                             download_artifacts=[dict(key='manifest', name='archive-manifest.json', kind='manifest', bytes=1, sha256='a'*64)])
        r.fulfill(json=copy.deepcopy(state))

    context.route('**/*', route)
    page.goto(BASE)
    page.wait_for_function("document.querySelector('#state').textContent.includes('"+RUN+"')")
    assert page.evaluate('location.hash') == '#run='+RUN
    assert page.locator('#start').is_disabled()
    assert not any(action for _, action in requests), 'Loading panel never retries or creates a snapshot'
    if mode in ['live_worker', 'final_artifact']:
        assert page.locator('#retry-snapshot').is_disabled()
        before = len(requests)
        page.wait_for_timeout(350)
        assert len(requests) == before
    elif mode == 'abort':
        page.locator('#snapshot-confirm-abort').check()
        page.locator('#abort-snapshot').click()
        page.wait_for_function("!document.querySelector('#start').disabled")
        assert state['snapshot_state'] == 'snapshot_aborted'
        assert [action for _, action in requests if action] == ['abort_snapshot']
    else:
        assert page.locator('#snapshot-actions').is_visible()
        assert page.locator('#retry-snapshot').is_disabled(), 'Retry needs explicit operator confirmation'
        page.locator('#snapshot-confirm-retry').check()
        page.evaluate("document.querySelector('#retry-snapshot').click(); document.querySelector('#retry-snapshot').click();")
        if mode == 'session_419':
            page.wait_for_function("document.querySelector('#message').textContent.includes('SESSION_EXPIRED')")
            before = len(requests)
            page.wait_for_timeout(350)
            assert len(requests) == before
            assert page.locator('#login').is_visible()
            assert page.locator('#retry-snapshot').is_disabled()
            assert state['snapshot_attempt_id'] == OLD
            page.reload()
            page.wait_for_function("document.querySelector('#state').textContent.includes('"+RUN+"')")
            page.locator('#snapshot-confirm-retry').check()
            page.locator('#retry-snapshot').click()
        page.wait_for_function("document.querySelectorAll('#download-artifacts a').length === 1", timeout=30000)
        assert state['processed'] == 2
        assert snapshot_captures == 1
        assert sum(action == 'snapshot_tick' for _, action in requests) == 2
        assert sum(action == 'tick' for _, action in requests) == 2
        assert page.locator('#resume').is_disabled()
        retries = [i for i, (_, action) in enumerate(requests) if action == 'retry_snapshot']
        assert len(retries) == (2 if mode == 'session_419' else 1), requests
        if mode in ['lost_response', 'timeout']:
            assert requests[retries[0]+1] == (BASE+'/'+RUN, None), 'Lost retry response must read existing attempt, never POST retry again'
    assert all(action != 'start' for _, action in requests)
    assert state['run_id'] == RUN
    assert not errors, errors
    print('PASS Chromium snapshot recovery:', mode)
    context.close()


with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=os.environ.get('CHROMIUM_PATH', '/usr/bin/chromium'), headless=True, args=['--no-sandbox'])
    for mode in ['retry', 'lost_response', 'timeout', 'session_419', 'no_cli_required', 'abort', 'live_worker', 'final_artifact']:
        run(browser, mode)
    browser.close()
