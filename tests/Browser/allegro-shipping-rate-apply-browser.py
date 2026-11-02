"""Chromium UI regression; all HTTP is intercepted locally. No production APPLY.
Requires Python Playwright; run with python3 tests/Browser/allegro-shipping-rate-apply-browser.py.
"""
import os
from pathlib import Path
from playwright.sync_api import sync_playwright

RUN_ID = 'e2229ef9-4db3-4c85-ab01-e7931e705e7b'
TOKEN = 'APPLY-ALLEGRO-SHIPPING-RATE-BACKFILL'
DIGEST = 'a' * 64
URL = 'http://audit.test/admin/tools/allegro/shipping-rate-backfill'
HTML = (Path(__file__).parents[2] / 'resources/views/admin/tools/allegro/shipping-rate-backfill.blade.php').read_text()
FLAGS = dict(database_writes=True, product_field_writes=False, allegro_writes=False, marketplace_writes=False, provider_calls=False)


def scenario(browser, mode):
    context = browser.new_context()
    page = context.new_page()
    calls, errors = [], []
    page.on('pageerror', lambda error: errors.append(str(error)))

    def handle(route):
        request = route.request
        if request.url == URL:
            route.fulfill(content_type='text/html', body=HTML)
        elif request.url == URL + '/runs':
            route.fulfill(json=dict(run=None, allegro_writes=False, product_writes=False, marketplace_writes=False))
        else:
            assert request.url == URL + '/apply', 'Unexpected endpoint: ' + request.url
            assert request.method == 'POST'
            payload = request.post_data_json
            calls.append(payload)
            assert payload['run_id'] == RUN_ID
            if payload['action'] == 'prepare':
                count = 4608 if mode == 'count_mismatch' else 4609
                route.fulfill(json=dict(**FLAGS, ok=True, run_id=RUN_ID,
                    plan_id='00000000-0000-4000-8000-000000000001', plan_digest=DIGEST,
                    eligible_count=count, expected_count=4609, count_difference=count-4609,
                    apply_allowed=count == 4609, summary=dict(already_correct=287, current_value_conflicts=250),
                    blocked=7318-count, excluded=dict(BLOCK_PROVIDER_READ=dict(count=159, examples=[7159])), last_report=None))
            else:
                assert payload['action'] == 'apply'
                assert payload['plan_digest'] == DIGEST and payload['confirm'] == TOKEN
                assert payload['plan_id'] == '00000000-0000-4000-8000-000000000001'
                if mode == 'source_changed':
                    route.fulfill(status=409, json=dict(**FLAGS, ok=False, reason_code='source_changed', eligible_count=4608, expected_count=4609, count_difference=-1))
                else:
                    route.fulfill(json=dict(ok=True, report=dict(planned=4609, updated=4608,
                        already_applied=0, skipped_concurrent_change=1, blocked=2709, failed=0, examples=dict(skipped_concurrent_change=[5]))))

    context.route('**/*', handle)
    page.goto(URL)
    execute = page.locator('#apply-execute')
    confirm = page.locator('#apply-confirm')
    assert execute.is_disabled()
    confirm.fill(TOKEN)
    assert execute.is_disabled()  # token alone cannot apply without a prepared plan
    page.locator('#apply-plan').click()
    page.wait_for_function("document.querySelector('#apply-plan-details').textContent.includes('plan_digest')")
    details = page.locator('#apply-plan-details').inner_text()
    assert '287' in details and '250' in details and '159' in details
    assert len(calls) == 1 and calls[0]['action'] == 'prepare'
    if mode == 'count_mismatch':
        assert execute.is_disabled() and '"count_difference": -1' in details
    else:
        confirm.fill('WRONG')
        assert execute.is_disabled()
        confirm.fill(TOKEN)
        assert execute.is_enabled()
        # The first click sets the local gate before fetch; the second cannot submit twice.
        page.evaluate("document.querySelector('#apply-execute').click(); document.querySelector('#apply-execute').click();")
        page.wait_for_function("document.querySelector('#apply-confirm').value === ''")
        assert len(calls) == 2, calls
        assert execute.is_disabled()
        if mode == 'source_changed':
            assert 'source_changed' in page.locator('#apply-report').inner_text()
            assert '"count_difference": -1' in page.locator('#apply-report').inner_text()
        else:
            assert '"skipped_concurrent_change": 1' in page.locator('#apply-report').inner_text()
        # Preparing/recovering a plan never auto-runs APPLY.
        page.locator('#apply-plan').click()
        page.wait_for_function("!document.querySelector('#apply-plan').disabled")
        assert len(calls) == 3 and calls[-1]['action'] == 'prepare'
        assert execute.is_disabled()
    assert not errors, errors
    context.close()
    print('PASS Chromium APPLY:', mode, '(intercepted local responses only)')


with sync_playwright() as playwright:
    browser = playwright.chromium.launch(executable_path=os.environ.get('CHROMIUM_PATH', '/usr/bin/chromium'), headless=True, args=['--no-sandbox'])
    for mode in ['confirmation', 'count_mismatch', 'source_changed']:
        scenario(browser, mode)
    browser.close()
