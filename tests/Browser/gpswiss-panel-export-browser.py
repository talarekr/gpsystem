"""Browser-only v3 workflow. Every request is intercepted locally; no shell or production access."""
import copy
import os
from pathlib import Path
from playwright.sync_api import sync_playwright

HTML = (Path(__file__).parents[2] / 'resources/views/admin/tools/gpswiss-migration-export.blade.php').read_text()
HTML = HTML.replace("@json(route('admin.tools.gpswiss-migration-export', [], false))", "'/admin/tools/gpswiss-migration-export'")
HTML = HTML.replace('{{ csrf_token() }}', 'panel-csrf')
BASE = 'https://audit.test/admin/tools/gpswiss-migration-export'
RUN = '8de6e27c-ad7c-470b-830b-b9ecee9446ba'
OLD = '7bd47b3f-7600-4dd1-aaa3-5c3d4b595c87'
NEW = '22222222-2222-4222-8222-222222222222'


def run(browser, mode):
    context = browser.new_context()
    page = context.new_page()
    state = dict(run_id=RUN, schema_version='gpswiss-wystawczesc-2', snapshot_mode='worker',
                 state='snapshot_pending', phase='snapshot_pending', snapshot_state='snapshot_pending',
                 snapshot_attempt_id=OLD, snapshot_retry_allowed=True, snapshot_abort_allowed=True,
                 snapshot_process_active=False, busy=False, resume_allowed=False, next_action='blocked',
                 launcher_status='worker_launch_unavailable', processed=0, total=0, remaining=0,
                 current_dataset=None, cursor=None, last_error=None, last_success=None)
    requests, errors, steps = [], [], []
    position = 0
    media = 0
    rejected = False
    lost = False
    page.on('pageerror', lambda error: errors.append(str(error)))

    def route(r):
        nonlocal position, media, rejected, lost
        request = r.request
        if request.is_navigation_request():
            assert request.url == BASE
            r.fulfill(content_type='text/html', body=HTML)
            return
        payload = request.post_data_json if request.method == 'POST' else {}
        action = (payload or {}).get('action')
        requests.append((request.url, action))
        assert request.url in [BASE+'?format=status', BASE+'/'+RUN], request.url
        assert action in [None, 'retry_snapshot', 'snapshot_tick', 'pause_snapshot', 'resume', 'tick'], 'No new run, launcher or integration action'
        if request.url.endswith('?format=status'):
            r.fulfill(json={'existing_run_found':True, 'run':copy.deepcopy(state), **copy.deepcopy(state)})
            return
        if action == 'retry_snapshot':
            assert payload['confirm_restart'] is True and payload['expected_snapshot_attempt'] == OLD
            assert state['snapshot_attempt_id'] == OLD, 'Double click must not create another generation'
            state.update(schema_version='gpswiss-wystawczesc-3', snapshot_mode='browser',
                         snapshot_attempt_id=NEW, next_action='snapshot_tick', launcher_status=None,
                         snapshot_retry_allowed=False, snapshot_abort_allowed=False,
                         snapshot_state='snapshot_running', state='snapshot_running', phase='snapshot_boundaries',
                         current_dataset='parts', processed=0, total=4, cursor=0)
        elif action == 'pause_snapshot':
            state.update(state='snapshot_paused', snapshot_state='snapshot_paused', next_action='resume')
        elif action == 'resume':
            if state['state'] == 'snapshot_paused':
                state.update(state='snapshot_running', snapshot_state='snapshot_running', next_action='snapshot_tick')
        elif action == 'snapshot_tick':
            assert state['snapshot_mode'] == 'browser'
            assert payload['expected_snapshot_attempt'] == NEW
            if mode == 'session_419' and position == 1 and not rejected:
                rejected = True
                r.fulfill(status=419, json={'reason_code':'SESSION_EXPIRED'})
                return
            position += 1
            steps.append(position)
            progression = [
                dict(phase='snapshot_export', current_dataset='parts', processed=2, total=4, cursor=2),
                dict(phase='snapshot_export', current_dataset='parts', processed=4, total=4, cursor=4),
                dict(phase='snapshot_export', current_dataset='cars', processed=0, total=2, cursor=0),
                dict(phase='snapshot_export', current_dataset='cars', processed=2, total=2, cursor=2),
                dict(phase='snapshot_verify', current_dataset='orders', processed=0, total=1, cursor=0),
                dict(phase='snapshot_verify', current_dataset='orders', processed=1, total=1, cursor=1),
                dict(phase='photo_inventory', current_dataset='part_images', processed=0, total=3, cursor=0, snapshot_state='snapshot_completed'),
                dict(state='photos', phase='photos', current_dataset=None, processed=0, total=3, cursor=0, next_action='tick', resume_allowed=True),
            ]
            state.update(progression[position-1])
            if mode == 'lost_fetch' and position == 1 and not lost:
                lost = True
                r.abort('connectionreset')
                return
        elif action == 'tick':
            assert position == 8, 'Photos/archives start only after every snapshot dataset'
            media += 1
            progression = [
                dict(processed=2, remaining=1),
                dict(state='archive', phase='archive', processed=0, total=2, remaining=2),
                dict(processed=1, remaining=1),
                dict(state='completed', phase='completed', processed=2, remaining=0, next_action='download', resume_allowed=False,
                     download_artifacts=[dict(key='manifest', name='archive-manifest.json', kind='manifest', bytes=123, sha256='a'*64),
                                         dict(key='volume-000001', name='volume-000001.zip', kind='archive', bytes=456, sha256='b'*64)]),
            ]
            state.update(progression[media-1])
        r.fulfill(json=copy.deepcopy(state))

    context.route('**/*', route)
    page.goto(BASE)
    page.wait_for_function("document.querySelector('#state').textContent.includes('"+RUN+"')")
    assert page.locator('#start').is_disabled()
    assert not any(action for _, action in requests), 'Opening legacy blocked run never retries automatically'
    page.locator('#snapshot-confirm-retry').check()
    page.evaluate("document.querySelector('#retry-snapshot').click(); document.querySelector('#retry-snapshot').click();")
    if mode == 'session_419':
        page.wait_for_function("document.querySelector('#message').textContent.includes('SESSION_EXPIRED')")
        before = len(requests)
        page.wait_for_timeout(350)
        assert len(requests) == before
        assert page.locator('#login').is_visible()
        assert position == 1
        page.reload()
        page.wait_for_function("document.querySelector('#state').textContent.includes('"+NEW+"')")
        page.locator('#resume').click()
    elif mode in ['reload', 'pause', 'stop_local']:
        page.wait_for_function("document.querySelector('#state').textContent.includes('snapshot_export')")
        if mode == 'pause':
            page.locator('#pause').click()
            page.wait_for_function("document.querySelector('#state').textContent.includes('snapshot_paused')")
            assert sum(action == 'pause_snapshot' for _, action in requests) == 1
        elif mode == 'stop_local':
            page.locator('#stop-local').click()
            page.wait_for_function("!document.querySelector('#resume').disabled")
            assert not any(action == 'pause_snapshot' for _, action in requests)
        before = len(requests)
        if mode != 'reload':
            page.wait_for_timeout(350)
            assert len(requests) == before
        page.reload()
        page.wait_for_function("document.querySelector('#state').textContent.includes('"+NEW+"')")
        page.locator('#resume').click()
    page.wait_for_function("document.querySelectorAll('#download-artifacts a').length === 2", timeout=30000)
    assert steps == list(range(1, 9)), steps
    assert media == 4
    assert sum(action == 'retry_snapshot' for _, action in requests) == 1
    assert all(action is None or url == BASE+'/'+RUN for url, action in requests)
    assert state['run_id'] == RUN and state['snapshot_attempt_id'] == NEW
    assert page.evaluate('location.hash') == '#run='+RUN
    assert page.locator('#sample').is_hidden() and page.locator('#full').is_hidden()
    links = page.locator('#download-artifacts a').evaluate_all('(links)=>links.map(link=>link.href)')
    assert links == [BASE+'/'+RUN+'?download=artifact&artifact=manifest', BASE+'/'+RUN+'?download=artifact&artifact=volume-000001'], links
    if mode == 'lost_fetch':
        first = next(i for i, (_, action) in enumerate(requests) if action == 'snapshot_tick')
        assert requests[first+1] == (BASE+'/'+RUN, None)
    assert not errors, errors
    print('PASS Chromium browser-only export:', mode)
    context.close()


with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=os.environ.get('CHROMIUM_PATH', '/usr/bin/chromium'), headless=True, args=['--no-sandbox'])
    for mode in ['counter_resets', 'lost_fetch', 'session_419', 'reload', 'pause', 'stop_local']:
        run(browser, mode)
    browser.close()
