<?php

namespace App\Http\Controllers\Tools;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use SplFileObject;

class ProductImagesImportRunnerController extends Controller
{
    private const DEFAULT_SOURCE_ROOT = 'storage/app/imports/gpswiss-uploads';

    public function __invoke(Request $request)
    {
        $configuredToken = (string) env('PRODUCT_IMAGES_IMPORT_TOKEN', '');
        $requestToken = (string) $request->query('token', '');

        if ($configuredToken === '' || $requestToken === '' || ! hash_equals($configuredToken, $requestToken)) {
            abort(403);
        }

        $csvPath = storage_path('app/imports/product_images.csv');

        if ($request->query('auto') === '1') {
            return $this->autoRunner($request, $csvPath);
        }

        if ($request->query('mode') === 'batch' || $request->query('ajax') === '1') {
            return $this->batchJson($request, $csvPath);
        }

        return $this->legacyRunner($request, $csvPath);
    }

    private function legacyRunner(Request $request, string $csvPath)
    {
        $validator = Validator::make($request->query(), [
            'token' => ['required', 'string'],
            'batch_size' => ['nullable', 'integer', 'min:1', 'max:50'],
            'batches' => ['nullable', 'integer', 'min:1', 'max:10'],
            'start_offset' => ['nullable', 'integer', 'min:0'],
            'source_root' => ['nullable', 'string', 'max:500'],
            'copy_files' => ['nullable', Rule::in(['1', 1])],
            'sleep' => ['nullable', 'integer', 'min:0', 'max:10'],
        ], [
            'batch_size.max' => 'Parametr batch_size nie może być większy niż 50.',
            'batches.max' => 'Parametr batches nie może być większy niż 10.',
            'copy_files.in' => 'Parametr copy_files musi być jawnie ustawiony jako copy_files=1.',
            'sleep.max' => 'Parametr sleep nie może być większy niż 10.',
        ]);

        if ($validator->fails()) {
            return response($this->renderValidationPage($csvPath, $request, $validator->errors()->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)), 422)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }

        if (! is_file($csvPath) || ! is_readable($csvPath)) {
            return response($this->renderValidationPage($csvPath, $request, 'Nie można odczytać CSV. Upewnij się, że plik istnieje: '.$csvPath), 404)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }

        $batchSize = (int) $request->integer('batch_size', 20);
        $batches = (int) $request->integer('batches', 1);
        $startOffset = (int) $request->integer('start_offset', 0);
        $sleep = (int) $request->integer('sleep', 2);
        $sourceRoot = (string) $request->query('source_root', self::DEFAULT_SOURCE_ROOT);

        return response()->stream(function () use ($csvPath, $request, $batchSize, $batches, $startOffset, $sleep, $sourceRoot): void {
            set_time_limit(0);
            ignore_user_abort(true);

            $totals = ['files_copied' => 0, 'part_images_created' => 0, 'local_files_missing' => 0];
            $completedBatches = 0;
            $nextOffset = $startOffset;
            $failed = false;

            echo $this->renderHeader($csvPath, $request, $batchSize, $batches, $startOffset, $sleep);
            $this->flushOutput();

            for ($batch = 1; $batch <= $batches; $batch++) {
                $offset = $startOffset + (($batch - 1) * $batchSize);
                $nextOffset = $offset;
                $result = $this->runImportBatch($csvPath, $batchSize, $offset, $sourceRoot);

                foreach ($totals as $key => $value) {
                    $totals[$key] += $result[$key] ?? 0;
                }

                echo $this->renderBatch($batch, $offset, $batchSize, (int) $result['exit_code'], (string) $result['raw_output']);
                $this->flushOutput();

                if ($result['exit_code'] !== 0) {
                    $failed = true;
                    break;
                }

                $completedBatches++;
                $nextOffset = $offset + $batchSize;

                if ($batch < $batches && $sleep > 0) {
                    sleep($sleep);
                }
            }

            echo $this->renderSummary($request, $completedBatches, $nextOffset, $totals, $failed);
            $this->flushOutput();
        }, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function batchJson(Request $request, string $csvPath): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'token' => ['required', 'string'],
            'mode' => ['nullable', Rule::in(['batch'])],
            'ajax' => ['nullable', Rule::in(['1', 1])],
            'offset' => ['nullable', 'integer', 'min:0'],
            'start_offset' => ['nullable', 'integer', 'min:0'],
            'batch_size' => ['nullable', 'integer', 'min:1', 'max:50'],
            'source_root' => ['nullable', 'string', 'max:500'],
            'copy_files' => ['nullable', Rule::in(['1', 1])],
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'validation_error', 'errors' => $validator->errors()], 422);
        }

        if (! is_file($csvPath) || ! is_readable($csvPath)) {
            return response()->json(['status' => 'csv_not_readable', 'errors' => ['csv' => 'Nie można odczytać CSV: '.$csvPath]], 404);
        }

        $batchSize = (int) $request->integer('batch_size', 20);
        $offset = (int) $request->integer('offset', (int) $request->integer('start_offset', 0));
        $sourceRoot = (string) $request->query('source_root', self::DEFAULT_SOURCE_ROOT);
        $totalProducts = $this->countCsvProductGroups($csvPath);
        $result = $this->runImportBatch($csvPath, $batchSize, $offset, $sourceRoot);
        $nextOffset = $offset + $batchSize;
        $completed = $nextOffset >= $totalProducts;

        return response()->json([
            'status' => $result['exit_code'] === 0 ? 'ok' : 'error',
            'current_offset' => $offset,
            'next_offset' => $completed ? null : $nextOffset,
            'batch_size' => $batchSize,
            'files_copied' => $result['files_copied'],
            'part_images_created' => $result['part_images_created'],
            'local_files_missing' => $result['local_files_missing'],
            'errors' => $result['errors'],
            'completed' => $completed,
            'summary' => $result['summary'],
            'raw_output' => $result['raw_output'],
        ]);
    }

    private function autoRunner(Request $request, string $csvPath)
    {
        $validator = Validator::make($request->query(), [
            'token' => ['required', 'string'],
            'auto' => ['required', Rule::in(['1', 1])],
            'start_offset' => ['nullable', 'integer', 'min:0'],
            'batch_size' => ['nullable', 'integer', 'min:1', 'max:50'],
            'sleep' => ['nullable', 'integer', 'min:1', 'max:3600'],
            'source_root' => ['nullable', 'string', 'max:500'],
            'copy_files' => ['nullable', Rule::in(['1', 1])],
            'stop_on_missing' => ['nullable', Rule::in(['0', '1', 0, 1])],
            'stop_on_errors' => ['nullable', Rule::in(['0', '1', 0, 1])],
            'max_batches' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response($this->renderValidationPage($csvPath, $request, $validator->errors()->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)), 422)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }

        if (! is_file($csvPath) || ! is_readable($csvPath)) {
            return response($this->renderValidationPage($csvPath, $request, 'Nie można odczytać CSV. Upewnij się, że plik istnieje: '.$csvPath), 404)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }

        $config = [
            'token' => (string) $request->query('token'),
            'startOffset' => (int) $request->integer('start_offset', 0),
            'batchSize' => (int) $request->integer('batch_size', 20),
            'sleep' => max(1, (int) $request->integer('sleep', 5)),
            'sourceRoot' => (string) $request->query('source_root', self::DEFAULT_SOURCE_ROOT),
            'copyFiles' => '1',
            'stopOnMissing' => $request->query('stop_on_missing', '1') !== '0',
            'stopOnErrors' => $request->query('stop_on_errors', '1') !== '0',
            'maxBatches' => $request->query('max_batches'),
        ];

        return response($this->renderAutoRunner($config), 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** @return array<string, mixed> */
    private function runImportBatch(string $csvPath, int $batchSize, int $offset, string $sourceRoot): array
    {
        $arguments = [
            'csvPath' => $csvPath,
            '--copy-files' => true,
            '--source-root' => $sourceRoot,
            '--limit' => $batchSize,
            '--offset' => $offset,
        ];

        $exitCode = Artisan::call('gps:import-product-images-from-csv', $arguments);
        $output = Artisan::output();
        $stats = $this->parseStats($output);

        return [
            'exit_code' => $exitCode,
            'files_copied' => $stats['files_copied'] ?? 0,
            'part_images_created' => $stats['part_images_created'] ?? 0,
            'local_files_missing' => $stats['local_files_missing'] ?? 0,
            'errors' => $exitCode === 0 ? 0 : 1,
            'summary' => $stats,
            'raw_output' => $output,
        ];
    }

    private function countCsvProductGroups(string $csvPath): int
    {
        $file = new SplFileObject($csvPath, 'rb');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::DROP_NEW_LINE | SplFileObject::SKIP_EMPTY);
        $headers = null;
        $wooIndex = null;
        $groups = [];
        foreach ($file as $index => $row) {
            if ($row === false || $row === [null]) continue;
            if ($headers === null) {
                $headers = array_map(fn (mixed $header): string => trim((string) $header), $row);
                $wooIndex = array_search('woo_product_id', $headers, true);
                continue;
            }
            $key = $wooIndex === false || $wooIndex === null ? '__missing_'.$index : trim((string) ($row[$wooIndex] ?? ''));
            $groups[$key !== '' ? $key : '__missing_'.$index] = true;
        }
        return count($groups);
    }

    private function renderValidationPage(string $csvPath, Request $request, string $message): string
    {
        return '<!doctype html><html lang="pl"><head><meta charset="utf-8"><title>Realny import zdjęć produktów — runner</title></head><body>'
            .'<h1>Realny import zdjęć produktów — runner</h1><p><strong>Ścieżka CSV:</strong> <code>'.e($csvPath).'</code></p>'
            .'<h2>Parametry</h2><pre>'.e(json_encode($this->displayParameters($request), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}').'</pre>'
            .'<h2>Błąd walidacji</h2><pre>'.e($message).'</pre></body></html>';
    }

    private function renderHeader(string $csvPath, Request $request, int $batchSize, int $batches, int $startOffset, int $sleep): string
    {
        return '<!doctype html><html lang="pl"><head><meta charset="utf-8"><title>Realny import zdjęć produktów — runner</title></head><body>'
            .'<h1>Realny import zdjęć produktów — runner</h1>'
            .'<p><strong>Tryb:</strong> realny import batchami; runner wymusza <code>--copy-files</code>, nie przekazuje <code>--skip-existing</code>, bez <code>--dry-run</code>.</p>'
            .'<p><strong>Ścieżka CSV:</strong> <code>'.e($csvPath).'</code></p>'
            .'<h2>Parametry runnera</h2><pre>'.e(json_encode($this->displayParameters($request) + [
                'batch_size_effective' => $batchSize,
                'batches_effective' => $batches,
                'start_offset_effective' => $startOffset,
                'sleep_effective' => $sleep,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}').'</pre>';
    }

    private function renderAutoRunner(array $config): string
    {
        $json = e(json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return <<<HTML
<!doctype html><html lang="pl"><head><meta charset="utf-8"><title>Auto runner importu zdjęć produktów</title><style>body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;margin:24px;line-height:1.4}button{margin-right:8px;padding:8px 14px}dl{display:grid;grid-template-columns:220px 1fr;gap:6px 14px;max-width:780px}dt{font-weight:700}dd{margin:0}pre{background:#111;color:#eee;padding:16px;overflow:auto;white-space:pre-wrap;border-radius:6px}.status{font-size:20px;font-weight:700}</style></head><body>
<h1>Auto runner importu zdjęć produktów</h1>
<p>Tryb wymusza <code>--copy-files</code>, nie przekazuje <code>--skip-existing</code> ani <code>--dry-run</code>.</p>
<p><strong>Możesz uruchomić auto-runner od <code>start_offset=0</code>, aby przejść cały import od początku.</strong> Importer jest idempotentny: istniejące rekordy <code>PartImage</code> są pomijane po <code>source_system + external_id</code> albo po <code>path</code>, niczego nie usuwa i dokłada tylko brakujące zdjęcia.</p>
<div><button id="start">Start</button><button id="pause">Pause</button><button id="resume">Resume</button><button id="stop">Stop</button></div>
<dl><dt>Status</dt><dd class="status" id="status">READY</dd><dt>Current offset</dt><dd id="currentOffset"></dd><dt>Next offset</dt><dd id="nextOffset"></dd><dt>Batch number</dt><dd id="batchNumber">0</dd><dt>Total files_copied</dt><dd id="totalFilesCopied">0</dd><dt>Total part_images_created</dt><dd id="totalPartImagesCreated">0</dd><dt>Total local_files_missing</dt><dd id="totalLocalFilesMissing">0</dd><dt>Total errors</dt><dd id="totalErrors">0</dd></dl>
<h2>Ostatnie summary</h2><pre id="summary">{}</pre><h2>Pełny log JSON</h2><pre id="log"></pre>
<script>const config=JSON.parse('$json');let running=false,paused=false,stopped=false,inFlight=false,nextOffset=Number(config.startOffset),currentOffsetDisplay=Number(config.startOffset),nextOffsetDisplay=Number(config.startOffset),batchNumber=0,totals={files_copied:0,part_images_created:0,local_files_missing:0,errors:0};const el=id=>document.getElementById(id);function render(status){if(status)el('status').textContent=status;el('currentOffset').textContent=currentOffsetDisplay;el('nextOffset').textContent=nextOffsetDisplay===null?'':nextOffsetDisplay;el('batchNumber').textContent=batchNumber;el('totalFilesCopied').textContent=totals.files_copied;el('totalPartImagesCreated').textContent=totals.part_images_created;el('totalLocalFilesMissing').textContent=totals.local_files_missing;el('totalErrors').textContent=totals.errors;}function sleep(ms){return new Promise(r=>setTimeout(r,ms));}function batchUrl(){const u=new URL(window.location.pathname,window.location.origin);u.searchParams.set('token',config.token);u.searchParams.set('mode','batch');u.searchParams.set('offset',nextOffset);u.searchParams.set('batch_size',config.batchSize);u.searchParams.set('source_root',config.sourceRoot);u.searchParams.set('copy_files','1');return u;}async function loop(){if(running||stopped)return;running=true;paused=false;render('RUNNING');while(!paused&&!stopped){if(config.maxBatches&&batchNumber>=Number(config.maxBatches)){stopped=true;render('STOPPED_MAX_BATCHES');break;}inFlight=true;let data;try{const response=await fetch(batchUrl(),{headers:{Accept:'application/json'}});data=await response.json();if(!response.ok)throw new Error(JSON.stringify(data));}catch(e){totals.errors++;el('summary').textContent=String(e);render('STOPPED_ERRORS');stopped=true;break;}finally{inFlight=false;}batchNumber++;totals.files_copied+=Number(data.files_copied||0);totals.part_images_created+=Number(data.part_images_created||0);totals.local_files_missing+=Number(data.local_files_missing||0);totals.errors+=Number(data.errors||0);el('summary').textContent=JSON.stringify(data.summary||data,null,2);el('log').textContent+=JSON.stringify(data,null,2)+'\n\n';currentOffsetDisplay=data.current_offset;nextOffsetDisplay=data.next_offset;render('RUNNING');if(data.completed){render('COMPLETED');stopped=true;break;}if(config.stopOnMissing&&Number(data.local_files_missing||0)>0){render('STOPPED_MISSING_FILES');stopped=true;break;}if(config.stopOnErrors&&Number(data.errors||0)>0){render('STOPPED_ERRORS');stopped=true;break;}nextOffset=Number(data.next_offset);await sleep(Number(config.sleep)*1000);}running=false;if(paused&&!stopped)render('PAUSED');}el('start').onclick=()=>{stopped=false;nextOffset=Number(config.startOffset);currentOffsetDisplay=nextOffset;nextOffsetDisplay=nextOffset;batchNumber=0;totals={files_copied:0,part_images_created:0,local_files_missing:0,errors:0};el('log').textContent='';loop();};el('pause').onclick=()=>{paused=true;if(!inFlight)render('PAUSED');};el('resume').onclick=()=>{if(!stopped){paused=false;loop();}};el('stop').onclick=()=>{stopped=true;paused=false;render('STOPPED');};render();</script></body></html>
HTML;
    }

    private function renderBatch(int $batch, int $offset, int $limit, int $exitCode, string $output): string
    {
        return '<h2>Batch '.$batch.'</h2><ul><li>offset: <code>'.$offset.'</code></li><li>limit: <code>'.$limit.'</code></li><li>kod wyjścia: <code>'.$exitCode.'</code></li></ul><pre>'.e($output).'</pre>';
    }

    private function renderSummary(Request $request, int $completedBatches, int $nextOffset, array $totals, bool $failed): string
    {
        $query = $request->query();
        $query['start_offset'] = $nextOffset;
        $nextUrl = url('/product-images-import-runner').'?'.http_build_query($query);

        return '<h2>Summary</h2>'
            .($failed ? '<p><strong>Błąd:</strong> runner zatrzymany po batchu z kodem wyjścia != 0.</p>' : '')
            .'<ul><li>wykonane batche: <code>'.$completedBatches.'</code></li><li>następny offset do użycia: <code>'.$nextOffset.'</code></li>'
            .'<li>suma files_copied: <code>'.$totals['files_copied'].'</code></li><li>suma part_images_created: <code>'.$totals['part_images_created'].'</code></li><li>suma local_files_missing: <code>'.$totals['local_files_missing'].'</code></li></ul>'
            .'<p><a href="'.e($nextUrl).'">Uruchom kolejne batche od next_offset='.$nextOffset.'</a></p></body></html>';
    }

    /** @return array<string, int> */
    private function parseStats(string $output): array
    {
        $stats = [];
        foreach (['files_copied', 'part_images_created', 'local_files_missing'] as $key) {
            if (preg_match('/\|\s*'.preg_quote($key, '/').'\s*\|\s*(\d+)\s*\|/', $output, $matches)) {
                $stats[$key] = (int) $matches[1];
            }
        }

        return $stats;
    }

    private function displayParameters(Request $request): array
    {
        return [
            'batch_size' => $request->query('batch_size', 20),
            'batches' => $request->query('batches', 1),
            'start_offset' => $request->query('start_offset', 0),
            'source_root' => $request->query('source_root', self::DEFAULT_SOURCE_ROOT),
            'copy_files' => $request->query('copy_files', '1') === '1',
            'sleep' => $request->query('sleep', 2),
            'skip_existing_wymuszony' => false,
            'dry_run_wymuszony' => false,
        ];
    }

    private function flushOutput(): void
    {
        if (function_exists('ob_flush')) {
            @ob_flush();
        }

        flush();
    }
}
