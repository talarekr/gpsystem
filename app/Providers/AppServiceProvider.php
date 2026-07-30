<?php

namespace App\Providers;

use App\Services\Storefront\CartService;
use App\Services\Storefront\CategoryTreeService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Throwable;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Application services will be registered by bounded MVP tickets.
    }

    public function boot(): void
    {
        // Final safety net: no code path (job, command or controller) may call an
        // eBay host while the application-level connection switch is disabled.
        Http::globalRequestMiddleware(function ($request) {
            $host = strtolower($request->getUri()->getHost());
            if (str_contains($host, 'ebay')) {
                app(\App\Services\Marketplace\EbayConnectionGate::class)->assertEnabled('external_api_request:'.$request->getMethod());
            }
            return $request;
        });

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
