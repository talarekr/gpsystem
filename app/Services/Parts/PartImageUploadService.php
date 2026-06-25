<?php

namespace App\Services\Parts;

use App\Models\Part;
use App\Models\PartImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PartImageUploadService
{
    private const DISK = 'public';
    private const IMPORTED_DIRECTORY = 'parts/photos/imported';
    private const ADMIN_DIRECTORY = 'parts/photos/admin';

    /**
     * @param  iterable<int, UploadedFile>  $uploadedFiles
     */
    public function attachUploadedImages(Part $part, iterable $uploadedFiles, ?string $sourceSystem = null): Collection
    {
        $storedPaths = [];

        try {
            foreach ($uploadedFiles as $uploadedFile) {
                if (! $uploadedFile instanceof UploadedFile || ! $uploadedFile->isValid()) {
                    throw new RuntimeException('Nie udało się odczytać przesłanego zdjęcia części.');
                }

                $path = $uploadedFile->store($this->originalUploadDirectory($part), self::DISK);

                if (! is_string($path) || $path === '' || ! Storage::disk(self::DISK)->exists($path)) {
                    throw new RuntimeException('Nie udało się zapisać przesłanego zdjęcia części.');
                }

                $this->mirrorOriginalToServedPublicStorage($path);

                $storedPaths[] = [
                    'path' => $path,
                    'source_system' => $sourceSystem,
                    'external_id' => basename($path),
                    'alt_text' => $uploadedFile->getClientOriginalName(),
                ];
            }

            return $this->syncStoredImages($part, $storedPaths);
        } catch (\Throwable $exception) {
            foreach ($storedPaths as $storedPath) {
                Storage::disk(self::DISK)->delete($storedPath['path']);
            }

            throw $exception;
        }
    }

    /**
     * @param  mixed  $images Paths from Filament FileUpload or arrays with image metadata.
     */
    public function syncStoredImages(Part $part, mixed $images): Collection
    {
        $items = collect($images ?? [])
            ->map(fn (mixed $image): array => $this->normalizeStoredImage($image))
            ->map(fn (array $image): array => $this->prepareStoredImageForPart($part, $image))
            ->filter(fn (array $image): bool => $image['path'] !== '')
            ->unique('path')
            ->values();

        $existingImages = $part->images()->get()->keyBy('path');
        $syncedImages = collect();

        foreach ($items as $index => $item) {
            $image = $existingImages->get($item['path']) ?? new PartImage(['path' => $item['path']]);
            $image->part_id = $part->id;
            $image->sort_order = $index;
            $image->is_primary = $index === 0;

            if ($item['source_system'] !== null) {
                $image->source_system = $item['source_system'];
            }

            if ($item['external_id'] !== null) {
                $image->external_id = $item['external_id'];
            }

            if ($item['alt_text'] !== null) {
                $image->alt_text = $item['alt_text'];
            }

            $image->save();
            $syncedImages->push($image);
        }

        return $syncedImages;
    }

    private function originalUploadDirectory(Part $part): string
    {
        return self::IMPORTED_DIRECTORY.'/'.((string) $part->getKey());
    }

    private function adminUploadDirectory(Part $part): string
    {
        return self::ADMIN_DIRECTORY.'/'.((string) $part->getKey());
    }

    private function prepareStoredImageForPart(Part $part, array $image): array
    {
        $path = $this->normalizePublicDiskPath($image['path'] ?? '');

        if ($path === '') {
            $image['path'] = '';

            return $image;
        }

        if ($this->isFlatPartPhotoUpload($path)) {
            $path = $this->moveFlatAdminUploadToPartDirectory($part, $path);
        }

        if (Storage::disk(self::DISK)->exists($path)) {
            $this->mirrorOriginalToServedPublicStorage($path);
        }

        $image['path'] = $path;

        return $image;
    }

    private function normalizePublicDiskPath(mixed $path): string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return '';
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $urlPath = parse_url($path, PHP_URL_PATH);
            $path = is_string($urlPath) ? $urlPath : $path;
        }

        $path = ltrim($path, '/');

        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        return $path;
    }

    private function isFlatPartPhotoUpload(string $path): bool
    {
        if (! str_starts_with($path, 'parts/photos/')) {
            return false;
        }

        $remainingPath = substr($path, strlen('parts/photos/'));

        return $remainingPath !== '' && ! str_contains($remainingPath, '/');
    }

    private function moveFlatAdminUploadToPartDirectory(Part $part, string $path): string
    {
        $targetPath = $this->adminUploadDirectory($part).'/'.basename($path);

        if ($path === $targetPath || Storage::disk(self::DISK)->exists($targetPath)) {
            return $targetPath;
        }

        if (! Storage::disk(self::DISK)->exists($path)) {
            return $path;
        }

        Storage::disk(self::DISK)->makeDirectory(dirname($targetPath));
        Storage::disk(self::DISK)->move($path, $targetPath);

        return $targetPath;
    }

    private function mirrorOriginalToServedPublicStorage(string $path): void
    {
        $source = Storage::disk(self::DISK)->path($path);
        $target = $this->servedPublicStoragePath($path);

        if ($source === $target || is_file($target)) {
            return;
        }

        $targetDirectory = dirname($target);

        if (! is_dir($targetDirectory) && ! mkdir($targetDirectory, 0755, true) && ! is_dir($targetDirectory)) {
            throw new RuntimeException('Nie udało się utworzyć publicznego katalogu dla zdjęcia części.');
        }

        if (! copy($source, $target)) {
            throw new RuntimeException('Nie udało się zapisać zdjęcia części w publicznym katalogu storage.');
        }

        chmod($target, 0644);
    }

    private function servedPublicStoragePath(string $path): string
    {
        return $this->servedPublicStorageRoot().DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($path, '/'));
    }

    private function servedPublicStorageRoot(): string
    {
        $configuredRoot = trim((string) config('filesystems.served_public_storage_root', ''));
        if ($configuredRoot !== '') {
            return rtrim($configuredRoot, DIRECTORY_SEPARATOR);
        }

        $documentRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), DIRECTORY_SEPARATOR);
        if ($documentRoot !== '') {
            return $documentRoot.DIRECTORY_SEPARATOR.'storage';
        }

        $siblingPublicHtmlStorage = dirname(base_path()).DIRECTORY_SEPARATOR.'public_html'.DIRECTORY_SEPARATOR.'storage';
        if (is_dir(dirname($siblingPublicHtmlStorage))) {
            return $siblingPublicHtmlStorage;
        }

        return public_path('storage');
    }

    private function normalizeStoredImage(mixed $image): array
    {
        if (is_array($image)) {
            $path = trim((string) ($image['path'] ?? $image['file'] ?? ''));

            return [
                'path' => $path,
                'source_system' => $image['source_system'] ?? null,
                'external_id' => $image['external_id'] ?? null,
                'alt_text' => $image['alt_text'] ?? null,
            ];
        }

        return [
            'path' => trim((string) $image),
            'source_system' => null,
            'external_id' => null,
            'alt_text' => null,
        ];
    }
}
