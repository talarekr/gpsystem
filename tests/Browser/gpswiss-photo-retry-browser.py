"""Explicit failed-photo retry UI; intercepted local HTTP only."""
import copy
import os
from pathlib import Path
from playwright.sync_api import sync_playwright

HTML = (Path(__file__).parents[2] / 'resources/views/admin/tools/gpswiss-migration-export.blade.php').read_text()
HTML = HTML.replace("@json(route('admin.tools.gpswiss-migration-export', [], false))", "'/admin/tools/gpswiss-migration-export'")
HTML = HTML.replace('{{ csrf_token() }}', 'photo-csrf')
BASE = 'https://audit.test/admin/tools/gpswiss-migration-export'
RUN = '8de6e27c-ad7c-470b-830b-b9ecee9446ba'
ATTEMPT = '7bd47b3f-7600-4dd1-aaa3-5c3d4b595c87'
GENERATION = '22222222-2222-4222-8222-222222222222'


def run(browser, mode):
    context = browser.new_context()
    page = context.new_page()
    artifacts = [dict(key='migration-manifest', name='migration-manifest.json', kind='manifest', bytes=123, sha256='a'*64)]
    state = dict(run_id=RUN, schema_version='gpswiss-wystawczesc-3', snapshot_mode='browser', snapshot_state='snapshot_completed',
                 snapshot_attempt_id=ATTEMPT, state='completed', phase='completed', next_action='download', busy=False,
                 last_error=None, photo_retry_allowed=True, processed=3, total=3, remaining=0,
                 photo_results={'COPIED_VERIFIED':2, 'COPY_FAILED':1}, download_artifacts=artifacts)
    if mode == 'queued_initial_copy':
        state.update(state='photos', phase='photos', next_action='tick', processed=2, remaining=1)
    requests, errors, copied = [], [], []
    rejected = False
    stage = 0
    page.on('pageerror', lambda error: errors.append(str(error)))

    def route(r):
        nonlocal rejected, stage
        request = r.request
        if request.is_navigation_request():
            assert request.url == BASE
            r.fulfill(content_type='text/html', body=HTML)
            return
        payload = request.post_data_json if request.method == 'POST' else {}
        action = (payload or {}).get('action')
        requests.append((request.url, action))
        assert request.url in [BASE+'?format=status', BASE+'/'+RUN]
        assert action in [None, 'retry_photos', 'resume', 'tick'], 'No snapshot restart or integration calls during photo retry'
        if request.url.endswith('?format=status'):
            r.fulfill(json={'existing_run_found':True, 'run':copy.deepcopy(state), **copy.deepcopy(state)})
            return
        if action == 'retry_photos':
            assert payload['confirm_retry_photos'] is True
            if mode == 'session_419' and not rejected:
                rejected = True
                r.fulfill(status=419, json={'reason_code':'SESSION_EXPIRED'})
                return
            assert stage == 0, 'Only one accepted retry generation'
            stage = 1
            state.update(state='photos', phase='photos_retry_setup', next_action='tick',
                         photo_retry_allowed=False, photo_retry_generation=GENERATION, processed=0, total=1)
            if mode == 'queued_initial_copy':
                stage = -1
                state.update(phase='photos', photo_retry_requested=True)
            if mode == 'lost_response':
                r.abort('connectionreset')
                return
        elif action == 'tick':
            if stage == -1:
                stage = 1
                state.update(phase='photos_retry_setup', photo_retry_requested=False)
            elif stage == 1:
                stage = 2
                state.update(phase='photos_retry_inventory', processed=0)
            elif stage == 2:
                stage = 3
                state.update(phase='photos_retry', processed=0)
            elif stage == 3:
                copied.append('failed-image-3')
                stage = 4
                state.update(state='archive', phase='archive_sample_index', processed=1,
                             photo_results={'COPIED_VERIFIED':3})
            elif stage == 4:
                stage = 5
                state.update(state='completed', phase='completed', processed=3, total=3, next_action='download', download_artifacts=artifacts)
            else:
                raise AssertionError('No duplicate retry or archive tick after completion')
        r.fulfill(json=copy.deepcopy(state))

    context.route('**/*', route)
    page.goto(BASE)
    page.wait_for_function("!document.querySelector('#photo-retry-actions').hidden")
    assert page.locator('#retry-photos').is_disabled()
    assert not any(action for _, action in requests), 'Photo retry requires an explicit operator action'
    page.locator('#photo-confirm-retry').check()
    page.evaluate("document.querySelector('#retry-photos').click(); document.querySelector('#retry-photos').click();")
    if mode == 'session_419':
        page.wait_for_function("document.querySelector('#message').textContent.includes('SESSION_EXPIRED')")
        before = len(requests)
        page.wait_for_timeout(350)
        assert len(requests) == before
        assert stage == 0 and page.locator('#login').is_visible()
        assert page.locator('#retry-photos').is_disabled()
        page.reload()
        page.wait_for_function("!document.querySelector('#photo-retry-actions').hidden")
        page.locator('#photo-confirm-retry').check()
        page.locator('#retry-photos').click()
    page.wait_for_function("document.querySelector('#state').textContent.includes('photos_retry_inventory') || document.querySelector('#state').textContent.includes('photos_retry') || document.querySelector('#state').textContent.includes('archive_sample_index')")
    page.wait_for_function("document.querySelector('#state').textContent.includes('completed') && document.querySelector('#state').textContent.includes('"+GENERATION+"') && document.querySelector('#download-artifacts').hidden === false")
    assert stage == 5
    assert copied == ['failed-image-3'], 'Only the failed photo is copied; good receipts are not scheduled'
    assert state['run_id'] == RUN and state['snapshot_attempt_id'] == ATTEMPT
    assert state['photo_retry_generation'] == GENERATION
    retries = [i for i, (_, action) in enumerate(requests) if action == 'retry_photos']
    assert len(retries) == (2 if mode == 'session_419' else 1)
    if mode == 'lost_response':
        assert requests[retries[0]+1] == (BASE+'/'+RUN, None), 'Read committed generation after lost response, never resend retry'
    assert page.locator('#photo-retry-actions').is_hidden()
    assert not errors, errors
    print('PASS Chromium photo retry:', mode)
    context.close()


with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=os.environ.get('CHROMIUM_PATH', '/usr/bin/chromium'), headless=True, args=['--no-sandbox'])
    for mode in ['explicit_double_click', 'lost_response', 'session_419', 'queued_initial_copy']:
        run(browser, mode)
    browser.close()
