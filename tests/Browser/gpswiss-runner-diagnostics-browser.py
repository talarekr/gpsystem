"""Exact production cursor; intercepted requests only."""
import time
from pathlib import Path
from playwright.sync_api import sync_playwright

def request_url(req):
    # Normalize only the new GET selector for legacy workflow assertions.
    url = req.url
    if req.method == 'GET' and url.endswith('?format=compact') and url.split('?')[0].split('/')[-1] != 'gpswiss-migration-export':
        return url.split('?')[0]
    return url

HTML=(Path(__file__).parents[2]/'resources/views/admin/tools/gpswiss-migration-export.blade.php').read_text().replace("@json(route('admin.tools.gpswiss-migration-export', [], false))", "'/admin/tools/gpswiss-migration-export'")
BASE='https://audit.test/admin/tools/gpswiss-migration-export'
RUN='8de6e27c-ad7c-470b-830b-b9ecee9446ba'
ATT='22222222-2222-4222-8222-222222222222'
def case(browser,mode):
 c=browser.new_context();p=c.new_page();requests=[];calls=0;polls=0
 state=dict(run_id=RUN,snapshot_attempt_id=ATT,snapshot_mode='browser',state='snapshot_running',snapshot_state='snapshot_running',phase='snapshot_export',current_dataset='parts',processed=2528,total=79957,cursor={'id':2528},busy=False,resume_allowed=True,next_action='snapshot_tick',last_error=None)
 def route(r):
  nonlocal calls,polls
  if r.request.is_navigation_request():r.fulfill(content_type='text/html',body=HTML.replace('45000','50') if mode=='timeout' else HTML);return
  action=(r.request.post_data_json or {}).get('action') if r.request.method=='POST' else None
  requests.append(action);assert action in [None,'resume','snapshot_tick'];assert RUN in request_url(r.request) or 'format=compact' in request_url(r.request)
  if action=='snapshot_tick':
   calls+=1;assert r.request.post_data_json['expected_snapshot_attempt']==ATT
   if calls==1 or mode=='exhausted':
    if mode in ['500','502','503','504']:r.fulfill(status=int(mode),body='temporary');return
    if mode=='timeout':time.sleep(.15);r.fulfill(json=state);return
    if mode=='malformed':r.fulfill(status=200,body='{broken');return
    if mode=='lost_success':state.update(processed=2533,cursor={'id':2533})
    r.abort('connectionreset');return
   assert polls>=1
   state.update(processed=state['processed']+5,cursor={'id':state['processed']+5},next_action='download',state='completed')
  if action is None:polls+=1
  if 'format=compact' in request_url(r.request):r.fulfill(json={'existing_run_found':True,'run':state})
  else:r.fulfill(json=state)
 c.route('**/*',route);p.goto(BASE);p.wait_for_function("document.querySelector('#state').textContent.includes('2528')")
 assert '[object Object]' not in p.locator('#progress').inner_text()
 p.locator('#resume').click()
 if mode=='exhausted':
  p.wait_for_function("document.querySelector('#message').textContent.includes('Eksport zatrzymany')",timeout=30000);assert calls==5;assert state['processed']==2528
 else:
  p.wait_for_function("document.querySelector('#message').textContent.includes('Eksport gotowy')",timeout=30000);assert state['processed']>2528;assert calls==2
 assert requests.count('resume')==0;assert 'response_bytes' in p.locator('#request-diagnostic').inner_text()
 c.close();print('PASS runner reliability',mode)
with sync_playwright() as pw:
 b=pw.chromium.launch(executable_path='/usr/bin/chromium',args=['--no-sandbox'])
 for mode in ['network','timeout','500','502','503','504','malformed','lost_success','exhausted']:case(b,mode)
 b.close()
