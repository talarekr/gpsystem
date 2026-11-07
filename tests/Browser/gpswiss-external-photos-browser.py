"""Explicit metadata-only photo delivery for the existing run; HTTP is intercepted."""
import copy
import json
from pathlib import Path
from playwright.sync_api import sync_playwright

HTML = (Path(__file__).parents[2] / 'resources/views/admin/tools/gpswiss-migration-export.blade.php').read_text().replace("@json(route('admin.tools.gpswiss-migration-export', [], false))", "'/admin/tools/gpswiss-migration-export'")
BASE = 'https://audit.test/admin/tools/gpswiss-migration-export'
RUN = '8de6e27c-ad7c-470b-830b-b9ecee9446ba'
ATT = '9cb33c92-3f1e-4db0-b08d-052715aa5f4c'


def case(browser, mode):
    context = browser.new_context()
    context.add_init_script('const native=setTimeout;window.setTimeout=(fn,ms,...args)=>native(fn,ms>=250 && ms<=8000?5:ms,...args);')
    page = context.new_page()
    accepted = dict(accepted_by_operator=True, recorded_ids=['1937', '2262'])
    state = dict(run_id=RUN, snapshot_attempt_id=ATT, snapshot_mode='browser', schema_version='gpswiss-wystawczesc-3',
                 state='snapshot_completed', snapshot_state='snapshot_completed', phase='relation_audit',
                 current_dataset='relation_audit', cursor=dict(current_dataset_index=0, shard_index=213, row_index=0, probes=4897),
                 processed=1699, total=79957, exported_records=79957, verification_processed=2155, record_capture_total=79957,
                 next_action='snapshot_tick', busy=False, last_error=None, resume_allowed=True, external_photos_allowed=True,
                 consistency_exception=copy.deepcopy(accepted))
    initial = copy.deepcopy(state)
    selections = 0
    actions = []
    phases = []

    def route(r):
        nonlocal selections
        req = r.request
        if req.is_navigation_request():
            r.fulfill(content_type='text/html', body=HTML)
            return
        if req.method == 'GET':
            r.fulfill(json=copy.deepcopy(state))
            return
        payload = req.post_data_json
        action = payload['action']
        actions.append(action)
        assert action in ['use_external_photos', 'resume', 'snapshot_tick', 'tick']
        if action == 'use_external_photos':
            selections += 1
            assert selections == 1
            assert payload['confirm_external_photos'] is True and payload['expected_snapshot_attempt'] == ATT
            if mode == '419':
                r.fulfill(status=419, json={'reason_code': 'SESSION_EXPIRED'})
                return
            state.update(photo_delivery=dict(mode='external_archive'), external_photos_allowed=False)
            if mode == 'lost_response':
                r.abort('connectionreset')
                return
        elif action in ['snapshot_tick', 'tick']:
            assert state['photo_delivery']['mode'] == 'external_archive'
            phases.append(state['phase'])
            if state['phase'] == 'relation_audit':
                state.update(phase='photo_inventory', current_dataset='photo_inventory', cursor=dict(dataset=0, shard=0, row=0), processed=0)
            elif state['phase'] == 'photo_inventory':
                state.update(state='archive', phase='archive_index', current_dataset='archive_index', next_action='tick')
            elif state['phase'] == 'archive_index':
                state.update(state='completed', phase='completed', next_action='download', download_artifacts=[])
            else:
                raise AssertionError(state)
        r.fulfill(json=copy.deepcopy(state))

    context.route('**/*', route)
    page.goto(BASE)
    page.wait_for_function("document.querySelector('#state').textContent.includes('1699')")
    assert page.locator('#use-external-photos').is_disabled()
    page.locator('#external-photo-confirm').check()
    page.evaluate("document.querySelector('#use-external-photos').click();" + ("document.querySelector('#use-external-photos').click();" if mode == 'double_click' else ''))
    if mode == '419':
        page.wait_for_function("document.querySelector('#message').textContent.includes('SESSION_EXPIRED')")
        assert state == initial and actions == ['use_external_photos']
    else:
        page.wait_for_function("document.querySelector('#message').textContent.includes('Kopiowanie oryginałów wyłączone')", timeout=10000)
        assert state['cursor'] == initial['cursor'] and state['processed'] == 1699
        assert actions == ['use_external_photos']
        assert 'osobny ZIP' in page.locator('#progress').inner_text()
        if mode == 'reload':
            page.reload()
            page.wait_for_function("document.querySelector('#progress').textContent.includes('osobny ZIP')")
        page.locator('#resume').click()
        page.wait_for_function("JSON.parse(document.querySelector('#state').textContent).state==='completed'", timeout=10000)
        assert phases == ['relation_audit', 'photo_inventory', 'archive_index']
        assert state['run_id'] == RUN and state['snapshot_attempt_id'] == ATT
        assert state['consistency_exception'] == accepted and state['exported_records'] == 79957
    context.close()
    print('PASS external photos', mode, flush=True)


with sync_playwright() as p:
    browser = p.chromium.launch(executable_path='/usr/bin/chromium', headless=True, args=['--no-sandbox'])
    for scenario in ['normal', 'lost_response', 'reload', 'double_click', '419']:
        case(browser, scenario)
    browser.close()
