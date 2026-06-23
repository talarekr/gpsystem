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
