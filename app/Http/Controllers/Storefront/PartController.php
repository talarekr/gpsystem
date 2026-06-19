<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Models\PartCategory;
use App\Services\Storefront\CategoryTreeService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

class PartController extends Controller
{
    public function show(string $slug, CategoryTreeService $categoryTree): View
    {
        $part = Part::query()
            ->with(['images', 'category', 'car'])
            ->storefrontVisible()
            ->where(fn (Builder $query) => $query->where('slug', $slug)->orWhere('id', $slug))
            ->firstOrFail();

        $related = Part::query()
            ->with(['images', 'category'])
            ->storefrontVisible()
            ->whereKeyNot($part->id)
            ->when($part->car_id, fn (Builder $query) => $query->forCar($part->car_id))
            ->limit(4)
            ->get();

        return view('storefront.parts.show', [
            'part' => $part,
            'related' => $related,
            'metaTitle' => $part->name.' - GPSwiss',
            'metaDescription' => str($part->short_description ?: $part->description ?: 'Używana część samochodowa GPSwiss.')->stripTags()->limit(155)->toString(),
            'breadcrumbs' => $this->breadcrumbs($part, $categoryTree),
        ]);
    }

    /** @return array<int, array{label:string, url?:string}> */
    private function breadcrumbs(Part $part, CategoryTreeService $categoryTree): array
    {
        $breadcrumbs = [
            ['label' => 'Strona główna', 'url' => route('storefront.home')],
        ];

        if ($part->category instanceof PartCategory) {
            $categoryPath = $categoryTree->ancestors($part->category)->push($part->category);

            foreach ($categoryPath as $category) {
                $breadcrumbs[] = [
                    'label' => $category->name,
                    'url' => $categoryTree->url($category),
                ];
            }
        }

        $breadcrumbs[] = ['label' => $part->name];

        return $breadcrumbs;
    }
}
