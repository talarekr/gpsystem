<?php

namespace App\Models;

use App\Services\Images\PartImagePresentationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PartImage extends Model
{
    protected $fillable = ['source_system', 'external_id', 'part_id', 'path', 'alt_text', 'sort_order', 'is_primary', 'legacy_payload'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'sort_order' => 'integer', 'legacy_payload' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (PartImage $image): void {
            if (! $image->is_primary && $image->part_id && ! self::query()->where('part_id', $image->part_id)->exists()) {
                $image->is_primary = true;
            }
        });

        static::saved(function (PartImage $image): void {
            if (! $image->path || (! $image->wasRecentlyCreated && ! $image->wasChanged('path'))) {
                return;
            }

            $image->legacy_payload = app(PartImagePresentationService::class)->process($image);
            $image->saveQuietly();
        });
    }

    public function publicUrl(): ?string
    {
        $path = trim((string) $this->path);

        if ($path === '') {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        if (Str::startsWith($path, '/storage/')) {
            return $path;
        }

        if (Str::startsWith($path, 'storage/')) {
            return '/'.ltrim($path, '/');
        }

        if (Str::startsWith($path, '/')) {
            return $path;
        }

        $relativePath = ltrim($path, '/');

        if (Str::startsWith($relativePath, 'parts/photos/') && Storage::disk('public')->exists($relativePath)) {
            return '/storage/'.$relativePath;
        }

        if (Storage::disk('public')->exists($relativePath)) {
            return Storage::disk('public')->url($relativePath);
        }

        if (is_file(public_path($relativePath))) {
            return asset($relativePath);
        }

        return Storage::disk('public')->url($relativePath);
    }

    public function listingUrl(): ?string
    {
        return $this->presentationUrl('listing_path') ?? $this->publicUrl();
    }

    public function productUrl(): ?string
    {
        return $this->presentationUrl('product_path') ?? $this->publicUrl();
    }

    private function presentationUrl(string $key): ?string
    {
        $path = data_get($this->legacy_payload, 'presentation.'.$key);

        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $path = ltrim($path, '/');

        if (! Storage::disk('public')->exists($path)) {
            return null;
        }

        return '/storage/'.$path;
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
