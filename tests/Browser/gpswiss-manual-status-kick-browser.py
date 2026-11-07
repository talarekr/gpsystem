"""Anonymized counters/identities reproduce the reported failure: KICK itself loses its reply and compact GET remains unavailable."""
import copy
import json
import sys
from pathlib import Path
from playwright.sync_api import sync_playwright

VIEW = Path(sys.argv[1]) if len(sys.argv) > 1 else Path(__file__).parents[2] / 'resources/views/admin/tools/gpswiss-migration-export.blade.php'
HTML = VIEW.read_text().replace("@json(route('admin.tools.gpswiss-migration-export', [], false))", "'/admin/tools/gpswiss-migration-export'")
BASE = 'https://audit.test/admin/tools/gpswiss-migration-export'
RUN = '00000000-0000-4000-8000-000000000101'
ATT = '00000000-0000-4000-8000-000000000102'
STAGES = [('snapshot_completed', 'relation_audit', 'snapshot_tick'), ('snapshot_completed', 'photo_inventory', 'snapshot_tick'),
          ('photos', 'photos', 'tick'), ('archive', 'archive_index', 'tick'), ('archive', 'archive_sample', 'tick'),
          ('archive', 'archive_sample_volumes', 'tick'), ('archive', 'archive_volumes', 'tick'), ('archive', 'archive_catalog', 'tick')]


def case(browser, mode, start=0):
    context = browser.new_context()
    context.add_init_script('const native=setTimeout;window.setTimeout=(fn,ms,...args)=>native(fn,ms>=250 && ms<=8000?5:ms,...args);')
    page = context.new_page()
    accepted = dict(accepted_by_operator=True, recorded_ids=['1001', '1002'], generation_at_acceptance=1)
    state = dict(run_id=RUN, snapshot_attempt_id=ATT, snapshot_mode='browser', schema_version='gpswiss-wystawczesc-3',
                 snapshot_state='snapshot_completed', processed=31007, total=50057, exported_records=50057,
                 record_capture_total=50057, verification_processed=155,
                 cursor=dict(shard_index=42, row_index=0, probes=50778, current_dataset_index=36),
                 busy=False, resume_allowed=True, blocked_reason=None, last_error=None,
                 consistency_exception=copy.deepcopy(accepted), last_tick=dict(reason_code='tick_completed', action='operator_artifact_finalization', records_completed=0))
    phase = start
    kicks = gets = 0
    allow = False
    reloaded = False
    requests, records, ticks, kick_gets = [], [], [], []

    def stage(index):
        if index == len(STAGES):
            state.update(state='completed', phase='completed', next_action='download', download_artifacts=[])
        else:
            root, name, action = STAGES[index]
            state.update(state=root, phase=name, current_dataset=name, next_action=action)
    stage(start)

    def checkpoint():
        return {key: copy.deepcopy(state[key]) for key in ['phase', 'processed', 'cursor']}

    def advance():
        nonlocal phase
        ticks.append(state['phase'])
        if phase == 0 and not records:
            records.extend(range(43, 48))
            state.update(processed=31012, cursor=dict(shard_index=43, row_index=0, probes=50783, current_dataset_index=36))
        else:
            phase += 1
            stage(phase)
            if phase < len(STAGES):
                state['cursor'] = dict(step=phase)

    def route(r):
        nonlocal kicks, gets
        req = r.request
        if req.is_navigation_request():
            r.fulfill(content_type='text/html', body=HTML)
            return
        if req.method == 'GET':
            gets += 1
            if req.url.endswith('?format=artifacts'):
                r.fulfill(json=dict(download_artifacts=[]))
            elif gets > 1 or reloaded:
                r.abort('connectionreset')
            else:
                r.fulfill(json=copy.deepcopy(state))
            return
        action = 'kick' if req.url.endswith('/kick') else req.post_data_json['action']
        requests.append(action)
        assert action in ['kick', 'snapshot_tick', 'tick']
        if action == 'kick':
            kicks += 1
            kick_gets.append(gets)
            assert req.post_data_json == {'attempt_id': ATT}, 'Never submit a browser cursor or phase'
            if mode in ['blocked', 'attempt_mismatch', 'session_419']:
                reason = {'blocked': 'relation_shard_missing_or_changed', 'attempt_mismatch': 'attempt_mismatch', 'session_419': 'SESSION_EXPIRED'}[mode]
                r.fulfill(status=419 if mode == 'session_419' else 409, json={'reason_code': reason})
                return
            if mode == 'exhausted' and not allow or mode == 'network' and kicks == 1 or mode == 'reload' and kicks == 1:
                r.abort('connectionreset')
                return
            if mode == '503' and kicks == 1:
                r.fulfill(status=503, json={'reason_code': 'temporarily_unavailable'})
                return
            if mode == 'malformed' and kicks == 1:
                r.fulfill(content_type='application/json', body='{broken')
                return
            before = checkpoint()
            completed = state['state'] == 'completed'
            if not completed:
                advance()
            if mode == 'lost_reply' and kicks == 1:
                r.abort('connectionreset')
                return
            r.fulfill(json={**copy.deepcopy(state), 'kick_reason': 'completed' if completed else 'tick_completed',
                            'kick_checkpoint_before': before, 'kick_checkpoint_after': checkpoint()})
            return
        if mode == 'all_operation_replies_lost':
            advance()
            r.abort('connectionreset')
            return
        advance()
        r.fulfill(json=copy.deepcopy(state))

    context.route('**/*', route)
    page.goto(BASE)
    page.wait_for_function("document.querySelector('#state').textContent.includes('31007')")
    if mode == 'reload':
        reloaded = True
        page.reload()
        page.wait_for_function("!document.querySelector('#resume').disabled", timeout=15000)
    page.evaluate("document.querySelector('#resume').click();document.querySelector('#resume').click();")
    if mode == 'exhausted':
        page.wait_for_function("document.querySelector('#message').textContent.includes('kick_retry_exhausted') || document.querySelector('#resume-diagnostic').textContent.includes('status_fetch_retry_exhausted') && document.querySelector('#message').textContent.includes('Eksport zatrzymany')", timeout=15000)
        assert kicks == 3 and not records, 'KICK must have a finite retry budget independent of GET'
        assert len(set(kick_gets)) == 1, 'Do not require GET status between KICK retries'
        allow = True
        before = gets
        page.locator('#resume').click()
        page.wait_for_function("JSON.parse(document.querySelector('#state').textContent).state==='completed'", timeout=15000)
        assert gets == before + 4
    elif mode in ['blocked', 'attempt_mismatch', 'session_419']:
        reason = {'blocked': 'relation_shard_missing_or_changed', 'attempt_mismatch': 'attempt_mismatch', 'session_419': 'SESSION_EXPIRED'}[mode]
        page.wait_for_function("document.querySelector('#message').textContent.includes('" + reason + "')")
        assert kicks == 1 and not ticks
        trace = json.loads(page.locator('#resume-diagnostic').inner_text())
        assert trace['kick_result']['blocked_reason'] == reason
        context.close()
        print('PASS manual KICK retry', mode, STAGES[start][1], flush=True)
        return
    else:
        page.wait_for_function("JSON.parse(document.querySelector('#state').textContent).state==='completed' || document.querySelector('#message').textContent.includes('Eksport zatrzymany')", timeout=15000)
        assert state['state'] == 'completed', 'KICK failed and recovery got stuck behind unavailable status GET'
    trace = json.loads(page.locator('#resume-diagnostic').inner_text())
    assert state['run_id'] == RUN and state['snapshot_attempt_id'] == ATT and state['consistency_exception'] == accepted
    assert state['exported_records'] == 50057 and state['verification_processed'] == 155
    assert records == (list(range(43, 48)) if start == 0 else []) and len(records) == len(set(records))
    assert trace['kick_result']['checkpoint_after_source'] == 'server_kick_response'
    assert trace['kick_result']['checkpoint_before_source'] == 'browser_display_only'
    assert trace['kick_result']['checkpoint_after'] is not None
    if mode in ['network', 'reload', '503', 'malformed', 'lost_reply']:
        assert kicks == 2 and len(set(kick_gets)) == 1, 'Retry exactly the guarded POST without another GET'
    if mode == 'network':
        assert trace['kick_result']['attempts'][0]['network_exception_type'] == 'TypeError'
        assert trace['kick_result']['attempts'][0]['response_bytes'] == 0
        assert trace['kick_result']['attempts'][-1]['http_status'] == 200
    if mode == 'lost_reply' and start == 0:
        assert trace['kick_result']['server_checkpoint_before']['processed'] == 31012
    assert 'kick_fallback_started' in page.locator('#resume-diagnostic').inner_text()
    context.close()
    print('PASS manual KICK retry', mode, STAGES[start][1], flush=True)


with sync_playwright() as p:
    browser = p.chromium.launch(executable_path='/usr/bin/chromium', headless=True, args=['--no-sandbox'])
    for scenario in ['network', 'lost_reply', 'exhausted', 'reload', '503', 'malformed', 'blocked', 'attempt_mismatch', 'session_419', 'all_operation_replies_lost']:
        case(browser, scenario)
    for position in range(1, len(STAGES)):
        case(browser, 'network', position)
    browser.close()
