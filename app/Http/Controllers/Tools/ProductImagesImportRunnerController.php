<?php

namespace App\Http\Controllers\Tools;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ProductImagesImportRunnerController extends Controller
{
    public function __invoke(Request $request)
    {
        $configuredToken = (string) env('PRODUCT_IMAGES_IMPORT_TOKEN', '');
        $requestToken = (string) $request->query('token', '');

        if ($configuredToken === '' || $requestToken === '' || ! hash_equals($configuredToken, $requestToken)) {
            abort(403);
        }

        $validator = Validator::make($request->query(), [
            'token' => ['required', 'string'],
            'batch_size' => ['nullable', 'integer', 'min:1', 'max:50'],
            'batches' => ['nullable', 'integer', 'min:1', 'max:10'],
            'start_offset' => ['nullable', 'integer', 'min:0'],
            'source_root' => ['required', 'string', 'max:500'],
            'copy_files' => ['required', Rule::in(['1', 1])],
            'sleep' => ['nullable', 'integer', 'min:0', 'max:10'],
        ], [
            'batch_size.max' => 'Parametr batch_size nie może być większy niż 50.',
            'batches.max' => 'Parametr batches nie może być większy niż 10.',
            'source_root.required' => 'Parametr source_root jest wymagany.',
            'copy_files.required' => 'Parametr copy_files=1 jest wymagany.',
            'copy_files.in' => 'Parametr copy_files musi być jawnie ustawiony jako copy_files=1.',
            'sleep.max' => 'Parametr sleep nie może być większy niż 10.',
        ]);

        $csvPath = storage_path('app/imports/product_images.csv');

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
        $sourceRoot = (string) $request->query('source_root');

        return response()->stream(function () use ($csvPath, $request, $batchSize, $batches, $startOffset, $sleep, $sourceRoot): void {
            set_time_limit(0);
            ignore_user_abort(true);

            $totals = [
                'files_copied' => 0,
                'part_images_created' => 0,
                'local_files_missing' => 0,
            ];
            $completedBatches = 0;
            $nextOffset = $startOffset;
            $failed = false;

            echo $this->renderHeader($csvPath, $request, $batchSize, $batches, $startOffset, $sleep);
            $this->flushOutput();

            for ($batch = 1; $batch <= $batches; $batch++) {
                $offset = $startOffset + (($batch - 1) * $batchSize);
                $nextOffset = $offset;

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

                foreach ($totals as $key => $value) {
                    $totals[$key] += $stats[$key] ?? 0;
                }

                echo $this->renderBatch($batch, $offset, $batchSize, $exitCode, $output);
                $this->flushOutput();

                if ($exitCode !== 0) {
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
            'source_root' => $request->query('source_root'),
            'copy_files' => $request->query('copy_files') === '1',
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
