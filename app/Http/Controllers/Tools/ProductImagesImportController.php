<?php

namespace App\Http\Controllers\Tools;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ProductImagesImportController extends Controller
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
            'limit' => ['required', 'integer', 'min:1', 'max:500'],
            'product_id' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'skip_existing' => ['nullable', Rule::in(['1', '0', 1, 0, true, false, 'true', 'false'])],
            'source_root' => ['required', 'string', 'max:500'],
            'copy_files' => ['required', Rule::in(['1', 1])],
        ], [
            'limit.required' => 'Parametr limit jest wymagany.',
            'limit.max' => 'Parametr limit nie może być większy niż 500 na jedno uruchomienie.',
            'source_root.required' => 'Parametr source_root jest wymagany.',
            'copy_files.required' => 'Parametr copy_files=1 jest wymagany.',
            'copy_files.in' => 'Parametr copy_files musi być jawnie ustawiony jako copy_files=1.',
        ]);

        $csvPath = storage_path('app/imports/product_images.csv');

        if ($validator->fails()) {
            return response($this->renderPage(
                csvPath: $csvPath,
                parameters: $this->displayParameters($request),
                output: 'Nieprawidłowe parametry:'.PHP_EOL.$validator->errors()->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                exitCode: null,
            ), 422)->header('Content-Type', 'text/html; charset=UTF-8');
        }

        if (! is_file($csvPath) || ! is_readable($csvPath)) {
            return response($this->renderPage(
                csvPath: $csvPath,
                parameters: $this->displayParameters($request),
                output: 'Nie można odczytać CSV. Upewnij się, że plik istnieje: '.$csvPath,
                exitCode: null,
            ), 404)->header('Content-Type', 'text/html; charset=UTF-8');
        }

        $arguments = [
            'csvPath' => $csvPath,
            '--limit' => (int) $request->query('limit'),
            '--source-root' => (string) $request->query('source_root'),
            '--copy-files' => true,
        ];

        if ($request->boolean('skip_existing')) {
            $arguments['--skip-existing'] = true;
        }

        if ($request->filled('product_id')) {
            $arguments['--product-id'] = (string) $request->query('product_id');
        }

        $exitCode = Artisan::call('gps:import-product-images-from-csv', $arguments);
        $output = Artisan::output();

        return response($this->renderPage(
            csvPath: $csvPath,
            parameters: $this->displayParameters($request),
            output: $output,
            exitCode: $exitCode,
        ))->header('Content-Type', 'text/html; charset=UTF-8');
    }

    private function renderPage(string $csvPath, array $parameters, string $output, ?int $exitCode): string
    {
        $escapedCsvPath = e($csvPath);
        $escapedParameters = e(json_encode($parameters, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        $escapedOutput = e($output);
        $escapedExitCode = $exitCode === null ? 'nie uruchomiono' : (string) $exitCode;

        return <<<HTML
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title>Realny import zdjęć produktów</title>
</head>
<body>
    <h1>Realny import zdjęć produktów</h1>
    <p><strong>Tryb:</strong> realny import. Endpoint nie dodaje <code>--dry-run</code>.</p>
    <p><strong>Bezpieczeństwo:</strong> wymagane są <code>limit</code> maks. 500, <code>source_root</code> oraz jawne <code>copy_files=1</code>. Parametr <code>skip_existing=1</code> jest opcjonalny i domyślnie wyłączony; import standardowo tylko dokłada brakujące zdjęcia.</p>
    <p><strong>Ścieżka CSV:</strong> <code>{$escapedCsvPath}</code></p>
    <p><strong>Kod wyjścia komendy:</strong> <code>{$escapedExitCode}</code></p>
    <h2>Parametry</h2>
    <pre>{$escapedParameters}</pre>
    <h2>Wynik</h2>
    <pre>{$escapedOutput}</pre>
</body>
</html>
HTML;
    }

    private function displayParameters(Request $request): array
    {
        return [
            'limit' => $request->query('limit'),
            'product_id' => $request->query('product_id'),
            'skip_existing' => $request->boolean('skip_existing'),
            'source_root' => $request->query('source_root'),
            'copy_files' => $request->query('copy_files') === '1',
            'dry_run_wymuszony' => false,
        ];
    }
}
