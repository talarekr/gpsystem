<?php

namespace App\Providers\Filament;

use App\Filament\Pages\AllegroIntegration;
use App\Filament\Pages\Analytics;
use App\Filament\Pages\CreateShipment;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\ErrorCenter;
use App\Filament\Pages\Help;
use App\Filament\Pages\ImportMigration\OvokoDonorCarImportPage;
use App\Filament\Pages\ImportMigration\WooProductImportPage;
use App\Filament\Pages\MobileIntake;
use App\Filament\Pages\Marketplace\MarketplaceOverview;
use App\Filament\Pages\Marketplace\MarketplaceSyncLogs;
use App\Filament\Pages\Orders;
use App\Filament\Pages\Pricing;
use App\Filament\Pages\ProductCatalog;
use App\Filament\Pages\ProductCommandCenter;
use App\Filament\Pages\Readiness;
use App\Filament\Pages\Settings;
use App\Filament\Pages\Shipments;
use App\Filament\Pages\Settings\AllegroSettings;
use App\Filament\Pages\Settings\AttributesParameters;
use App\Filament\Pages\Settings\AuditLog;
use App\Filament\Pages\Settings\AutomationQueueSettings;
use App\Filament\Pages\Settings\Categories;
use App\Filament\Pages\Settings\ChannelSettings;
use App\Filament\Pages\Settings\CompanyShopIdentity;
use App\Filament\Pages\Settings\EbayDeSettings;
use App\Filament\Pages\Settings\EbayFrSettings;
use App\Filament\Pages\Settings\EbaySettings;
use App\Filament\Pages\Settings\FeatureFlagsSafety;
use App\Filament\Pages\Settings\InternalLogisticsClasses;
use App\Filament\Pages\Settings\OvokoSettings;
use App\Filament\Pages\Settings\PricingSettings;
use App\Filament\Pages\Settings\ProductIntakeSettings;
use App\Filament\Pages\Settings\ProductSettings;
use App\Filament\Pages\Settings\ReadinessRules;
use App\Filament\Pages\Settings\StockWarehouseSettings;
use App\Filament\Pages\Settings\TranslationContentTemplates;
use App\Filament\Pages\Settings\WooCommerceSettings;
use App\Filament\Pages\StagingItems;
use App\Filament\Pages\StockLocations;
use App\Filament\Pages\UsersRoles;
use App\Filament\Pages\WooSyncPreparation;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\Blade;
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
            ->breadcrumbs(false)
            ->renderHook(
                'panels::head.end',
                fn (): string => Blade::render('@include(\'filament.admin-ui-refinements\')'),
            )
            ->renderHook(
                'panels::body.start',
                fn (): string => Blade::render('@include(\'filament.admin-topbar\')'),
            )
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
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->navigationGroups([
                // GPS Product Hub convention: avoid defining icons on both a group and its child items.
                NavigationGroup::make('Zamówienia')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->collapsible(false),
                NavigationGroup::make('Części')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->collapsible(false),
                NavigationGroup::make('Przesyłki')
                    ->icon('heroicon-o-paper-airplane')
                    ->collapsible(false),
                NavigationGroup::make('Magazyn')
                    ->icon('heroicon-o-building-office-2')
                    ->collapsible(false),
                NavigationGroup::make('Samochody')
                    ->icon('heroicon-o-truck')
                    ->collapsible(false),
                NavigationGroup::make('Kategorie')
                    ->icon('heroicon-o-tag')
                    ->collapsible(false),
                NavigationGroup::make('Wiadomości E-mail')
                    ->icon('heroicon-o-envelope')
                    ->collapsible(false),
                NavigationGroup::make('Administracja marketplace')
                    ->icon('heroicon-o-globe-alt')
                    ->collapsible(false),
            ])
            ->navigationItems([
                NavigationItem::make('Mapowanie')
                    ->group('Kategorie')
                    ->sort(10)
                    ->url(fn (): string => route('admin.marketplace-category-mapper.index'))
                    ->isActiveWhen(fn (): bool => request()->routeIs('admin.marketplace-category-mapper.*')),
            ])
            ->pages([
                Dashboard::class,
                Analytics::class,
                MobileIntake::class,
                StagingItems::class,
                ProductCatalog::class,
                ProductCommandCenter::class,
                Pricing::class,
                StockLocations::class,
                Readiness::class,
                WooSyncPreparation::class,
                Orders::class,
                CreateShipment::class,
                Shipments::class,
                ErrorCenter::class,
                AllegroIntegration::class,
                MarketplaceOverview::class,
                MarketplaceSyncLogs::class,
                Settings::class,
                CompanyShopIdentity::class,
                UsersRoles::class,
                ProductSettings::class,
                ProductIntakeSettings::class,
                Categories::class,
                AttributesParameters::class,
                PricingSettings::class,
                StockWarehouseSettings::class,
                InternalLogisticsClasses::class,
                ChannelSettings::class,
                WooCommerceSettings::class,
                EbaySettings::class,
                EbayDeSettings::class,
                EbayFrSettings::class,
                AllegroSettings::class,
                OvokoSettings::class,
                OvokoDonorCarImportPage::class,
                WooProductImportPage::class,
                TranslationContentTemplates::class,
                ReadinessRules::class,
                AutomationQueueSettings::class,
                FeatureFlagsSafety::class,
                AuditLog::class,
                Help::class,
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
