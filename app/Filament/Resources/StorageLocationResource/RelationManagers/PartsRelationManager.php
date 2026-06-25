<?php

namespace App\Filament\Resources\StorageLocationResource\RelationManagers;

use App\Filament\Resources\PartResource;
use App\Models\Part;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PartsRelationManager extends RelationManager
{
    protected static string $relationship = 'parts';

    protected static ?string $title = 'Części w tym miejscu';

    protected static ?string $modelLabel = 'Część';

    protected static ?string $pluralModelLabel = 'Części';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'images:id,part_id,path,sort_order,is_primary',
                'marketplaceListings:id,part_id,marketplace,external_offer_id,price,currency,status,sync_status,match_status,last_error,url,last_api_status,last_seen_at,not_seen_in_active_api_at',
            ]))
            ->emptyStateHeading('Brak części w tym miejscu')
            ->emptyStateDescription(null)
            ->columns([
                Tables\Columns\ViewColumn::make('admin_part_image')
                    ->label('Zdjęcie')
                    ->view('filament.resources.parts.table-image')
                    ->viewData(fn (Part $record): array => ['part' => $record])
                    ->extraHeaderAttributes(['class' => 'gps-col-image'])
                    ->extraCellAttributes(['class' => 'gps-col-image'])
                    ->extraAttributes(['class' => 'gps-col-image-content']),
                Tables\Columns\ViewColumn::make('id')
                    ->label('ID')
                    ->view('filament.resources.parts.table-id')
                    ->viewData(fn (Part $record): array => ['part' => $record])
                    ->sortable()
                    ->searchable()
                    ->extraHeaderAttributes(['class' => 'gps-col-id'])
                    ->extraCellAttributes(['class' => 'gps-col-id'])
                    ->extraAttributes(['class' => 'gps-col-id-content']),
                Tables\Columns\ViewColumn::make('admin_part_title')
                    ->label('Nazwa części')
                    ->view('filament.resources.parts.table-title')
                    ->viewData(fn (Part $record): array => ['part' => $record])
                    ->searchable(['name', 'sku'])
                    ->extraHeaderAttributes(['class' => 'gps-col-title'])
                    ->extraCellAttributes(['class' => 'gps-col-title'])
                    ->extraAttributes(['class' => 'gps-col-title-content']),
                Tables\Columns\ViewColumn::make('admin_part_numbers')
                    ->label('Numer części')
                    ->view('filament.resources.parts.table-numbers')
                    ->viewData(fn (Part $record): array => ['part' => $record])
                    ->searchable(['part_number', 'oem_number', 'manufacturer_code'])
                    ->extraHeaderAttributes(['class' => 'gps-col-number'])
                    ->extraCellAttributes(['class' => 'gps-col-number'])
                    ->extraAttributes(['class' => 'gps-col-number-content']),
                Tables\Columns\ViewColumn::make('admin_part_channels')
                    ->label('Kanały sprzedaży')
                    ->view('filament.resources.parts.table-channels')
                    ->viewData(fn (Part $record): array => ['part' => $record])
                    ->extraHeaderAttributes(['class' => 'gps-col-channels'])
                    ->extraCellAttributes(['class' => 'gps-col-channels'])
                    ->extraAttributes(['class' => 'gps-col-channels-content']),
                Tables\Columns\TextColumn::make('quantity')
                    ->label('Ilość')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn (?string $state) => Part::statusOptions()[$state] ?? $state)
                    ->badge()
                    ->size('xs')
                    ->sortable()
                    ->extraHeaderAttributes(['class' => 'gps-col-status'])
                    ->extraCellAttributes(['class' => 'gps-col-status'])
                    ->extraAttributes(['class' => 'gps-col-status-content']),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Podgląd')
                    ->color('gray')
                    ->url(fn (Part $record): string => PartResource::getUrl('view', ['record' => $record])),
                Tables\Actions\EditAction::make()
                    ->label('Edytuj')
                    ->url(fn (Part $record): string => PartResource::getUrl('edit', ['record' => $record])),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->defaultSort('id', 'desc');
    }
}
