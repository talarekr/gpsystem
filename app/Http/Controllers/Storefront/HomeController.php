<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Services\Storefront\CategoryTreeService;
use App\Services\Storefront\JarekFrCatalog;
use App\Support\StorefrontContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Schema;

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

        $sections = collect(self::SECTIONS)->mapWithKeys(function (string $path, string $label) use ($categoryTree, $sectionLabelKeys) {
            $category = $categoryTree->findByPublicPath($path);

            if (! $category) {
                return [__('storefront.'.($sectionLabelKeys[$label] ?? 'catalog')) => collect()];
            }

            return [__('storefront.'.($sectionLabelKeys[$label] ?? 'catalog')) => Part::query()
                ->with(['images', 'category'])
                ->withStorefrontTranslations(request()->attributes->get('storefront_locale', 'pl'))
                ->storefrontVisible()
                ->where('category_id', $category->id)
                ->latest('updated_at')
                ->limit(8)
                ->get()];
        });

        return view('storefront.home', [
            'sections' => $sections,
            'jarekProducts' => StorefrontContext::isFrench(request()) && Schema::hasTable('jarek_fr_products')
                ? app(JarekFrCatalog::class)->query()->latest('id')->limit(8)->get() : null,
            'metaTitle' => __('storefront.homepage_title'),
            'metaDescription' => __('storefront.homepage_desc'),
        ]);
    }
}
