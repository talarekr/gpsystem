<?php

namespace App\Filament\Resources\CarResource\Pages\Concerns;

use App\Models\Car;

trait ManagesCarImages
{
    /**
     * @var array<int, string>
     */
    protected array $photoPaths = [];

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function extractPhotoPaths(array $data): array
    {
        $this->photoPaths = $this->normalizePhotoPaths($data['photo_paths'] ?? []);
        unset($data['photo_paths']);

        $data['main_photo_path'] = $this->photoPaths[0] ?? null;

        return $data;
    }

    /**
     * @return array<int, string>
     */
    protected function normalizePhotoPaths(mixed $photoPaths): array
    {
        if (is_string($photoPaths)) {
            $photoPaths = [$photoPaths];
        }

        if (! is_array($photoPaths)) {
            return [];
        }

        return array_values(array_filter(
            $photoPaths,
            static fn (mixed $path): bool => is_string($path) && filled($path),
        ));
    }

    protected function syncCarImages(Car $car): void
    {
        $car->images()->delete();

        foreach ($this->photoPaths as $index => $path) {
            $car->images()->create([
                'path' => $path,
                'sort_order' => $index,
                'is_primary' => $index === 0,
            ]);
        }

        $car->forceFill([
            'main_photo_path' => $this->photoPaths[0] ?? null,
        ])->saveQuietly();
    }
}
