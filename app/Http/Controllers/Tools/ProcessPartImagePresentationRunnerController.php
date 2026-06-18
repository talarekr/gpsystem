<?php

namespace App\Http\Controllers\Tools;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ProcessPartImagePresentationRunnerController extends Controller
{
    public function __invoke(Request $request)
    {
        $configuredToken = (string) env('PRODUCT_IMAGES_IMPORT_TOKEN', '');
        $requestToken = (string) $request->query('token', '');

        if ($configuredToken === '' || $requestToken === '' || ! hash_equals($configuredToken, $requestToken)) {
            abort(403);
        }

        if ($request->query('auto') !== '1') {
            return response($this->renderStartPage($request), 200)->header('Content-Type', 'text/html; charset=UTF-8');
        }

        $validator = Validator::make($request->query(), [
            'token' => ['required', 'string'],
            'auto' => ['required', Rule::in(['1', 1])],
            'dry_run' => ['nullable', Rule::in(['0', '1', 0, 1])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'offset' => ['nullable', 'integer', 'min:0'],
            'only_imported' => ['nullable', Rule::in(['0', '1', 0, 1])],
            'missing_only' => ['nullable', Rule::in(['0', '1', 0, 1])],
            'force' => ['nullable', Rule::in(['0', '1', 0, 1])],
            'max_batches' => ['nullable', 'integer', 'min:1'],
            'delay_ms' => ['nullable', 'integer', 'min:0'],
            'stop_on_errors' => ['nullable', Rule::in(['0', '1', 0, 1])],
        ], [
            'limit.max' => 'Parametr limit nie może być większy niż 50.',
        ]);

        if ($validator->fails()) {
            return response($this->renderValidationPage($request, $validator->errors()->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)), 422)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }

        $config = [
            'token' => $requestToken,
            'dryRun' => $request->query('dry_run', '0') === '1',
            'limit' => min(50, max(1, (int) $request->integer('limit', 50))),
            'offset' => max(0, (int) $request->integer('offset', 0)),
            'onlyImported' => $request->query('only_imported', '1') !== '0',
            'missingOnly' => $request->query('missing_only', '0') === '1',
            'force' => $request->query('force', '1') !== '0',
            'maxBatches' => max(1, (int) $request->integer('max_batches', 20)),
            'delayMs' => max(0, (int) $request->integer('delay_ms', 500)),
            'stopOnErrors' => $request->query('stop_on_errors', '1') !== '0',
        ];

        return response($this->renderAutoRunner($config), 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    private function renderStartPage(Request $request): string
    {
        $query = $request->query();
        $query['auto'] = '1';
        $query += [
            'dry_run' => '0',
            'limit' => '50',
            'offset' => '0',
            'only_imported' => '1',
            'missing_only' => '0',
            'force' => '1',
            'max_batches' => '20',
            'delay_ms' => '500',
            'stop_on_errors' => '1',
        ];
        $startUrl = url('/tools/process-part-image-presentation-runner').'?'.http_build_query($query);

        return '<!doctype html><html lang="pl"><head><meta charset="utf-8"><title>Runner presentation zdjęć produktów</title></head><body>'
            .'<h1>Runner presentation zdjęć produktów</h1>'
            .'<p><strong>Tryb docelowy:</strong> ALL PRODUCTS / ALL IMPORTED. Runner używa <code>only_imported=1</code>, <code>missing_only=0</code>, <code>force=1</code>, <code>dry_run=0</code> i <code>limit=50</code>.</p>'
            .'<p>Ten adres bez <code>auto=1</code> niczego nie uruchamia. Auto-runner startuje dopiero po wejściu w URL z <code>auto=1</code>.</p>'
            .'<p>Runner odpala istniejący endpoint <code>/tools/process-part-image-presentation</code> batch po batchu. Nie usuwa oryginałów, nie usuwa <code>gpswiss-uploads</code> i nie zmienia ścieżek zdjęć w bazie.</p>'
            .'<p><a href="'.e($startUrl).'">Uruchom auto-runner od offset=0</a></p>'
            .'</body></html>';
    }

    private function renderValidationPage(Request $request, string $message): string
    {
        return '<!doctype html><html lang="pl"><head><meta charset="utf-8"><title>Błąd runnera presentation</title></head><body>'
            .'<h1>Błąd runnera presentation</h1><h2>Parametry</h2><pre>'.e(json_encode($request->query(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}').'</pre>'
            .'<h2>Błąd walidacji</h2><pre>'.e($message).'</pre></body></html>';
    }

    /** @param array<string, mixed> $config */
    private function renderAutoRunner(array $config): string
    {
        $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?: '{}';

        return str_replace('__AUTO_RUNNER_CONFIG__', $json, <<<'HTML'
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<title>Auto-runner presentation zdjęć produktów</title>
<style>
body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;margin:24px;line-height:1.4}code{background:#f6f8fa;padding:2px 4px;border-radius:4px}dl{display:grid;grid-template-columns:220px 1fr;gap:6px 14px;max-width:1100px}dt{font-weight:700}dd{margin:0}.status{font-size:20px;font-weight:700}.banner{background:#fff4ce;border:1px solid #d4a72c;border-radius:8px;padding:12px;max-width:1100px}pre{background:#111;color:#eee;padding:16px;overflow:auto;white-space:pre-wrap;border-radius:6px;max-width:1100px}a{overflow-wrap:anywhere}
</style>
</head>
<body>
<h1>Auto-runner presentation zdjęć produktów</h1>
<div class="banner"><strong>TRYB: ALL PRODUCTS / ALL IMPORTED</strong><br>Runner działa z <code>only_imported=1</code> i <code>missing_only=0</code>, więc przetwarza wszystkie importowane zdjęcia, nie tylko brakujące presentation.</div>
<p>Ta strona uruchamia istniejący endpoint <code>/tools/process-part-image-presentation</code> batch po batchu dopiero po załadowaniu URL z <code>auto=1</code>.</p>
<dl>
<dt>Status</dt><dd class="status" id="status">READY</dd>
<dt>Current offset</dt><dd id="currentOffset">0</dd>
<dt>Batch number</dt><dd id="batchNumber">0</dd>
<dt>Total scanned</dt><dd id="totalScanned">0</dd>
<dt>Total eligible</dt><dd id="totalEligible">0</dd>
<dt>Total processed</dt><dd id="totalProcessed">0</dd>
<dt>Total skipped</dt><dd id="totalSkipped">0</dd>
<dt>Total warnings</dt><dd id="totalWarnings">0</dd>
<dt>Total errors</dt><dd id="totalErrors">0</dd>
<dt>Next/current batch URL</dt><dd><a id="nextLink" href="#"></a></dd>
</dl>
<h2>Ostatni JSON batcha</h2><pre id="lastJson">{}</pre>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const config = __AUTO_RUNNER_CONFIG__;
    let offset = Number(config.offset || 0);
    let batchNumber = 0;
    let totals = {scanned: 0, eligible: 0, processed: 0, skipped: 0, warnings: 0, errors: 0};
    const el = (id) => document.getElementById(id);

    function boolParam(value) { return value ? '1' : '0'; }

    function buildBatchUrl(currentOffset) {
        const params = new URLSearchParams();
        params.set('token', config.token || '');
        params.set('limit', String(config.limit || 50));
        params.set('offset', String(currentOffset));
        params.set('dry_run', boolParam(config.dryRun));
        params.set('force', boolParam(config.force));
        params.set('only_imported', boolParam(config.onlyImported));
        params.set('missing_only', boolParam(config.missingOnly));
        return '/tools/process-part-image-presentation?' + params.toString();
    }

    function render(status) {
        if (status) el('status').textContent = status;
        el('currentOffset').textContent = String(offset);
        el('batchNumber').textContent = String(batchNumber);
        el('totalScanned').textContent = String(totals.scanned);
        el('totalEligible').textContent = String(totals.eligible);
        el('totalProcessed').textContent = String(totals.processed);
        el('totalSkipped').textContent = String(totals.skipped);
        el('totalWarnings').textContent = String(totals.warnings);
        el('totalErrors').textContent = String(totals.errors);
        const nextUrl = buildBatchUrl(offset);
        el('nextLink').href = nextUrl;
        el('nextLink').textContent = nextUrl;
    }

    function sleep(ms) { return new Promise((resolve) => setTimeout(resolve, ms)); }

    async function run() {
        render('RUNNING');
        while (batchNumber < Number(config.maxBatches || 20)) {
            const batchUrl = buildBatchUrl(offset);
            render('FETCHING');
            try {
                const response = await fetch(batchUrl, {headers: {Accept: 'application/json'}});
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
                offset = Number(json.next_offset || offset);
                render('RUNNING');

                if (Number(json.errors_count || 0) > 0 && config.stopOnErrors) {
                    render('STOPPED_ERRORS');
                    return;
                }
                if (json.completed === true) {
                    render('COMPLETED');
                    return;
                }

                await sleep(Number(config.delayMs || 500));
            } catch (error) {
                totals.errors += 1;
                el('lastJson').textContent = error && error.message ? error.message : String(error);
                render('JS_ERROR');
                return;
            }
        }
        render('STOPPED_MAX_BATCHES');
    }

    render('READY');
    run();
});
</script>
</body>
</html>
HTML);
    }
}
