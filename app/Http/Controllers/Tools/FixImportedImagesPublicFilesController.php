<?php

namespace App\Http\Controllers\Tools;

use App\Models\PartImage;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Throwable;

class FixImportedImagesPublicFilesController extends Controller
{
    public function __invoke(Request $request)
    {
        $configuredToken = (string) env('PRODUCT_IMAGES_IMPORT_TOKEN', '');
        $requestToken = (string) $request->query('token', '');

        if ($configuredToken === '' || $requestToken === '' || ! hash_equals($configuredToken, $requestToken)) {
            abort(403);
        }

        $limit = max(1, min(5000, (int) $request->query('limit', 500)));
        $offset = max(0, (int) $request->query('offset', 0));

        $summary = [
            'total_imported_images' => 0,
            'source_exists' => 0,
            'public_target_copied' => 0,
            'already_exists' => 0,
            'missing_source' => 0,
            'errors' => 0,
            'target_examples' => [],
            'error_examples' => [],
            'limit' => $limit,
            'offset' => $offset,
        ];

        PartImage::query()
            ->where('path', 'like', 'parts/photos/imported/%')
            ->orderBy('id')
            ->offset($offset)
            ->limit($limit)
            ->get(['id', 'path'])
            ->each(function (PartImage $image) use (&$summary): void {
                $summary['total_imported_images']++;
                $path = ltrim((string) $image->path, '/');
                $source = storage_path('app/public/'.$path);

                try {
                    if (! is_file($source)) {
                        $summary['missing_source']++;
                        return;
                    }

                    $summary['source_exists']++;

                    $target = $this->publicTarget($path);
                    if (count($summary['target_examples']) < 20) {
                        $summary['target_examples'][] = [
                            'target_type' => 'public_target',
                            'target' => $target,
                        ];
                    }

                    if (is_file($target)) {
                        chmod($target, 0644);
                        $this->chmodDirectory(dirname($target));
                        $summary['already_exists']++;
                        return;
                    }

                    $this->ensureDirectory(dirname($target));
                    copy($source, $target);
                    chmod($target, 0644);
                    $summary['public_target_copied']++;
                } catch (Throwable $exception) {
                    $summary['errors']++;
                    if (count($summary['error_examples']) < 20) {
                        $summary['error_examples'][] = [
                            'part_image_id' => $image->id,
                            'path' => $path,
                            'source' => $source,
                            'message' => $exception->getMessage(),
                        ];
                    }
                }
            });

        return response()->json($summary, 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function publicTarget(string $relativePath): string
    {
        $storageRoot = $this->publicStorageRoot();

        return $storageRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    private function publicStorageRoot(): string
    {
        $documentRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), DIRECTORY_SEPARATOR);
        if ($documentRoot !== '') {
            return $documentRoot.DIRECTORY_SEPARATOR.'storage';
        }

        $siblingPublicHtml = dirname(base_path()).DIRECTORY_SEPARATOR.'public_html';
        if (is_dir($siblingPublicHtml)) {
            return $siblingPublicHtml.DIRECTORY_SEPARATOR.'storage';
        }

        return public_path('storage');
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $this->chmodDirectory($directory);
    }

    private function chmodDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            chmod($directory, 0755);
        }
    }
}
