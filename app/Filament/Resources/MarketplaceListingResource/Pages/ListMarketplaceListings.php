<?php
namespace App\Filament\Resources\MarketplaceListingResource\Pages;
use App\Filament\Resources\MarketplaceListingResource;
use App\Models\MarketplaceListing;
use Filament\Resources\Pages\ListRecords;

class ListMarketplaceListings extends ListRecords
{
    protected static string $resource = MarketplaceListingResource::class;
    protected static ?string $title = 'Administracja marketplace → Ovoko';
    protected function getHeaderWidgets(): array { return []; }
    protected function getHeaderActions(): array { return []; }
}
