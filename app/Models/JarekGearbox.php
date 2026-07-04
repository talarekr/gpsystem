<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class JarekGearbox extends Model
{
    public const LOCALIZED_IMAGES_DIRECTORY = 'jarek-gearboxes';
    public const LOCALIZED_IMAGES_SOURCE = 'storage/app/public/jarek-gearboxes';
    private const LOCALIZED_IMAGE_MAX_INDEX = 60;

    protected $fillable = [
        'source_account', 'allegro_account', 'allegro_offer_id', 'allegro_offer_url', 'title',
        'description', 'plain_description', 'price', 'currency', 'quantity', 'allegro_status',
        'main_image_url', 'images', 'category_id', 'category_name', 'category_path', 'category_payload', 'parameters', 'raw_payload',
        'import_status', 'imported_at', 'updated_from_allegro_at', 'ebay_status', 'ebay_listing_id',
        'ebay_offer_id', 'ebay_inventory_sku', 'ebay_payload_snapshot', 'ebay_published_at',
    ];

    /** @return array<int, string> */
    public function localizedImageUrls(): array
    {
        $offerId = $this->localizedImageOfferId();
        if ($offerId === null) return [];

        $urls = [];
        foreach (range(1, self::LOCALIZED_IMAGE_MAX_INDEX) as $index) {
            $relative = self::LOCALIZED_IMAGES_DIRECTORY.'/'.$offerId.'/'.str_pad((string) $index, 2, '0', STR_PAD_LEFT).'.jpg';
            if (Storage::disk('public')->exists($relative) || is_file(dirname(base_path()).'/public_html/storage/'.$relative)) {
                $urls[] = $this->publicStorageUrl($relative);
            }
        }

        return $urls;
    }

    /** @return array<int, string> */
    public function displayImageUrls(): array
    {
        $localized = $this->localizedImageUrls();
        if ($localized !== []) return $localized;

        return array_values(array_unique(array_filter(
            array_merge([$this->main_image_url], $this->flattenImageValues($this->images)),
            fn ($url): bool => is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false
        )));
    }

    /** @return array<int, string> */
    public function csvImageUrls(): array
    {
        return $this->localizedImageUrls();
    }

    public function localizedImagesSource(): string
    {
        return self::LOCALIZED_IMAGES_SOURCE;
    }

    private function localizedImageOfferId(): ?string
    {
        $raw = (string) ($this->allegro_offer_id ?: '');
        if ($raw === '') return null;

        $offerId = preg_replace('/[^A-Za-z0-9_-]+/', '-', $raw);
        return is_string($offerId) && $offerId !== '' ? $offerId : null;
    }

    private function publicStorageUrl(string $relativePath): string
    {
        return 'https://gpswiss.pl/storage/'.ltrim($relativePath, '/');
    }

    /** @return array<int, string> */
    private function flattenImageValues(mixed $value): array
    {
        if (is_string($value)) return [$value];
        if (! is_array($value)) return [];

        $urls = [];
        foreach ($value as $item) {
            $urls = array_merge($urls, $this->flattenImageValues($item));
        }

        return $urls;
    }

    protected $casts = [
        'price' => 'decimal:2',
        'quantity' => 'integer',
        'images' => 'array',
        'category_path' => 'array',
        'category_payload' => 'array',
        'parameters' => 'array',
        'raw_payload' => 'array',
        'ebay_payload_snapshot' => 'array',
        'imported_at' => 'datetime',
        'updated_from_allegro_at' => 'datetime',
        'ebay_published_at' => 'datetime',
    ];
}
