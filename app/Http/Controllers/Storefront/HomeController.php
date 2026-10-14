<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Services\Storefront\CategoryTreeService;
use App\Services\Storefront\FrenchMixedCatalog;
use App\Support\StorefrontContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    private const SECTIONS = [
        'Silniki kompletne' => 'silnik-i-osprzet/silniki-i-osprzet/kompletne-silniki',
        'Skrzynia biegów' => 'uklad-napedowy/skrzynie-biegow-i-inne-elementy/automatyczna-skrzynia-biegow',
        'Filtry DPF' => 'uklad-wydechowy-i-inne-elementy/elementy-systemu-kontroli-spalin/filtr-czastek-stalych-katalizator-fap-dpf',
        'Zwrotnice' => 'os-przednia-i-inne-elementy/os-przednia/zwrotnica-kola-przedniego',
    ];

    public function index(CategoryTreeService $categoryTree): View
    {
        $sectionLabelKeys = [
            'Silniki kompletne' => 'engines',
            'Skrzynia biegów' => 'gearbox',
            'Filtry DPF' => 'dpf',
            'Zwrotnice' => 'knuckles',
        ];

        $paths = self::SECTIONS;
        if (StorefrontContext::isFrench(request())) {
            $paths['Skrzynia biegów'] = FrenchMixedCatalog::CATEGORY_PATH;
        }
        $sections = collect($paths)->mapWithKeys(function (string $path, string $label) use ($categoryTree, $sectionLabelKeys) {
            $category = $categoryTree->findByPublicPath($path);

            if (! $category) {
                return [__('storefront.'.($sectionLabelKeys[$label] ?? 'catalog')) => collect()];
            }

            $parts = Part::query()
                ->with(['images', 'category'])
                ->withStorefrontTranslations(request()->attributes->get('storefront_locale', 'pl'))
                ->storefrontVisible()
                ->where('category_id', $category->id)
                ->latest('updated_at');
            if (StorefrontContext::isFrench(request()) && $label === 'Skrzynia biegów') {
                // Home sections are curated; URL query filters must not select different supplier products.
                $homeRequest = Request::create(request()->getSchemeAndHttpHost().'/');
                $parts = app(FrenchMixedCatalog::class)->paginate($parts, $homeRequest, [(int) $category->id], 8)->getCollection();
            } else {
                $parts = $parts->limit(8)->get();
            }

            return [__('storefront.'.($sectionLabelKeys[$label] ?? 'catalog')) => $parts];
        });

        return view('storefront.home', [
            'sections' => $sections,
            'metaTitle' => __('storefront.homepage_title'),
            'metaDescription' => __('storefront.homepage_desc'),
        ]);
    }
}
