"""Real Chromium UI regression with every request intercepted locally; no production access."""
import json
import os
import re
from pathlib import Path
from playwright.sync_api import sync_playwright

HTML = (Path(__file__).parents[2] / 'resources/views/admin/tools/gpswiss-migration-export.blade.php').read_text()
BASE = 'https://audit.test/admin/tools/gpswiss-migration-export'

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=os.environ.get('CHROMIUM_PATH', '/usr/bin/chromium'), headless=True, args=['--no-sandbox'])
    for lose_start in [False, True]:
        context = browser.new_context()
        page = context.new_page()
        state = dict(run_id=None, state='photos', processed_images=0, total_images=2, last_error=None)
        calls, errors, run_ids = [], [], []
        lost = False
        page.on('pageerror', lambda e: errors.append(str(e)))

        def route(r):
            global lost
            request = r.request
            if request.url == BASE:
                r.fulfill(content_type='text/html', body=HTML)
                return
            assert request.url.startswith(BASE+'/'), request.url
            run_id = request.url[len(BASE)+1:]
            assert re.fullmatch('[a-f0-9-]{36}', run_id), run_id
            payload = request.post_data_json if request.method == 'POST' else {}
            action = (payload or {}).get('action')
            assert action in [None, 'start', 'tick']
            calls.append(action)
            run_ids.append(run_id)
            state['run_id'] = run_id
            if action == 'start' and lose_start and not lost:
                lost = True
                r.abort('failed')  # snapshot committed, response lost
                return
            if action == 'tick':
                state['processed_images'] += 1
                if state['processed_images'] == 2:
                    state['state'] = 'completed'
            r.fulfill(json=state)

        context.route('**/*', route)
        page.goto(BASE)
        page.evaluate("document.querySelector('#start').click(); document.querySelector('#start').click();")
        if lose_start:
            page.wait_for_function("document.querySelector('#message').textContent.includes('przerwany')")
            saved_hash = page.evaluate('location.hash')
            assert saved_hash.startswith('#run=')
            page.reload()
            page.wait_for_function("document.querySelector('#state').textContent.includes('photos')")
            assert page.evaluate('location.hash') == saved_hash
            page.locator('#resume').click()
        page.wait_for_function("!document.querySelector('#full').hidden")
        assert calls.count('start') == 1 and calls.count('tick') == 2, calls
        assert len(set(run_ids)) == 1, run_ids
        for kind in ['sample', 'full']:
            assert page.locator('#'+kind).evaluate('(a)=>a.href') == BASE+'/'+state['run_id']+'?download='+kind
        assert not errors, errors
        print('PASS Chromium: private sample/full links, single start, same-ID recovery, lost_start='+str(lose_start))
        context.close()
    browser.close()
