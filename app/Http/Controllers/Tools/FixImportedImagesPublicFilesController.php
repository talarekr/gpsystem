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
            'copied' => 0,
            'already_exists' => 0,
            'missing_source' => 0,
            'errors' => 0,
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
                $target = public_path('storage/'.$path);

                try {
                    if (! is_file($source)) {
                        $summary['missing_source']++;
                        return;
                    }

                    $summary['source_exists']++;

                    if (is_file($target)) {
                        chmod($target, 0644);
                        $this->chmodDirectory(dirname($target));
                        $summary['already_exists']++;
                        return;
                    }

                    $this->ensureDirectory(dirname($target));
                    copy($source, $target);
                    chmod($target, 0644);
                    $summary['copied']++;
                } catch (Throwable $exception) {
                    $summary['errors']++;
                    if (count($summary['error_examples']) < 20) {
                        $summary['error_examples'][] = [
                            'part_image_id' => $image->id,
                            'path' => $path,
                            'source' => $source,
                            'target' => $target,
                            'message' => $exception->getMessage(),
                        ];
                    }
                }
            });

        return response()->json($summary, 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
