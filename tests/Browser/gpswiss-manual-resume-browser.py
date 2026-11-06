"""Manual continuation of the exact production checkpoint; no real network."""
from pathlib import Path
from playwright.sync_api import sync_playwright
HTML=(Path(__file__).parents[2]/'resources/views/admin/tools/gpswiss-migration-export.blade.php').read_text().replace("@json(route('admin.tools.gpswiss-migration-export', [], false))", "'/admin/tools/gpswiss-migration-export'")
BASE='https://audit.test/admin/tools/gpswiss-migration-export'
RUN='8de6e27c-ad7c-470b-830b-b9ecee9446ba'
ATT='9cb33c92-3f1e-4db0-b08d-052715aa5f4c'
def case(browser,mode):
 c=browser.new_context();p=c.new_page();calls=0;actions=[];allow=False;rows=[];gets=0
 state=dict(run_id=RUN,snapshot_attempt_id=ATT,snapshot_mode='browser',state='snapshot_running',snapshot_state='snapshot_running',phase='snapshot_export',current_dataset='part_categories',processed=15759,total=79957,remaining=64198,cursor={'id':435},busy=False,resume_allowed=True,next_action='snapshot_tick',last_error=None,last_tick=dict(reason_code='tick_completed',cursor_before={'id':430},cursor_after={'id':435},records_completed=5))
 if mode=='lost_timer':
  p.add_init_script("const native=setTimeout;window.setTimeout=(fn,ms,...args)=>native(fn,ms===500?60000:ms,...args);")
 def route(r):
  nonlocal calls,gets
  if r.request.is_navigation_request():r.fulfill(content_type='text/html',body=HTML);return
  action=(r.request.post_data_json or {}).get('action') if r.request.method=='POST' else None;actions.append(action)
  assert action in [None,'snapshot_tick'], 'Healthy server continuation needs no resume/reset POST'
  if action=='snapshot_tick':
   calls+=1;assert r.request.post_data_json['expected_snapshot_attempt']==ATT
   if not allow:r.abort('connectionreset');return
   assert not rows;rows.extend(range(436,441));state.update(processed=15764,cursor={'id':440},remaining=64193,state='completed',next_action='download')
  else:gets+=1
  r.fulfill(json={'existing_run_found':True,'run':state} if 'format=status' in r.request.url else state)
 c.route('**/*',route);p.goto(BASE);p.wait_for_function("document.querySelector('#state').textContent.includes('15759')");p.locator('#resume').click()
 if mode=='lost_timer':p.wait_for_function("document.querySelector('#message').textContent.includes('network_recovery')")
 else:
  p.wait_for_function("document.querySelector('#message').textContent.includes('retry_exhausted')",timeout=30000);assert calls==5
 if mode=='reload':p.reload();p.wait_for_function("document.querySelector('#state').textContent.includes('15759')")
 allow=True;before=gets
 p.evaluate("document.querySelector('#resume').click();document.querySelector('#resume').click();")
 p.wait_for_function("document.querySelector('#message').textContent.includes('Eksport gotowy')",timeout=10000)
 assert gets>before;assert state['processed']==15764 and state['cursor']=={'id':440};assert len(rows)==len(set(rows))==5
 trace=p.locator('#resume-diagnostic').inner_text()
 for event in ['resume_started','local_retry_state_reset','status_loaded','next_action=snapshot_tick','tick_scheduled']:assert event in trace,trace
 assert '9cb33c92' in p.locator('#state').inner_text();assert state['run_id']==RUN
 c.close();print('PASS manual resume',mode)
with sync_playwright() as pw:
 b=pw.chromium.launch(executable_path='/usr/bin/chromium',args=['--no-sandbox'])
 for mode in ['exhausted','reload','lost_timer']:case(b,mode)
 b.close()
