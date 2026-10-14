<?php

namespace App\Services\Storefront;

use App\Services\Images\JarekImagePresentation;

/** URLs only: no remote download or image processing during a public request. */
class JarekCatalogImage
{
    public bool $is_primary;

    public int $sort_order;

    private ?array $variants = null;

    public function __construct(private string $url, public string $alt_text, public int $id)
    {
        $this->is_primary = $id === 0;
        $this->sort_order = $id;
    }

    public function productUrl(): string
    {
        return $this->variant('product');
    }

    public function listingUrl(): string
    {
        return $this->variant('listing');
    }

    public function listingScore(): ?float
    {
        $this->variant('listing');

        return $this->variants['listing_score'];
    }

    private function variant(string $kind): string
    {
        $this->variants ??= app(JarekImagePresentation::class)->variants($this->url);

        return $this->variants[$kind];
    }

    public function publicUrl(): string
    {
        return $this->url;
    }
}
