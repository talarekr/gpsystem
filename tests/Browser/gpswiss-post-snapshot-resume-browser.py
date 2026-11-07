"""Exact post-snapshot checkpoint; all HTTP is intercepted, including KICK and failures."""
import copy
import json
import sys
import time
from pathlib import Path
from playwright.sync_api import sync_playwright

VIEW = Path(sys.argv[1]) if len(sys.argv) > 1 else Path(__file__).parents[2] / 'resources/views/admin/tools/gpswiss-migration-export.blade.php'
HTML = VIEW.read_text().replace("@json(route('admin.tools.gpswiss-migration-export', [], false))", "'/admin/tools/gpswiss-migration-export'")
BASE = 'https://audit.test/admin/tools/gpswiss-migration-export'
RUN = '8de6e27c-ad7c-470b-830b-b9ecee9446ba'
ATT = '9cb33c92-3f1e-4db0-b08d-052715aa5f4c'
STAGES = [('snapshot_completed', 'relation_audit', 'snapshot_tick'), ('snapshot_completed', 'photo_inventory', 'snapshot_tick'),
          ('photos', 'photos', 'tick'), ('archive', 'archive_index', 'tick'), ('archive', 'archive_sample', 'tick'),
          ('archive', 'archive_sample_volumes', 'tick'), ('archive', 'archive_volumes', 'tick'), ('archive', 'archive_catalog', 'tick')]


def case(browser, mode, start=0):
    context = browser.new_context()
    context.add_init_script('const native=setTimeout;window.setTimeout=(fn,ms,...args)=>native(fn,ms>=250 && ms<=8000 && ms!==400?5:ms,...args);')
    page = context.new_page()
    accepted = dict(accepted_by_operator=True, recorded_ids=['1937', '2262'], generation_at_acceptance=1)
    state = dict(run_id=RUN, snapshot_attempt_id=ATT, snapshot_mode='browser', schema_version='gpswiss-wystawczesc-3',
                 snapshot_state='snapshot_completed', current_dataset='relation_audit', processed=45983, total=79957,
                 exported_records=79957, record_capture_total=79957, verification_processed=2155,
                 cursor=dict(shard_index=2239, row_index=0, probes=67253, current_dataset_index=35),
                 busy=False, resume_allowed=True, blocked_reason=None, last_error=None,
                 consistency_exception=copy.deepcopy(accepted), last_tick=dict(reason_code='tick_completed'))
    phase = start
    actions, records, ticks = [], [], []
    failures = kicks = gets = 0
    allow = False
    reloaded = False

    def stage(index):
        if index == len(STAGES):
            state.update(state='completed', phase='completed', next_action='download', download_artifacts=[])
        else:
            root, name, action = STAGES[index]
            state.update(state=root, phase=name, current_dataset=name, next_action=action)
    stage(start)
    if mode == 'busy':
        state.update(busy=True, next_action='wait')

    def advance():
        nonlocal phase
        ticks.append(state['phase'])
        if phase == 0 and state['processed'] == 45983:
            assert not records
            records.extend(range(2240, 2245))
            state.update(processed=45988, cursor=dict(shard_index=2240, row_index=0, probes=67258, current_dataset_index=35))
        else:
            phase += 1
            stage(phase)
            if phase < len(STAGES):
                state['cursor'] = dict(step=phase)

    def route(r):
        nonlocal failures, kicks, gets
        req = r.request
        if req.is_navigation_request():
            html = HTML.replace('isStatus?10000:45000', 'isStatus?10000:30') if mode == 'timeout' else HTML
            if mode == 'manual_each_stage':
                html = html.replace('await wait(150);', 'await wait(400);')
            r.fulfill(content_type='text/html', body=html)
            return
        if req.method == 'GET':
            gets += 1
            if req.url.endswith('?format=artifacts'):
                r.fulfill(json=dict(download_artifacts=[]))
                return
            if mode in ['kick', 'reload_kick'] and (reloaded or mode == 'kick' and gets > 1):
                r.abort('connectionreset')
                return
            if mode == '419' and gets > 1:
                r.fulfill(status=419, json={'reason_code': 'SESSION_EXPIRED'})
                return
            if mode == 'blocked' and gets > 1:
                state.update(next_action='blocked', resume_allowed=False, blocked_reason='relation_shard_missing_or_changed')
            if mode == 'busy' and gets > 2:
                state.update(busy=False, next_action='snapshot_tick')
            r.fulfill(json=copy.deepcopy(state))
            return
        payload = req.post_data_json
        action = payload['action'] if not req.url.endswith('/kick') else 'kick'
        actions.append(action)
        assert action in ['snapshot_tick', 'tick', 'kick'], 'Healthy post-snapshot work must not POST resume/start/reset: ' + action
        if action == 'kick':
            kicks += 1
            assert payload == {'attempt_id': ATT}
            advance()
            r.fulfill(json={**copy.deepcopy(state), 'kick_reason': 'tick_completed'})
            return
        if action == 'snapshot_tick':
            assert payload == {'action': action, 'expected_snapshot_attempt': ATT}
        assert state['next_action'] == action
        if mode == 'exhausted' and not allow or mode in ['503', 'malformed', 'timeout'] and failures == 0:
            failures += 1
            if mode == '503':
                r.fulfill(status=503, json={'reason_code': 'temporarily_unavailable'})
            elif mode == 'malformed':
                r.fulfill(content_type='application/json', body='{broken')
            elif mode == 'timeout':
                time.sleep(.1)
                r.fulfill(json=copy.deepcopy(state))
            else:
                r.abort('connectionreset')
            return
        advance()
        if mode == 'lost_response' and failures == 0:
            failures += 1
            r.abort('connectionreset')
            return
        assert state['consistency_exception'] == accepted
        r.fulfill(json=copy.deepcopy(state))

    context.route('**/*', route)
    page.goto(BASE)
    page.wait_for_function("document.querySelector('#state').textContent.includes('45983')")
    if mode in ['reload', 'reload_kick']:
        reloaded = True
        page.reload()
        page.wait_for_function("!document.querySelector('#resume').disabled", timeout=15000)
    page.evaluate("document.querySelector('#resume').click();" + ("document.querySelector('#resume').click();" if mode == 'double_click' else ''))
    if mode == 'exhausted':
        page.wait_for_function("document.querySelector('#message').textContent.includes('retry_exhausted')", timeout=15000)
        assert failures == 5 and not records
        allow = True
        page.locator('#resume').click()
    if mode in ['419', 'blocked']:
        expected = 'SESSION_EXPIRED' if mode == '419' else 'relation_shard_missing_or_changed'
        page.wait_for_function("document.querySelector('#message').textContent.includes('" + expected + "')")
        assert not ticks
        if mode == 'blocked':
            diagnostic = json.loads(page.locator('#resume-diagnostic').inner_text())
            assert diagnostic['phase'] == 'relation_audit' and diagnostic['runner_decision'] == 'blocked'
            assert diagnostic['state'] == 'snapshot_completed' and diagnostic['resume_allowed'] is False
    else:
        if mode == 'manual_each_stage':
            for _, name, _ in STAGES[1:]:
                page.wait_for_function("JSON.parse(document.querySelector('#state').textContent).phase==='" + name + "'")
                page.locator('#stop-local').click()
                page.locator('#resume').click()
        page.wait_for_function("JSON.parse(document.querySelector('#state').textContent).state==='completed'", timeout=20000)
        assert records == (list(range(2240, 2245)) if start == 0 else [])
        assert len(records) == len(set(records))
        assert state['run_id'] == RUN and state['snapshot_attempt_id'] == ATT
        assert state['consistency_exception'] == accepted and state['exported_records'] == 79957 and state['verification_processed'] == 2155
        if mode in ['kick', 'reload_kick']:
            assert kicks == 1
        trace = page.locator('#resume-diagnostic').inner_text()
        if mode not in ['manual_each_stage']:
            for event in ['resume_started', 'local_retry_state_reset', 'tick_scheduled']:
                assert event in trace
        if mode == 'lost_response':
            assert 'checkpoint_progress_detected' in trace and 'retry_counter_reset' in trace
    context.close()
    print('PASS post-snapshot resume', mode, STAGES[start][1], flush=True)


with sync_playwright() as p:
    browser = p.chromium.launch(executable_path='/usr/bin/chromium', headless=True, args=['--no-sandbox'])
    for scenario in ['normal', 'lost_response', '503', 'malformed', 'timeout', 'exhausted', 'reload', 'double_click', 'busy', '419', 'blocked', 'kick', 'reload_kick', 'manual_each_stage']:
        case(browser, scenario)
    for position in range(1, len(STAGES)):
        case(browser, 'stage_start', position)
    browser.close()
