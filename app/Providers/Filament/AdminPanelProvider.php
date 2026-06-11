<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\ErrorCenter;
use App\Filament\Pages\MobileIntake;
use App\Filament\Pages\Orders;
use App\Filament\Pages\Pricing;
use App\Filament\Pages\ProductCatalog;
use App\Filament\Pages\ProductCommandCenter;
use App\Filament\Pages\Readiness;
use App\Filament\Pages\Settings;
use App\Filament\Pages\StagingItems;
use App\Filament\Pages\StockLocations;
use App\Filament\Pages\UsersRoles;
use App\Filament\Pages\WooSyncPreparation;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('GPS Product Hub')
            ->colors([
                'primary' => [
                    50 => '#f5f7fa',
                    100 => '#e8edf4',
                    200 => '#cfd9e7',
                    300 => '#a6bad1',
                    400 => '#7695b7',
                    500 => '#54779e',
                    600 => '#405f82',
                    700 => '#354d69',
                    800 => '#0B1F3A',
                    900 => '#08182d',
                    950 => '#050f1d',
                ],
            ])
            ->pages([
                Dashboard::class,
                MobileIntake::class,
                StagingItems::class,
                ProductCatalog::class,
                ProductCommandCenter::class,
                Pricing::class,
                StockLocations::class,
                Readiness::class,
                WooSyncPreparation::class,
                Orders::class,
                ErrorCenter::class,
                Settings::class,
                UsersRoles::class,
            ])
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
