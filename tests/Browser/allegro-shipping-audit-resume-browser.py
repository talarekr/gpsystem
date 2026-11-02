"""Real Chromium regression; requires Python Playwright and a Chromium executable.
Run: python3 tests/Browser/allegro-shipping-audit-resume-browser.py
All responses are intercepted locally; no production or Allegro requests.
"""
import copy
import json
import os
from pathlib import Path
from playwright.sync_api import sync_playwright

RUN_ID = 'e2229ef9-4db3-4c85-ab01-e7931e705e7b'
URL = 'http://audit.test/admin/tools/allegro/shipping-rate-backfill'
HTML = (Path(__file__).parents[2] / 'resources/views/admin/tools/allegro/shipping-rate-backfill.blade.php').read_text()
FLAGS = dict(database_writes=False, allegro_writes=False, product_writes=False, marketplace_writes=False)


def scenario(browser, mode):
    run = dict(run_id=RUN_ID, state='running', phase='scan', scan_complete=False,
               processed_count=7173, total=7318, resolved=7014, provider_read_failed=159,
               remaining=145, retry_remaining=0, cursor=8690, max_part_id=8840,
               busy=False, lease_token_present=False, lease_expires_in=0, stale_lease=False,
               wait_seconds=0, blocked_reason=None, resume_reason='resumed', last_error=None,
               last_tick=dict(reason='product_completed', duration_ms=5, requested_items=1,
                              completed_items=1, provider_requests=0),
               provider_read_failed_by_reason=dict(offer_404=159), provider_offer_not_found_count=816,
               summary=dict(total_parts=7173, parts_with_one_active_offer=7014,
                            parts_without_active_offer=0, parts_with_multiple_active_offers=0,
                            empty_kurier_allegro=7173), catalog=[], local_options=[], preview=[], breakdown={})
    ids = [i for i in range(8691, 8841) if i not in [8695, 8700, 8710, 8720, 8730]]
    seen, resumes, errors = [], [], []
    polls = 3 if mode == 'status_poll' else 0
    failed = False
    context = browser.new_context()
    page = context.new_page()
    page.on('pageerror', lambda error: errors.append(str(error)))
    # Accelerate scan/backoff timers, preserving the actual fetch timeout/cancellation.
    page.add_init_script('''const nativeTimeout=window.setTimeout.bind(window);
        window.setTimeout=(fn,ms,...args)=>nativeTimeout(fn,ms===10000?ms:0,...args);''')

    if mode == 'lost_local_timer':
        page.add_init_script('''const schedulerTimeout=window.setTimeout.bind(window);let lost=false;
            window.setTimeout=(fn,ms,...args)=>{if(ms===0 && !lost){lost=true;return 987654;}
                return schedulerTimeout(fn,ms,...args);};''')

    def handle(route):
        nonlocal polls, failed
        request = route.request
        if '/runs' not in request.url:
            assert request.url.split('#')[0] == URL, request.url
            route.fulfill(content_type='text/html', body=HTML)
            return
        payload = request.post_data_json if request.method == 'POST' else {}
        action = (payload or {}).get('action')
        assert action != 'start', 'Must preserve the existing run'
        assert RUN_ID in request.url
        if action == 'resume':
            resumes.append(RUN_ID)
        if not action and polls:
            polls -= 1
            run['busy'] = polls > 0
        if action == 'batch':
            assert not run['busy']
            if ids:
                part = ids.pop(0)
                assert part not in seen
                seen.append(part)
                run['cursor'] = part
                run['processed_count'] += 1
                run['resolved'] += 1
                run['remaining'] -= 1
                if mode == 'fetch_failed' and not failed:
                    failed = True  # committed response lost; status must recover cursor
                    route.abort('failed')
                    return
            else:
                run.update(state='completed', scan_complete=True)
        result = copy.deepcopy(run)
        result['resume_allowed'] = result['state'] != 'completed'
        result['next_action'] = 'none' if result['state'] == 'completed' else 'poll_lease' if result['busy'] else 'scan_tick'
        route.fulfill(json=dict(**FLAGS, run=result, **{k: result[k] for k in ['resume_allowed', 'next_action', 'remaining', 'cursor', 'state']}))

    context.route('**/*', handle)
    page.goto(URL + '#run=' + RUN_ID)
    page.wait_for_function("document.querySelector('#progress').textContent.includes('7173')")
    if mode == 'reload':
        page.reload()
        page.wait_for_function("!document.querySelector('#resume').disabled")
    if mode == 'duplicate_click':
        page.evaluate("document.querySelector('#resume').click(); document.querySelector('#resume').click();")
    else:
        page.locator('#resume').click()
    if mode == 'lost_local_timer':
        page.wait_for_function("JSON.parse(document.querySelector('#progress').textContent).local_runner_active")
        assert run['processed_count'] == 7173
        assert page.locator('#resume').is_enabled()
        page.locator('#resume').click()
    page.wait_for_function("JSON.parse(document.querySelector('#progress').textContent).processed > 7173")
    page.wait_for_function("JSON.parse(document.querySelector('#progress').textContent).state === 'completed'", timeout=30000)
    final = json.loads(page.locator('#progress').inner_text())
    assert final['processed'] == 7318 and final['remaining'] == 0 and final['cursor'] == 8840
    assert final['run_id'] == RUN_ID and final['provider_read_failed'] == 159
    assert run['provider_offer_not_found_count'] == 816
    assert len(seen) == len(set(seen)) == 145
    assert resumes == [RUN_ID] * (2 if mode == 'lost_local_timer' else 1) and not errors, (resumes, errors)
    assert not final['local_runner_active'] and not final['local_tick_scheduled']
    context.close()
    print('PASS Chromium:', mode, '7173 -> 7318, same run, 145 unique products, 159 failures preserved')


with sync_playwright() as playwright:
    browser = playwright.chromium.launch(executable_path=os.environ.get('CHROMIUM_PATH', '/usr/bin/chromium'), headless=True, args=['--no-sandbox'])
    for mode in ['existing_run', 'reload', 'fetch_failed', 'status_poll', 'duplicate_click', 'lost_local_timer']:
        scenario(browser, mode)
    browser.close()
