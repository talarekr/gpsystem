<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\MarketplaceCategoryTreeImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketplaceCategoryTreeImportController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    public function dryRunImport(Request $request, MarketplaceCategoryTreeImportService $service): JsonResponse
    {
        if ($deny = $this->denyBadToken($request)) return $deny;
        return response()->json($service->previewOrImport(false));
    }

    public function debugFetch(Request $request, MarketplaceCategoryTreeImportService $service): JsonResponse
    {
        if ($deny = $this->denyBadToken($request)) return $deny;
        return response()->json($service->debugFetch($request->boolean('verbose')));
    }

    public function import(Request $request, MarketplaceCategoryTreeImportService $service): JsonResponse
    {
        if ($deny = $this->denyBadToken($request)) return $deny;
        if ((string) $request->query('confirm') !== '1') return response()->json(['ok' => false, 'error_message' => 'Refusing local import without confirm=1.', 'local_update' => false, 'ovoko_write' => false, 'allegro_write' => false, 'ebay_write' => false], 422);
        try {
            return response()->json([
                'ok' => false,
                'error_message' => 'Large marketplace category tree imports must use the batch autorunner endpoint.',
                'autorunner_url' => url('/tools/marketplace-category-tree-import-autorun').'?token='.self::TOKEN,
                'local_update' => false,
                'ovoko_write' => false,
                'allegro_write' => false,
                'ebay_write' => false,
            ], 409);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error_message' => $e->getMessage(), 'local_update' => false, 'ovoko_write' => false, 'allegro_write' => false, 'ebay_write' => false], 500);
        }
    }

    public function autorun(Request $request, MarketplaceCategoryTreeImportService $service)
    {
        if ($deny = $this->denyBadToken($request)) return $deny;
        $runId = (string) $request->query('run_id', '');
        $latest = $runId === '' ? $service->latestAutorun() : null;
        $status = $runId !== '' ? $service->statusAutorun($runId) : ($latest ? $service->statusAutorun((string) $latest['run_id']) : ['ok' => true, 'status' => 'idle']);
        if ($request->expectsJson() || $request->query('json')) return response()->json($status);

        $token = self::TOKEN;
        $startUrl = url('/tools/start-marketplace-category-tree-import-autorun').'?token='.$token.'&confirm=1&batch_size=200&channel=all&include_raw_payload=0&continue_on_error=1';
        $statusUrl = url('/tools/status-marketplace-category-tree-import-autorun').'?token='.$token;
        $resultsUrl = url('/tools/results-marketplace-category-tree-import-autorun').'?token='.$token;
        $initialStatus = e(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $html = <<<'HTML'
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Marketplace category tree import autorun</title>
<style>
body{font-family:Arial,sans-serif;margin:32px;color:#111827}.actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:18px}button,a.button{border:0;display:inline-block;padding:9px 14px;background:#2563eb;color:white;text-decoration:none;border-radius:6px;cursor:pointer;font-size:14px}button.secondary{background:#4b5563}button.danger{background:#dc2626}button:disabled{background:#9ca3af;cursor:not-allowed}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin:18px 0}.card{border:1px solid #e5e7eb;border-radius:8px;padding:12px;background:#fff}.label{font-size:12px;text-transform:uppercase;color:#6b7280}.value{font-size:20px;font-weight:700;margin-top:4px;word-break:break-word}.bar{height:18px;background:#e5e7eb;border-radius:99px;overflow:hidden}.bar span{display:block;height:100%;width:0;background:#16a34a;transition:width .2s}.notice{padding:12px;border-radius:8px;background:#eff6ff;border:1px solid #bfdbfe;margin:14px 0}.notice.error{background:#fef2f2;border-color:#fecaca}pre{background:#f6f8fa;padding:16px;border-radius:8px;overflow:auto;max-height:420px}label{display:inline-flex;gap:6px;align-items:center}
</style>
</head>
<body>
<h1>Marketplace category tree import autorun</h1>
<div class="actions">
<button id="startBtn">Start</button>
<button id="resumeBtn" class="secondary">Resume</button>
<button id="pauseBtn" class="danger" disabled>Stop/Pause</button>
<label><input id="continueOnError" type="checkbox" checked> continue_on_error=1</label>
<a id="resultsLink" class="button secondary" href="#" target="_blank" rel="noopener" style="display:none">Results</a>
</div>
<div id="message" class="notice">Gotowy. Start utworzy run, a Resume wznowi aktywny/latest run.</div>
<div class="bar"><span id="progressBar"></span></div>
<div class="grid">
<div class="card"><div class="label">run_id</div><div id="run_id" class="value">-</div></div>
<div class="card"><div class="label">status</div><div id="status" class="value">-</div></div>
<div class="card"><div class="label">processed / total</div><div id="processed_total" class="value">-</div></div>
<div class="card"><div class="label">progress_percent</div><div id="progress_percent" class="value">-</div></div>
<div class="card"><div class="label">created_count</div><div id="created_count" class="value">-</div></div>
<div class="card"><div class="label">updated_count</div><div id="updated_count" class="value">-</div></div>
<div class="card"><div class="label">failed_count</div><div id="failed_count" class="value">-</div></div>
<div class="card"><div class="label">processed_this_tick</div><div id="processed_this_tick" class="value">-</div></div>
</div>
<h2>sample_errors</h2><pre id="sample_errors">[]</pre>
<h2>Raw status</h2><pre id="rawStatus">INITIAL_STATUS</pre>
<script>
const START_URL = __START_URL__;
const STATUS_URL = __STATUS_URL__;
const RESULTS_URL = __RESULTS_URL__;
let current = JSON.parse(document.getElementById('rawStatus').textContent || '{}');
let paused = true;
let inFlight = false;
let timer = null;
const delayMs = 750;

function byId(id){ return document.getElementById(id); }
function setMessage(text, isError=false){ const el=byId('message'); el.textContent=text; el.className='notice'+(isError?' error':''); }
function resultsHref(runId){ return RESULTS_URL + '&run_id=' + encodeURIComponent(runId || ''); }
function withContinue(url){ return url + (url.includes('?') ? '&' : '?') + 'continue_on_error=' + (byId('continueOnError').checked ? '1' : '0'); }
function render(data){
  current = data || {};
  byId('run_id').textContent = current.run_id || '-';
  byId('status').textContent = current.status || '-';
  byId('processed_total').textContent = (current.processed_count ?? 0) + ' / ' + (current.total_count ?? 0);
  byId('progress_percent').textContent = (current.progress_percent ?? 0) + '%';
  byId('created_count').textContent = current.created_count ?? 0;
  byId('updated_count').textContent = current.updated_count ?? 0;
  byId('failed_count').textContent = current.failed_count ?? 0;
  byId('processed_this_tick').textContent = current.processed_this_tick ?? '-';
  byId('sample_errors').textContent = JSON.stringify(current.sample_errors || [], null, 2);
  byId('rawStatus').textContent = JSON.stringify(current, null, 2);
  byId('progressBar').style.width = Math.max(0, Math.min(100, Number(current.progress_percent || 0))) + '%';
  if (current.run_id) { byId('resultsLink').href = resultsHref(current.run_id); byId('resultsLink').style.display = 'inline-block'; }
}
function setRunning(running){ byId('startBtn').disabled = running || inFlight; byId('resumeBtn').disabled = running || inFlight; byId('pauseBtn').disabled = !running; }
async function fetchJson(url){ inFlight = true; setRunning(!paused); try { const res = await fetch(url, {headers:{'Accept':'application/json'}}); const data = await res.json(); render(data); return data; } finally { inFlight = false; setRunning(!paused); } }
function scheduleNext(data){
  clearTimeout(timer);
  const hasErrors = Number(data.failed_count || 0) > 0 || (Array.isArray(data.sample_errors) && data.sample_errors.length > 0);
  if (data.status === 'complete') { paused = true; setRunning(false); setMessage('Import zakończony'); return; }
  if (hasErrors && !byId('continueOnError').checked) { paused = true; setRunning(false); setMessage('Import zatrzymany lokalnie: wykryto błędy, a continue_on_error=0.', true); return; }
  if (!paused && data.status === 'running' && data.next_url) timer = setTimeout(() => tick(data.next_url), delayMs);
}
async function tick(url){ if (paused || inFlight) return; const data = await fetchJson(withContinue(url)); scheduleNext(data); }
async function start(){ if (inFlight || !paused) return; paused = false; setRunning(true); setMessage('Startuję import i automatyczne ticki...'); const data = await fetchJson(START_URL); if (data.status === 'started' && data.next_url) data.status = 'running'; scheduleNext(data); }
async function resume(){ if (inFlight || !paused) return; paused = false; setRunning(true); setMessage('Szukam aktywnego/latest runa i wznawiam ticki...'); const data = await fetchJson(STATUS_URL); if (data.next_url) { setMessage('Wznowiono run ' + data.run_id); scheduleNext({...data, status: data.status === 'started' ? 'running' : data.status}); } else { scheduleNext(data); if (data.status !== 'complete') setMessage(data.error_message || 'Brak aktywnego runa do wznowienia.', true); } }
function pause(){ paused = true; clearTimeout(timer); setRunning(false); setMessage('Auto-fetch zatrzymany lokalnie. Run nie został zresetowany. Kliknij Resume, aby kontynuować.'); }
byId('startBtn').addEventListener('click', start);
byId('resumeBtn').addEventListener('click', resume);
byId('pauseBtn').addEventListener('click', pause);
render(current);
</script>
</body>
</html>
HTML;
        $html = str_replace(['__START_URL__', '__STATUS_URL__', '__RESULTS_URL__', 'INITIAL_STATUS'], [json_encode($startUrl, JSON_UNESCAPED_SLASHES), json_encode($statusUrl, JSON_UNESCAPED_SLASHES), json_encode($resultsUrl, JSON_UNESCAPED_SLASHES), $initialStatus], $html);
        return response($html);
    }

    public function startAutorun(Request $request, MarketplaceCategoryTreeImportService $service): JsonResponse
    {
        if ($deny = $this->denyBadToken($request)) return $deny;
        if ((string) $request->query('confirm') !== '1') return response()->json(['ok' => false, 'error_message' => 'Refusing autorun start without confirm=1.'], 422);
        try {
            return response()->json($service->startAutorun((string) $request->query('channel', 'all'), (int) $request->query('batch_size', 200), $request->boolean('include_raw_payload'), (int) $request->query('time_limit', 10)));
        } catch (\Throwable $e) { return response()->json(['ok' => false, 'error_message' => $e->getMessage()], 500); }
    }

    public function runAutorun(Request $request, MarketplaceCategoryTreeImportService $service): JsonResponse
    {
        if ($deny = $this->denyBadToken($request)) return $deny;
        return response()->json($service->tickAutorun((string) $request->query('run_id')));
    }

    public function statusAutorun(Request $request, MarketplaceCategoryTreeImportService $service): JsonResponse
    {
        if ($deny = $this->denyBadToken($request)) return $deny;
        $runId = (string) $request->query('run_id', '');
        if ($runId === '') {
            $latest = $service->latestAutorun();
            return response()->json($latest ? $service->statusAutorun((string) $latest['run_id']) : ['ok' => true, 'status' => 'idle', 'run_id' => null]);
        }
        return response()->json($service->statusAutorun($runId));
    }

    public function resetAutorun(Request $request, MarketplaceCategoryTreeImportService $service): JsonResponse
    {
        if ($deny = $this->denyBadToken($request)) return $deny;
        if ((string) $request->query('confirm') !== '1') return response()->json(['ok' => false, 'error_message' => 'Refusing reset without confirm=1.'], 422);
        return response()->json($service->resetAutorun((string) $request->query('run_id')));
    }

    public function resultsAutorun(Request $request, MarketplaceCategoryTreeImportService $service): JsonResponse
    {
        if ($deny = $this->denyBadToken($request)) return $deny;
        return response()->json($service->resultsAutorun((string) $request->query('run_id')));
    }

    public function debugAutorun(Request $request, MarketplaceCategoryTreeImportService $service): JsonResponse
    {
        if ($deny = $this->denyBadToken($request)) return $deny;
        return response()->json($service->debugAutorun());
    }

    public function dryRunBackfill(Request $request, MarketplaceCategoryTreeImportService $service): JsonResponse
    {
        if ($deny = $this->denyBadToken($request)) return $deny;
        return response()->json($service->backfillEbayDe(false));
    }

    public function backfill(Request $request, MarketplaceCategoryTreeImportService $service): JsonResponse
    {
        if ($deny = $this->denyBadToken($request)) return $deny;
        if ((string) $request->query('confirm') !== '1') return response()->json(['ok' => false, 'error_message' => 'Refusing local backfill without confirm=1.', 'local_update' => false], 422);
        return response()->json($service->backfillEbayDe(true));
    }

    private function denyBadToken(Request $request): ?JsonResponse
    {
        return hash_equals(self::TOKEN, (string) $request->query('token', '')) ? null : response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
    }
}
