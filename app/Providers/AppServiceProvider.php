<?php

namespace App\Providers;

use App\Services\Storefront\CartService;
use App\Services\Storefront\CategoryTreeService;
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
