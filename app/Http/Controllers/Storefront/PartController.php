<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Models\PartCategory;
use App\Services\Storefront\CategoryTreeService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class PartController extends Controller
{
    public function show(Request $request, string $slug, CategoryTreeService $categoryTree): View
    {
        $locale = $request->attributes->get('storefront_locale', 'pl');
        $part = Part::query()
            ->with(['images', 'category', 'car'])
            ->withStorefrontTranslations($locale)
            ->storefrontVisible()
            ->where(fn (Builder $query) => $query->where('slug', $slug)->orWhere('id', $slug))
            ->firstOrFail();

        $related = Part::query()
            ->with(['images', 'category'])
            ->withStorefrontTranslations($locale)
            ->storefrontVisible()
            ->whereKeyNot($part->id)
            ->when($part->car_id, fn (Builder $query) => $query->forCar($part->car_id))
            ->limit(4)
            ->get();

        return view('storefront.parts.show', [
            'part' => $part,
            'related' => $related,
            'metaTitle' => $part->storefrontNameForLocale($locale).' - GPSwiss',
            'metaDescription' => str($locale === 'fr'
                ? ($part->storefrontShortDescriptionForLocale($locale) ?: $part->storefrontDescriptionForLocale($locale))
                : ($part->short_description ?: $part->description ?: __('storefront.default_desc')))->stripTags()->limit(155)->toString(),
            'breadcrumbs' => $this->breadcrumbs($part, $categoryTree, $locale),
        ]);
    }

    /** @return array<int, array{label:string, url?:string}> */
    private function breadcrumbs(Part $part, CategoryTreeService $categoryTree, string $locale): array
    {
        $breadcrumbs = [
            ['label' => __('storefront.home'), 'url' => route('storefront.home')],
        ];

        if ($part->category instanceof PartCategory) {
            $categoryPath = $categoryTree->ancestors($part->category)->push($part->category);

            foreach ($categoryPath as $category) {
                $breadcrumbs[] = [
                    'label' => $category->storefrontNameForLocale($locale),
                    'url' => $categoryTree->url($category),
                ];
            }
        }

        $breadcrumbs[] = ['label' => $part->storefrontNameForLocale($locale)];

        return $breadcrumbs;
    }
}
