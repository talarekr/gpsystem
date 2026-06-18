<?php

namespace App\Http\Controllers\Tools;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ProcessPartImagePresentationRunnerController extends Controller
{
    public function __invoke(Request $request)
    {
        $configuredToken = (string) env('PRODUCT_IMAGES_IMPORT_TOKEN', '');
        $requestToken = (string) $request->query('token', '');

        if ($configuredToken === '' || $requestToken === '' || ! hash_equals($configuredToken, $requestToken)) {
            abort(403);
        }

        $config = $this->config($request);

        if ((string) $request->query('auto', '') !== '1') {
            return response($this->renderLanding($config), 200)->header('Content-Type', 'text/html; charset=UTF-8');
        }

        return response($this->renderRunner($config), 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** @return array<string, mixed> */
    private function config(Request $request): array
    {
        $limit = min(50, max(1, (int) $request->query('limit', 50)));

        return [
            'token' => (string) $request->query('token', ''),
            'dryRun' => $request->boolean('dry_run', false),
            'limit' => $limit,
            'offset' => max(0, (int) $request->query('offset', 0)),
            'onlyImported' => $request->boolean('only_imported', true),
            'missingOnly' => $request->boolean('missing_only', false),
            'force' => $request->boolean('force', true),
            'maxBatches' => max(1, (int) $request->query('max_batches', 20)),
            'delayMs' => max(0, (int) $request->query('delay_ms', 500)),
            'stopOnErrors' => $request->boolean('stop_on_errors', true),
            'runnerUrl' => url('/tools/process-part-image-presentation-runner'),
            'batchUrl' => url('/tools/process-part-image-presentation'),
        ];
    }

    /** @param array<string, mixed> $config */
    private function renderLanding(array $config): string
    {
        $startQuery = [
            'token' => $config['token'],
            'auto' => '1',
            'dry_run' => $config['dryRun'] ? '1' : '0',
            'limit' => $config['limit'],
            'offset' => $config['offset'],
            'only_imported' => $config['onlyImported'] ? '1' : '0',
            'missing_only' => $config['missingOnly'] ? '1' : '0',
            'force' => $config['force'] ? '1' : '0',
            'max_batches' => $config['maxBatches'],
            'delay_ms' => $config['delayMs'],
            'stop_on_errors' => $config['stopOnErrors'] ? '1' : '0',
        ];
        $startUrl = $config['runnerUrl'].'?'.http_build_query($startQuery);

        return '<!doctype html><html lang="pl"><head><meta charset="utf-8"><title>Presentation images auto-runner</title>'
            .$this->style().'</head><body><h1>Presentation images auto-runner</h1>'
            .'<p>Ten runner uruchamia istniejący endpoint <code>/tools/process-part-image-presentation</code> batch po batchu dopiero po wejściu w URL z <code>auto=1</code>.</p>'
            .'<p class="notice"><strong>Tryb docelowy: ALL IMPORTED / missing_only=0</strong>. Domyślnie: <code>only_imported=1</code>, <code>missing_only=0</code>, <code>force=1</code>, <code>dry_run=0</code>, <code>limit=50</code>.</p>'
            .'<h2>Aktualna konfiguracja</h2><pre>'.e(json_encode($this->publicConfig($config), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}').'</pre>'
            .'<p><a class="button" href="'.e($startUrl).'">Start auto-runner od offset='.e((string) $config['offset']).'</a></p>'
            .'</body></html>';
    }

    /** @param array<string, mixed> $config */
    private function renderRunner(array $config): string
    {
        $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?: '{}';

        return str_replace('__CONFIG__', $json, <<<'HTML'
<!doctype html>
<html lang="pl">
<head><meta charset="utf-8"><title>Presentation images auto-runner</title>
<style>body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;margin:24px;line-height:1.45}dl{display:grid;grid-template-columns:220px 1fr;gap:8px 14px;max-width:1100px}dt{font-weight:700}dd{margin:0}pre{background:#111;color:#eee;padding:16px;border-radius:6px;overflow:auto;white-space:pre-wrap}.notice{background:#fff3cd;border:1px solid #ffec99;padding:12px;border-radius:6px}.status{font-size:20px;font-weight:700}.button{display:inline-block;padding:8px 12px;border:1px solid #333;border-radius:4px;text-decoration:none;color:#111}code{overflow-wrap:anywhere}</style>
</head>
<body>
<h1>Presentation images auto-runner</h1>
<p class="notice"><strong>RUNNING ALL IMPORTED / missing_only=0</strong> — runner przetwarza importowane zdjęcia z <code>force=1</code> i nie uruchamia się po deployu, tylko z tego URL-a <code>auto=1</code>.</p>
<p><button id="pauseBtn" type="button">Pause</button> <button id="resumeBtn" type="button">Resume</button> <button id="stopBtn" type="button">Stop</button></p>
<dl>
<dt>Status</dt><dd class="status" id="status">STARTING</dd>
<dt>Current offset</dt><dd id="currentOffset">0</dd>
<dt>Batch number</dt><dd id="batchNumber">0</dd>
<dt>Total scanned</dt><dd id="totalScanned">0</dd>
<dt>Total eligible</dt><dd id="totalEligible">0</dd>
<dt>Total processed</dt><dd id="totalProcessed">0</dd>
<dt>Total skipped</dt><dd id="totalSkipped">0</dd>
<dt>Total warnings</dt><dd id="totalWarnings">0</dd>
<dt>Total errors</dt><dd id="totalErrors">0</dd>
<dt>Next/current endpoint</dt><dd><a id="nextLink" href="#"><code id="nextUrl"></code></a></dd>
</dl>
<h2>Konfiguracja</h2><pre id="config"></pre>
<h2>Ostatni JSON batcha</h2><pre id="lastJson">{}</pre>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const config = __CONFIG__;
    let currentOffset = Number(config.offset || 0);
    let batchNumber = 0;
    let paused = false;
    let stopped = false;
    const totals = {scanned: 0, eligible: 0, processed: 0, skipped: 0, warnings: 0, errors: 0};
    const el = (id) => document.getElementById(id);

    function boolParam(value) { return value ? '1' : '0'; }
    function buildBatchUrl(offset) {
        const params = new URLSearchParams();
        params.set('token', config.token || '');
        params.set('limit', String(config.limit || 50));
        params.set('offset', String(offset));
        params.set('dry_run', boolParam(config.dryRun));
        params.set('force', boolParam(config.force));
        params.set('only_imported', boolParam(config.onlyImported));
        params.set('missing_only', boolParam(config.missingOnly));
        return String(config.batchUrl) + '?' + params.toString();
    }
    function render(status) {
        if (status) el('status').textContent = status;
        const url = buildBatchUrl(currentOffset);
        el('currentOffset').textContent = String(currentOffset);
        el('batchNumber').textContent = String(batchNumber);
        el('totalScanned').textContent = String(totals.scanned);
        el('totalEligible').textContent = String(totals.eligible);
        el('totalProcessed').textContent = String(totals.processed);
        el('totalSkipped').textContent = String(totals.skipped);
        el('totalWarnings').textContent = String(totals.warnings);
        el('totalErrors').textContent = String(totals.errors);
        el('nextUrl').textContent = url;
        el('nextLink').href = url;
    }
    function sleep(ms) { return new Promise((resolve) => setTimeout(resolve, ms)); }
    async function run() {
        if (stopped) return;
        if (paused) { render('PAUSED'); return; }
        if (batchNumber >= Number(config.maxBatches || 20)) { stopped = true; render('STOPPED_MAX_BATCHES'); return; }
        const url = buildBatchUrl(currentOffset);
        render('RUNNING');
        try {
            const response = await fetch(url, {headers: {Accept: 'application/json'}});
            const json = await response.json();
            el('lastJson').textContent = JSON.stringify(json, null, 2);
            if (!response.ok) throw new Error(JSON.stringify(json));
            batchNumber += 1;
            totals.scanned += Number(json.scanned || 0);
            totals.eligible += Number(json.eligible || 0);
            totals.processed += Number(json.processed || 0);
            totals.skipped += Number(json.skipped || 0);
            totals.warnings += Number(json.warnings_count || 0);
            totals.errors += Number(json.errors_count || 0);
            currentOffset = Number(json.next_offset);
            render('RUNNING');
            if (json.completed === true) { stopped = true; render('COMPLETED'); return; }
            if (Number(json.errors_count || 0) > 0 && config.stopOnErrors) { stopped = true; render('STOPPED_ERRORS'); return; }
            await sleep(Number(config.delayMs || 500));
            run();
        } catch (error) {
            totals.errors += 1;
            stopped = true;
            el('lastJson').textContent = error && error.message ? error.message : String(error);
            render('JS_ERROR');
        }
    }
    el('config').textContent = JSON.stringify(config, null, 2);
    el('pauseBtn').addEventListener('click', () => { paused = true; render('PAUSED'); });
    el('resumeBtn').addEventListener('click', () => { if (!stopped) { paused = false; render('RUNNING'); run(); } });
    el('stopBtn').addEventListener('click', () => { stopped = true; paused = false; render('STOPPED'); });
    render('STARTING');
    run();
});
</script>
</body></html>
HTML);
    }

    private function style(): string
    {
        return '<style>body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;margin:24px;line-height:1.45;max-width:1100px}.notice{background:#fff3cd;border:1px solid #ffec99;padding:12px;border-radius:6px}pre{background:#111;color:#eee;padding:16px;border-radius:6px;overflow:auto}.button{display:inline-block;padding:10px 14px;border:1px solid #333;border-radius:4px;text-decoration:none;color:#111}</style>';
    }

    /** @param array<string, mixed> $config */
    private function publicConfig(array $config): array
    {
        $public = $config;
        $public['token'] = '[hidden in preview]';

        return $public;
    }
}
