<?php

namespace Tests\Concerns;

use App\Models\PartCategory;
use App\Services\Storefront\CategoryTreeService;

/** Keep unrelated regression fixtures on the canonical URL; redirect policy has separate tests. */
trait ResolvesFrenchCategoryUrls
{
    protected function frenchCategoryUrl(PartCategory|string $category): string
    {
        $tree = app(CategoryTreeService::class)->forLocale('fr');
        if (is_string($category)) {
            $category = $tree->findByPublicPath($category) ?? throw new \RuntimeException('Missing test category: '.$category);
        }

        return $tree->localizedUrl($category, 'fr');
    }
}
