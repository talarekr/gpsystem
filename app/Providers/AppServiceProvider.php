<?php

namespace App\Providers;

use App\Services\Storefront\CartService;
use App\Services\Storefront\CategoryTreeService;
use Illuminate\Support\Facades\View;
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

            $view->with([
                'storefrontCategoryRoots' => $categoryTree->roots(),
                'storefrontCategoryShortcuts' => $categoryTree->shortcuts(),
                'categoryTreeService' => $categoryTree,
                'storefrontCartCount' => app(CartService::class)->count(),
            ]);
        });
    }
}
