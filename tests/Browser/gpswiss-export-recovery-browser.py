"""Real Chromium regression. Intercepted local state only; no production export or API calls.
python3 tests/Browser/gpswiss-export-recovery-browser.py
"""
import copy
import os
from pathlib import Path
from playwright.sync_api import sync_playwright

HTML = (Path(__file__).parents[2] / 'resources/views/admin/tools/gpswiss-migration-export.blade.php').read_text()
HTML = HTML.replace("@json(route('admin.tools.gpswiss-migration-export', [], false))", "'/admin/tools/gpswiss-migration-export'")
BASE = 'https://audit.test/admin/tools/gpswiss-migration-export'
ID = 'e2229ef9-4db3-4c85-ab01-e7931e705e7b'
PHANTOM = '00000000-0000-4000-8000-000000000001'


def run(browser, mode):
    context = browser.new_context()
    page = context.new_page()
    state = dict(run_id=ID, state='photos', phase='photos', processed=0, processed_images=0, remaining=3,
                 total_images=3, current_item=None, last_success=None, last_error=None, resume_allowed=True,
                 blocked_reason=None, owner_match=True, artifacts_present=True, busy=mode == 'initially_busy')
    if mode in ['checkpoint_missing_after_load', 'interrupted_snapshot_after_load']:
        state.update(processed=2, processed_images=2, remaining=1)
    requests, processed, errors = [], [], []
    expired = False
    lost = False
    busy_polls = 0
    page.on('pageerror', lambda e: errors.append(str(e)))

    def route(r):
        nonlocal expired, lost, busy_polls
        req = r.request
        if req.is_navigation_request() and req.url in [BASE, BASE+'/'+ID]:
            r.fulfill(content_type='text/html', body=HTML.replace('{{ csrf_token() }}', 'fresh-csrf'))
            return
        payload = req.post_data_json if req.method == 'POST' else {}
        action = (payload or {}).get('action')
        requests.append((req.url, action))
        assert action != 'start', 'Existing-run recovery must never create a snapshot'
        assert action in [None, 'resume', 'tick'], action
        if req.url == BASE+'?format=status':
            found = mode != 'phantom_without_existing'
            returned = copy.deepcopy(state)
            if mode == 'checkpoint_missing_after_load' and sum('?format=status' in url for url, _ in requests) > 1:
                returned = dict(run_id=ID, state='blocked', phase='unknown', processed=None, remaining=None,
                                last_error='checkpoint_missing', blocked_reason='checkpoint_missing', resume_allowed=False,
                                owner_match=True, artifacts_present=False, busy=False)
            r.fulfill(json={**(returned if found else {}), 'existing_run_found':found, 'run_id':ID if found else None, 'run':returned if found else None})
            return
        if PHANTOM in req.url:
            r.fulfill(status=404, json=dict(reason_code='run_not_found', run_id=PHANTOM))
            return
        assert req.url == BASE+'/'+ID, req.url
        if mode == 'owner_mismatch':
            r.fulfill(status=404, json=dict(reason_code='run_owner_mismatch', owner_match=False))
            return
        if mode == 'checkpoint_missing_after_load':
            assert action is None, 'Missing checkpoint must block before resume or tick'
            r.fulfill(status=404, json=dict(reason_code='checkpoint_missing', run_id=ID))
            return
        if mode == 'interrupted_snapshot_after_load':
            assert action is None, 'Interrupted snapshot must block before resume or tick'
            state.update(state='snapshot', phase='snapshot', resume_allowed=False, blocked_reason='snapshot_in_progress_or_interrupted')
        if mode == 'initially_busy' and action is None:
            busy_polls += 1
            if busy_polls >= 2:
                state['busy'] = False
        if action:
            assert not state['busy'], 'Busy run must poll until idle before resume verification or tick'
        if action == 'tick':
            if mode == 'expired_session' and state['processed'] == 1 and not expired:
                expired = True
                r.fulfill(status=419, json=dict(reason_code='SESSION_EXPIRED'))
                return
            if state['processed'] < 3:
                state['processed'] += 1
                state['processed_images'] = state['processed']
                state['remaining'] -= 1
                state['last_success'] = '2026-11-06T12:00:00+01:00'
                processed.append(state['processed'])
            if state['remaining'] == 0:
                state.update(state='completed', phase='completed', resume_allowed=False, blocked_reason='export_already_completed')
            if mode == 'lost_tick_response' and not lost:
                lost = True
                r.abort('failed')
                return
        r.fulfill(json=copy.deepcopy(state))

    context.route('**/*', route)
    fragment = '#run='+PHANTOM if mode.startswith('phantom') else '#run='+ID if mode in ['owner_mismatch', 'nested_panel_url'] else ''
    page.goto(BASE+('/'+ID if mode == 'nested_panel_url' else '')+fragment)
    if mode == 'owner_mismatch':
        page.wait_for_function("document.querySelector('#message').textContent.includes('run_owner_mismatch')")
        assert not any(action for _, action in requests), requests
        assert not any('?format=status' in url for url, _ in requests), requests
    elif mode == 'phantom_without_existing':
        page.wait_for_function("document.querySelector('#message').textContent.includes('run_not_found')")
        page.wait_for_timeout(300)
        assert not any(action for _, action in requests), requests
        assert any('?format=status' in url for url, _ in requests)
    else:
        page.wait_for_function("document.querySelector('#state').textContent.includes('"+ID+"')")
        assert page.locator('#start').is_disabled()
        assert page.evaluate('location.hash') == '#run='+ID
        page.evaluate("document.querySelector('#resume').click(); document.querySelector('#resume').click();")
        if mode in ['checkpoint_missing_after_load', 'interrupted_snapshot_after_load']:
            page.wait_for_function("document.querySelector('#message').textContent.includes('Wznowienie zablokowane')")
            before = len(requests)
            page.wait_for_timeout(1300)
            assert len(requests) == before, 'Idle blocked snapshot/checkpoint must stop, not poll forever'
            assert not any(action for _, action in requests), requests
            assert page.locator('#resume').is_disabled()
            assert page.locator('#start').is_disabled()
            assert not errors, errors
            print('PASS Chromium recovery:', mode)
            context.close()
            return
        if mode == 'expired_session':
            page.wait_for_function("document.querySelector('#message').textContent.includes('SESSION_EXPIRED')")
            before = len(requests)
            page.wait_for_timeout(350)
            assert len(requests) == before, ('419 must stop local loop without endless POST retries', requests[before:])
            assert state['processed'] == 1
            assert page.locator('#login').is_visible()
            assert page.locator('#resume').is_disabled()
            page.reload()
            page.wait_for_function("document.querySelector('#state').textContent.includes('"+ID+"')")
            page.locator('#resume').click()
        page.wait_for_function("!document.querySelector('#full').hidden", timeout=30000)
        assert processed == [1, 2, 3], processed
        assert state['run_id'] == ID
        assert all(action is None or url.endswith('/'+ID) for url, action in requests)
        assert not any(action == 'start' for _, action in requests)
        assert sum(action == 'resume' for _, action in requests) == (2 if mode == 'expired_session' else 1), requests
        if mode == 'lost_tick_response':
            lost_at = next(i for i, (_, action) in enumerate(requests) if action == 'tick')
            assert requests[lost_at+1] == (BASE+'/'+ID, None), 'Poll the committed checkpoint before retrying a lost tick'
        assert page.locator('#resume').is_disabled()
    assert not errors, errors
    print('PASS Chromium recovery:', mode)
    context.close()


with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=os.environ.get('CHROMIUM_PATH', '/usr/bin/chromium'), headless=True, args=['--no-sandbox'])
    for mode in ['lost_hash', 'expired_session', 'lost_tick_response', 'phantom_recovers_existing', 'phantom_without_existing', 'owner_mismatch', 'nested_panel_url', 'initially_busy', 'checkpoint_missing_after_load', 'interrupted_snapshot_after_load']:
        run(browser, mode)
    browser.close()
