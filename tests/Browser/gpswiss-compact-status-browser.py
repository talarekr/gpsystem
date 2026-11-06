"""Exact production 20568/79957: compact status retry precedes every resumed tick."""
import time
from pathlib import Path
from playwright.sync_api import sync_playwright
HTML=(Path(__file__).parents[2]/'resources/views/admin/tools/gpswiss-migration-export.blade.php').read_text().replace("@json(route('admin.tools.gpswiss-migration-export', [], false))", "'/admin/tools/gpswiss-migration-export'")
BASE='https://audit.test/admin/tools/gpswiss-migration-export'
RUN='8de6e27c-ad7c-470b-830b-b9ecee9446ba'
ATT='9cb33c92-3f1e-4db0-b08d-052715aa5f4c'
def case(browser,mode):
 c=browser.new_context();p=c.new_page();fails=0;ticks=0;seen=[];success=False
 state=dict(run_id=RUN,snapshot_attempt_id=ATT,snapshot_mode='browser',state='snapshot_running',snapshot_state='snapshot_running',phase='snapshot_export',current_dataset='marketplace_category_mappings',processed=20568,total=79957,remaining=59389,cursor={'id':2777},busy=False,resume_allowed=True,next_action='snapshot_tick',last_error=None,last_tick={'reason_code':'tick_completed'})
 def route(r):
  nonlocal fails,ticks,success
  req=r.request
  if req.is_navigation_request():r.fulfill(content_type='text/html',body=HTML.replace('isStatus?10000:45000','isStatus?50:45000') if mode=='timeout' else HTML);return
  action=(req.post_data_json or {}).get('action') if req.method=='POST' else None;seen.append((req.url,action))
  if req.url.endswith('?format=artifacts'):r.fulfill(json={'run_id':RUN,'state':'completed','download_artifacts':[]});return
  if action is None:
   assert req.url.endswith('?format=compact'),req.url
   if '?format=compact' in req.url and '/'+RUN in req.url:
    fails+=1
    if fails==1 or mode.startswith('exhausted'):
     if mode in ['500','503','exhausted503']:r.fulfill(status=503 if mode=='exhausted503' else int(mode),body='temporary');return
     if mode=='malformed':r.fulfill(content_type='application/json',body='{bad');return
     if mode=='timeout':time.sleep(.15);r.fulfill(json=state);return
     r.abort('connectionreset');return
    success=True
   r.fulfill(json={'existing_run_found':True,'run':state} if req.url==BASE+'?format=compact' else state);return
  assert action=='snapshot_tick' and success,seen
  assert req.post_data_json['expected_snapshot_attempt']==ATT
  ticks+=1;assert ticks==1
  state.update(processed=20573,cursor={'id':2782},state='completed',next_action='download')
  r.fulfill(json=state)
 c.route('**/*',route);p.goto(BASE);p.wait_for_function("document.querySelector('#state').textContent.includes('20568')")
 if mode=='reload':p.reload();p.wait_for_function("document.querySelector('#state').textContent.includes('20568')")
 p.locator('#resume').click()
 if mode.startswith('exhausted'):
  p.wait_for_function("document.querySelector('#resume-diagnostic').textContent.includes('status_fetch_retry_exhausted')",timeout=20000)
  assert fails==4 and ticks==0
 else:
  p.wait_for_function("document.querySelector('#message').textContent.includes('Eksport gotowy')",timeout=20000)
  assert state['processed']==20573 and state['cursor']=={'id':2782} and fails==(3 if mode=='reload' else 2)
  events=p.locator('#resume-diagnostic').inner_text()
  for event in ['resume_started','local_retry_state_reset','status_loaded','next_action=snapshot_tick','tick_scheduled','tick_completed']:assert event in events
 diag=p.locator('#request-diagnostic').inner_text();assert 'content_type' in diag and 'status_response_bytes' in diag
 assert state['run_id']==RUN and state['snapshot_attempt_id']==ATT
 c.close();print('PASS compact status',mode)
with sync_playwright() as pw:
 b=pw.chromium.launch(executable_path='/usr/bin/chromium',args=['--no-sandbox'])
 for mode in ['network','500','503','malformed','timeout','reload','exhausted','exhausted503']:case(b,mode)
 b.close()
