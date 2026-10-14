<?php

namespace App\Services\Storefront;

use App\Models\JarekFrProduct;
use App\Models\PartCategory;
use Illuminate\Support\Collection;

/** Read-only view adapter, deliberately not a Part or a persisted stock item. */
class JarekCatalogProduct
{
    public string $id;

    public string $sku;

    public ?string $part_number = null;

    public string $currency = 'EUR';

    public ?float $price;

    public int $quantity = 1;

    public string $status = 'ready';

    public ?int $car_id = null;

    public Collection $images;

    public array $presentation;

    public function __construct(public JarekFrProduct $exposure, public ?PartCategory $category = null)
    {
        $this->presentation = app(JarekFrPresentation::class)->forProduct($exposure);
        $this->id = 'jarek-'.$exposure->id;
        $this->sku = $this->presentation['sku'];
        $this->price = $this->presentation['cents'] === null ? null : $this->presentation['cents'] / 100;
        $title = $this->presentation['title'];
        $this->images = collect($this->presentation['images'])->map(fn (string $url, int $index) => new JarekCatalogImage($url, $title, $index));
    }

    public function storefrontNameForLocale(string $locale): string
    {
        return $this->presentation['title'];
    }

    public function storefrontDescriptionForLocale(string $locale): string
    {
        return $this->presentation['description'];
    }

    public function storefrontDetailsForLocale(string $locale): array
    {
        return [];
    }

    public function primaryImage(): ?JarekCatalogImage
    {
        return $this->images->first();
    }

    public function listingImage(): ?JarekCatalogImage
    {
        return $this->primaryImage();
    }

    public function listingImageUrl(): ?string
    {
        return $this->primaryImage()?->listingUrl();
    }
}
