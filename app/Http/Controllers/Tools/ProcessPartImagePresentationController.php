<?php

namespace App\Http\Controllers\Tools;

use App\Models\PartImage;
use App\Services\Images\PartImagePresentationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

class ProcessPartImagePresentationController extends Controller
{
    public function __construct(private readonly PartImagePresentationService $presentationService)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $configuredToken = (string) env('PRODUCT_IMAGES_IMPORT_TOKEN', '');
        $requestToken = (string) $request->query('token', '');

        if ($configuredToken === '' || $requestToken === '' || ! hash_equals($configuredToken, $requestToken)) {
            abort(403);
        }

        $dryRun = $request->boolean('dry_run', true);
        $limit = min(50, max(1, (int) $request->query('limit', 20)));
        $offset = max(0, (int) $request->query('offset', 0));
        $force = $request->boolean('force', true);
        $missingOnly = $request->boolean('missing_only', false);
        $onlyImported = $request->boolean('only_imported', false);
        $partId = (int) $request->query('part_id', 0);
        $imageId = (int) $request->query('image_id', 0);

        $images = PartImage::query()
            ->when($partId > 0, fn ($query) => $query->where('part_id', $partId))
            ->when($imageId > 0, fn ($query) => $query->whereKey($imageId))
            ->when($onlyImported, fn ($query) => $query->where('path', 'like', 'parts/photos/imported/%'))
            ->orderBy('id')
            ->offset($offset)
            ->limit($limit)
            ->get();

        $items = [];
        $eligible = 0;
        $processed = 0;
        $skipped = 0;
        $warningsCount = 0;
        $errorsCount = 0;

        foreach ($images as $image) {
            $before = $this->presentationSnapshot($image);
            $shouldProcess = ! $missingOnly || $this->needsPresentationProcessing($before);
            $action = $shouldProcess ? ($dryRun ? 'would_process' : 'processed') : 'skipped';
            $error = null;
            $after = $before;

            if ($shouldProcess) {
                $eligible++;

                if ($dryRun) {
                    $processed++;
                } else {
                    try {
                        $image->legacy_payload = $this->presentationService->process($image, $force);
                        $image->saveQuietly();
                        $image->refresh();
                        $after = $this->presentationSnapshot($image);
                        $processed++;
                    } catch (\Throwable $exception) {
                        $action = 'error';
                        $error = $exception->getMessage();
                        $errorsCount++;
                    }
                }
            } else {
                $skipped++;
            }

            $warnings = $after['warnings'];
            $warningsCount += count($warnings);

            $items[] = [
                'image_id' => $image->id,
                'part_id' => $image->part_id,
                'path' => $image->path,
                'is_imported_photo' => $image->isImportedPhoto(),
                'had_presentation_before' => $before['exists'],
                'listing_path_before' => $before['listing_path'],
                'product_path_before' => $before['product_path'],
                'listing_score_before' => $before['listing_score'],
                'action' => $action,
                'listing_path_after' => $after['listing_path'],
                'product_path_after' => $after['product_path'],
                'listing_score_after' => $after['listing_score'],
                'warnings' => $warnings,
                'error' => $error,
            ];
        }

        $scanned = $images->count();

        return response()->json([
            'dry_run' => $dryRun,
            'limit' => $limit,
            'offset' => $offset,
            'next_offset' => $offset + $scanned,
            'completed' => $scanned < $limit,
            'scanned' => $scanned,
            'eligible' => $eligible,
            'processed' => $processed,
            'skipped' => $skipped,
            'warnings_count' => $warningsCount,
            'errors_count' => $errorsCount,
            'force' => $force,
            'missing_only' => $missingOnly,
            'only_imported' => $onlyImported,
            'items' => $items,
        ], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array{exists: bool, listing_path: ?string, product_path: ?string, listing_score: mixed, warnings: array<int, mixed>, listing_exists: bool, product_exists: bool} */
    private function presentationSnapshot(PartImage $image): array
    {
        $presentation = data_get($image->legacy_payload, 'presentation');
        $exists = is_array($presentation);
        $listingPath = $exists ? $this->cleanRelativePath($presentation['listing_path'] ?? null) : null;
        $productPath = $exists ? $this->cleanRelativePath($presentation['product_path'] ?? null) : null;
        $warnings = $exists && is_array($presentation['warnings'] ?? null) ? array_values($presentation['warnings']) : [];

        return [
            'exists' => $exists,
            'listing_path' => $listingPath,
            'product_path' => $productPath,
            'listing_score' => $exists ? ($presentation['listing_score'] ?? null) : null,
            'warnings' => $warnings,
            'listing_exists' => $this->publicStorageFileExists($listingPath),
            'product_exists' => $this->publicStorageFileExists($productPath),
        ];
    }

    /** @param array{exists: bool, listing_path: ?string, product_path: ?string, warnings: array<int, mixed>, listing_exists: bool, product_exists: bool} $snapshot */
    private function needsPresentationProcessing(array $snapshot): bool
    {
        return ! $snapshot['exists']
            || $snapshot['listing_path'] === null
            || $snapshot['product_path'] === null
            || $snapshot['warnings'] !== []
            || ! $snapshot['listing_exists']
            || ! $snapshot['product_exists'];
    }

    private function publicStorageFileExists(?string $path): bool
    {
        return $path !== null && Storage::disk('public')->exists($path);
    }

    private function cleanRelativePath(mixed $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        return ltrim($path, '/');
    }
}
