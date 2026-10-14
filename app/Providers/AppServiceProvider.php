<?php

namespace App\Providers;

use App\Models\Part;
use App\Observers\PartFrenchTranslationObserver;
use App\Services\Storefront\CartService;
use App\Services\Storefront\CategoryTreeService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Application services will be registered by bounded MVP tickets.
    }

    public function boot(): void
    {
        Part::observe(PartFrenchTranslationObserver::class);
        View::composer('storefront.*', function ($view): void {
            $view->with('storefrontContentLocale', request()->attributes->get('storefront_locale', 'pl'));
        });

        RateLimiter::for('fr-new-products-translations', fn (Request $request): Limit => Limit::perMinute(15)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('tools', function (Request $request): Limit {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        View::composer('storefront.partials.header', function ($view): void {
            $categoryTree = app(CategoryTreeService::class);

            try {
                $categoryRoots = $categoryTree->roots();
                $categoryShortcuts = $categoryTree->shortcuts();
            } catch (Throwable) {
                $categoryRoots = collect();
                $categoryShortcuts = [];
            }

            try {
                $cartCount = app(CartService::class)->count();
            } catch (Throwable) {
                $cartCount = 0;
            }

            $view->with([
                'storefrontCategoryRoots' => $categoryRoots,
                'storefrontCategoryShortcuts' => $categoryShortcuts,
                'categoryTreeService' => $categoryTree,
                'storefrontCartCount' => $cartCount,
            ]);
        });
    }
}
