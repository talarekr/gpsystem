<?php

namespace App\Services\Storefront;

use App\Models\Part;
use App\Models\PartCategory;
use App\Support\StorefrontContext;
use Illuminate\Http\Request;

class StorefrontSeo
{
    private const PUBLIC_ROUTES = [
        'storefront.home', 'storefront.catalog', 'storefront.category', 'storefront.product',
        'storefront.contact', 'storefront.terms', 'storefront.privacy-policy',
        'storefront.jarek.index', 'storefront.jarek.show',
    ];

    public function filtered(Request $request): bool
    {
        return $request->routeIs('storefront.catalog', 'storefront.category', 'storefront.jarek.index')
            && collect(['q', 'part_number', 'category', 'producer', 'model', 'vehicle_model',
                'price_from', 'price_to', 'price_min', 'price_max', 'sort'])
                ->contains(fn ($key) => $request->filled($key));
    }

    public function privatePage(Request $request): bool
    {
        return $request->routeIs('storefront.*', 'password.*')
            && ! $request->routeIs(...self::PUBLIC_ROUTES)
            && ! $request->routeIs('storefront.fr.sitemap.*', 'storefront.fr-www.sitemap.*',
                'storefront.fr.robots', 'storefront.fr-www.robots');
    }

    public function metadata(Request $request, ?Part $part = null, ?PartCategory $category = null): array
    {
        $fr = StorefrontContext::isFrench($request);
        $path = '/'.ltrim($request->path(), '/');
        if ($part && $request->routeIs('storefront.product')) {
            $path = route('storefront.product', ['slug' => $part->slug ?: $part->id], false);
        } elseif ($category && $request->routeIs('storefront.category')) {
            $path = route('storefront.category', ['path' => $category->full_slug_path ?: $category->slug ?: $category->id], false);
        }
        $pageKey = $request->routeIs('storefront.jarek.index') ? 'jarek_page' : 'page';
        $page = filter_var($request->query($pageKey), FILTER_VALIDATE_INT);
        if ($request->routeIs('storefront.catalog', 'storefront.category', 'storefront.jarek.index') && ! $this->filtered($request) && $page > 1) {
            $path .= '?'.$pageKey.'='.$page;
        }
        $canonical = $fr ? 'https://gpswiss.fr'.$path : url()->current();
        $alternates = [];
        // Both sites serve the same public catalog/paths. Never fabricate alternates for auth,
        // signed pages, filters or PL-only operational routes. Unknown hosts retain legacy SEO.
        $public = $request->routeIs(...self::PUBLIC_ROUTES) && ! $this->filtered($request);
        if ($public && ! $request->routeIs('storefront.jarek.*') && in_array($request->getHost(), ['gpswiss.fr', 'gpswiss.pl'], true)) {
            $alternates = ['pl' => 'https://gpswiss.pl'.$path, 'fr' => 'https://gpswiss.fr'.$path,
                'x-default' => 'https://gpswiss.pl'.$path];
            // Normalize the PL counterpart too so self-hreflang points at its canonical URL.
            if (! $fr) {
                $canonical = 'https://gpswiss.pl'.$path;
            }
        }

        return ['canonical' => $canonical, 'alternates' => $alternates,
            'robots' => $fr && ($this->privatePage($request) || $this->filtered($request))
                ? ($this->filtered($request) ? 'noindex, follow' : 'noindex, noarchive') : null];
    }
}
