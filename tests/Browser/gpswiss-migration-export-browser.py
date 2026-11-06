"""Real Chromium UI regression with every request intercepted locally; no production access."""
import json
import os
import re
from pathlib import Path
from playwright.sync_api import sync_playwright


def request_url(req):
    # Normalize only the new GET selector for legacy workflow assertions.
    url = req.url
    if req.method == 'GET' and url.endswith('?format=compact') and url.split('?')[0].split('/')[-1] != 'gpswiss-migration-export':
        return url.split('?')[0]
    return url

HTML = (Path(__file__).parents[2] / 'resources/views/admin/tools/gpswiss-migration-export.blade.php').read_text()
HTML = HTML.replace("@json(route('admin.tools.gpswiss-migration-export', [], false))", json.dumps('/admin/tools/gpswiss-migration-export'))
HTML = HTML.replace('{{ csrf_token() }}', 'fixture-csrf')
BASE = 'https://audit.test/admin/tools/gpswiss-migration-export'

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=os.environ.get('CHROMIUM_PATH', '/usr/bin/chromium'), headless=True, args=['--no-sandbox'])
    for lose_start in [False, True]:
        context = browser.new_context()
        page = context.new_page()
        state = dict(run_id=None, state='photos', phase='photos', processed=0, processed_images=0, total_images=2,
                     remaining=2, last_error=None, resume_allowed=True, owner_match=True, artifacts_present=True,
                     blocked_reason=None, busy=False)
        calls, errors, run_ids, requests = [], [], [], []
        lost = False
        page.on('pageerror', lambda e: errors.append(str(e)))

        def route(r):
            global lost
            request = r.request
            if request_url(request) == BASE:
                r.fulfill(content_type='text/html', body=HTML)
                return
            if request_url(request) == BASE+'?format=compact':
                assert request.method == 'GET'
                r.fulfill(json={'existing_run_found':bool(state['run_id']), 'run_id':state['run_id'], 'run':state if state['run_id'] else None})
                return
            assert request_url(request).startswith(BASE+'/'), request_url(request)
            run_id = request_url(request)[len(BASE)+1:]
            assert re.fullmatch('[a-f0-9-]{36}', run_id), run_id
            payload = request.post_data_json if request.method == 'POST' else {}
            action = (payload or {}).get('action')
            assert action in [None, 'start', 'resume', 'tick']
            calls.append(action)
            requests.append((request_url(request), action))
            run_ids.append(run_id)
            assert not state['run_id'] or state['run_id'] == run_id
            state['run_id'] = run_id
            if action == 'start' and lose_start and not lost:
                lost = True
                r.abort('failed')  # snapshot committed, response lost
                return
            if action == 'tick':
                state['processed_images'] += 1
                state['processed'] = state['processed_images']
                state['remaining'] -= 1
                if state['processed_images'] == 2:
                    state.update(state='completed', phase='completed', resume_allowed=False, blocked_reason='export_already_completed')
            r.fulfill(json=state)

        context.route('**/*', route)
        page.goto(BASE)
        page.wait_for_function("!document.querySelector('#start').disabled")
        page.evaluate("document.querySelector('#start').click(); document.querySelector('#start').click();")
        page.wait_for_function("!document.querySelector('#full').hidden")
        assert calls.count('start') == 1 and calls.count('tick') == 2, calls
        assert len(set(run_ids)) == 1, run_ids
        assert page.evaluate('location.hash') == '#run='+state['run_id']
        if lose_start:
            start_index = next(i for i, (_, action) in enumerate(requests) if action == 'start')
            assert requests[start_index+1] == (BASE+'/'+state['run_id'], None), 'Lost start response must recover committed snapshot status'
        assert page.locator('#resume').is_disabled()
        for kind in ['sample', 'full']:
            assert page.locator('#'+kind).evaluate('(a)=>a.href') == BASE+'/'+state['run_id']+'?download='+kind
        assert not errors, errors
        print('PASS Chromium: private sample/full links, single start, same-ID recovery, lost_start='+str(lose_start))
        context.close()
    browser.close()
