<?php

namespace App\Http\Controllers\Tools;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

class ImportedImagesStorageReportController extends Controller
{
    private const SAMPLE_RELATIVE_PATH = 'parts/photos/imported/2083/9db7e2614347b1e4cfc4d94a9150.jpg';

    public function __invoke(Request $request)
    {
        $configuredToken = (string) env('PRODUCT_IMAGES_IMPORT_TOKEN', '');
        $requestToken = (string) $request->query('token', '');

        if ($configuredToken === '' || $requestToken === '' || ! hash_equals($configuredToken, $requestToken)) {
            abort(403);
        }

        $publicStoragePath = public_path('storage');
        $storagePublicPath = storage_path('app/public');
        $documentRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), DIRECTORY_SEPARATOR);
        $siblingPublicHtml = dirname(base_path()).DIRECTORY_SEPARATOR.'public_html';

        return response()->json([
            'mode' => 'report_only',
            'safety' => 'Ten endpoint tylko raportuje. Nie usuwa ani nie modyfikuje plików.',
            'directories' => [
                $this->directoryReport('imports_gpswiss_uploads', storage_path('app/imports/gpswiss-uploads')),
                $this->directoryReport('storage_app_public_parts_photos_imported', storage_path('app/public/parts/photos/imported')),
                $this->directoryReport('public_html_storage_parts_photos_imported', $siblingPublicHtml.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'parts'.DIRECTORY_SEPARATOR.'photos'.DIRECTORY_SEPARATOR.'imported'),
                $this->directoryReport('app_public_storage_parts_photos_imported', public_path('storage/parts/photos/imported')),
            ],
            'public_storage' => [
                'path' => $publicStoragePath,
                'exists' => file_exists($publicStoragePath),
                'is_symlink' => is_link($publicStoragePath),
                'realpath' => realpath($publicStoragePath) ?: null,
                'expected_target' => $storagePublicPath,
                'expected_target_realpath' => realpath($storagePublicPath) ?: null,
                'points_to_expected_target' => $this->pointsToExpectedTarget($publicStoragePath, $storagePublicPath),
            ],
            'paths' => [
                'public_path' => public_path(),
                'public_path_realpath' => realpath(public_path()) ?: null,
                'base_path' => base_path(),
                'base_path_realpath' => realpath(base_path()) ?: null,
                'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? null,
                'document_root_realpath' => $documentRoot === '' ? null : (realpath($documentRoot) ?: null),
                'sibling_public_html' => $siblingPublicHtml,
                'sibling_public_html_realpath' => realpath($siblingPublicHtml) ?: null,
            ],
            'sample_file' => [
                'relative_path' => self::SAMPLE_RELATIVE_PATH,
                'locations' => [
                    'storage_app_public' => $this->fileReport(storage_path('app/public/'.self::SAMPLE_RELATIVE_PATH)),
                    'app_public_storage' => $this->fileReport(public_path('storage/'.self::SAMPLE_RELATIVE_PATH)),
                    'public_html_storage' => $this->fileReport($siblingPublicHtml.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, self::SAMPLE_RELATIVE_PATH)),
                ],
            ],
        ], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    private function directoryReport(string $label, string $path): array
    {
        $exists = file_exists($path);
        $isDir = is_dir($path);
        $sizeBytes = 0;
        $filesCount = 0;
        $errors = [];

        if ($isDir) {
            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );

                foreach ($iterator as $fileInfo) {
                    try {
                        if (! $fileInfo->isFile()) {
                            continue;
                        }

                        $filesCount++;
                        $sizeBytes += $fileInfo->getSize();
                    } catch (Throwable $exception) {
                        if (count($errors) < 20) {
                            $errors[] = $exception->getMessage();
                        }
                    }
                }
            } catch (Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        return [
            'label' => $label,
            'path' => $path,
            'exists' => $exists,
            'is_dir' => $isDir,
            'size_bytes' => $sizeBytes,
            'size_human' => $this->humanSize($sizeBytes),
            'files_count' => $filesCount,
            'errors' => $errors,
        ];
    }

    /** @return array{path: string, exists: bool, is_file: bool, size_bytes: int|null, size_human: string|null} */
    private function fileReport(string $path): array
    {
        $isFile = is_file($path);
        $sizeBytes = $isFile ? filesize($path) : null;

        return [
            'path' => $path,
            'exists' => file_exists($path),
            'is_file' => $isFile,
            'size_bytes' => $sizeBytes,
            'size_human' => $sizeBytes === null ? null : $this->humanSize($sizeBytes),
        ];
    }

    private function pointsToExpectedTarget(string $path, string $expectedTarget): bool
    {
        $pathRealpath = realpath($path);
        $expectedRealpath = realpath($expectedTarget);

        return $pathRealpath !== false && $expectedRealpath !== false && $pathRealpath === $expectedRealpath;
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float) $bytes;
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return $unitIndex === 0
            ? $bytes.' '.$units[$unitIndex]
            : number_format($size, 2, '.', ' ').' '.$units[$unitIndex];
    }
}
