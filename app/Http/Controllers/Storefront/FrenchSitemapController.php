<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\JarekFrProduct;
use App\Models\Part;
use App\Services\Storefront\CategoryTreeService;
use App\Support\StorefrontContext;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;

class FrenchSitemapController extends Controller
{
    public const PRODUCTS_PER_PAGE = 1000;

    public function index(): Response
    {
        $urls = ['https://gpswiss.fr/sitemaps/pages.xml'];
        $pages = (int) ceil(Part::query()->storefrontVisible()->count() / self::PRODUCTS_PER_PAGE);
        for ($page = 1; $page <= $pages; $page++) {
            $urls[] = 'https://gpswiss.fr/sitemaps/products-'.$page.'.xml';
        }

        if (Schema::hasTable('jarek_fr_products')) {
            for ($page = 1; $page <= (int) ceil(JarekFrProduct::query()->active()->count() / self::PRODUCTS_PER_PAGE); $page++) {
                $urls[] = 'https://gpswiss.fr/sitemaps/jarek-'.$page.'.xml';
            }
        }

        return response()->view('storefront.fr.sitemap-index', compact('urls'))
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function pages(CategoryTreeService $categories): Response
    {
        $urls = collect(['/', '/czesci', '/kontakt', '/regulamin', '/polityka-prywatnosci'])
            ->map(fn ($path) => ['loc' => 'https://gpswiss.fr'.$path]);
        foreach ($categories->all() as $category) {
            $publicPath = trim((string) ($category->full_slug_path ?: $category->slug), '/');
            // Match the actual public resolver; skip missing/ambiguous paths, never invent ID URLs.
            if ($publicPath === '' || $categories->findByPublicPath($publicPath)?->id !== $category->id) {
                continue;
            }
            $path = route('storefront.category', ['path' => $publicPath], false);
            $urls->push(['loc' => 'https://gpswiss.fr'.$path, 'lastmod' => $category->updated_at?->toAtomString()]);
        }

        return $this->xml($urls->unique('loc')->all());
    }

    public function products(int $page): Response
    {
        abort_if($page < 1 || $page > (int) ceil(Part::query()->storefrontVisible()->count() / self::PRODUCTS_PER_PAGE), 404);
        $urls = Part::query()->storefrontVisible()->orderBy('id')
            ->offset(($page - 1) * self::PRODUCTS_PER_PAGE)->limit(self::PRODUCTS_PER_PAGE)
            ->get(['id', 'slug', 'updated_at'])->map(fn ($part) => [
                'loc' => 'https://gpswiss.fr'.route('storefront.product', ['slug' => $part->slug ?: $part->id], false),
                'lastmod' => $part->updated_at?->toAtomString(),
            ])->all();

        return $this->xml($urls);
    }

    public function jarek(int $page): Response
    {
        abort_unless(StorefrontContext::isFrench(request()) && Schema::hasTable('jarek_fr_products'), 404);
        $query = JarekFrProduct::query()->active();
        abort_if($page < 1 || $page > (int) ceil((clone $query)->count() / self::PRODUCTS_PER_PAGE), 404);
        $urls = $query->orderBy('id')->offset(($page - 1) * self::PRODUCTS_PER_PAGE)->limit(self::PRODUCTS_PER_PAGE)
            ->get()->map(fn ($product) => ['loc' => 'https://gpswiss.fr'.route('storefront.jarek.show', $product->id, false),
                'lastmod' => $product->updated_at?->toAtomString()])->all();

        return $this->xml($urls);
    }

    public function robots(): Response
    {
        $blocked = ['/admin', '/tools', '/livewire', '/auth/', '/payments/', '/payu/', '/api-info',
            '/panier', '/commande', '/koszyk', '/zamowienie', '/moje-konto', '/logowanie', '/login',
            '/rejestracja', '/przypomnij-haslo', '/reset-hasla', '/szukaj', '/*?*q=', '/*?*sort='];
        $text = "User-agent: *\n";
        foreach ($blocked as $path) {
            $text .= 'Disallow: '.$path."\n";
        }
        $text .= "Sitemap: https://gpswiss.fr/sitemap.xml\n";

        return response($text)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    private function xml(array $urls): Response
    {
        return response()->view('storefront.fr.sitemap-urls', compact('urls'))
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
