<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Services\Storefront\CategoryTreeService;
use Illuminate\Contracts\View\View;

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
        $sections = collect(self::SECTIONS)->map(function (string $path) use ($categoryTree) {
            $category = $categoryTree->findByPublicPath($path);

            if (! $category) {
                return collect();
            }

            return Part::query()
                ->with(['images', 'category'])
                ->storefrontVisible()
                ->where('category_id', $category->id)
                ->latest('updated_at')
                ->limit(8)
                ->get();
        });

        return view('storefront.home', [
            'sections' => $sections,
            'metaTitle' => 'GPSwiss - używane części samochodowe',
            'metaDescription' => 'Największy wybór oryginalnych używanych części samochodowych GPSwiss.',
        ]);
    }
}
