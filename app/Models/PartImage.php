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
        return $this->absolutePublicUrl();
    }

    public function relativePublicUrl(): ?string
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

    public function absolutePublicUrl(): ?string
    {
        $url = $this->relativePublicUrl();

        if ($url === null || Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }

        return url($url);
    }

    public function listingUrl(): ?string
    {
        if ($this->isImportedPhoto()) {
            return $this->absolutePublicUrl();
        }

        return $this->presentationUrl('listing_path') ?? $this->absolutePublicUrl();
    }

    public function listingScore(): ?float
    {
        $presentation = $this->legacy_payload['presentation'] ?? null;

        if (! is_array($presentation)) {
            return null;
        }

        $storedScore = $presentation['listing_score'] ?? null;

        if (is_numeric($storedScore)) {
            return (float) $storedScore;
        }

        return self::calculateListingScore(
            $presentation['listing_fill_width_ratio'] ?? data_get($presentation, 'metrics.listing.fill_ratio.width_ratio'),
            $presentation['listing_fill_height_ratio'] ?? data_get($presentation, 'metrics.listing.fill_ratio.height_ratio'),
            $presentation['listing_dominant_ratio'] ?? data_get($presentation, 'metrics.listing.fill_ratio.dominant_ratio')
        );
    }

    public static function calculateListingScore(mixed $widthRatio, mixed $heightRatio, mixed $dominantRatio): ?float
    {
        if (! is_numeric($widthRatio) || ! is_numeric($heightRatio) || ! is_numeric($dominantRatio)) {
            return null;
        }

        $widthRatio = max(0.0, min(1.2, (float) $widthRatio));
        $heightRatio = max(0.0, min(1.2, (float) $heightRatio));
        $dominantRatio = max(0.0, min(1.2, (float) $dominantRatio));

        $score = ($widthRatio * 58) + ($heightRatio * 28) + ($dominantRatio * 14);

        if ($widthRatio >= 0.65 && $heightRatio >= 0.55 && $dominantRatio >= 0.85) {
            $score += 18;
        }

        if ($widthRatio < 0.45) {
            $score -= (0.45 - $widthRatio) * 120;
        }

        if ($heightRatio < 0.45) {
            $score -= (0.45 - $heightRatio) * 70;
        }

        return round($score, 4);
    }

    public function productUrl(): ?string
    {
        if ($this->isImportedPhoto()) {
            return $this->absolutePublicUrl();
        }

        return $this->presentationUrl('product_path') ?? $this->absolutePublicUrl();
    }

    public function isImportedPhoto(): bool
    {
        return Str::startsWith(ltrim((string) $this->path, '/'), 'parts/photos/imported/');
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

        return url('/storage/'.$path);
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
