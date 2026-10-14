<?php

namespace App\Services\Storefront;

/** URLs only: no remote download or image processing during a public request. */
class JarekCatalogImage
{
    public bool $is_primary;

    public int $sort_order;

    public function __construct(private string $url, public string $alt_text, public int $id)
    {
        $this->is_primary = $id === 0;
        $this->sort_order = $id;
    }

    public function productUrl(): string
    {
        return $this->url;
    }

    public function listingUrl(): string
    {
        return $this->url;
    }

    public function publicUrl(): string
    {
        return $this->url;
    }
}
