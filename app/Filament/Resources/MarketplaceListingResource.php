<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MarketplaceListingResource\Pages;
use App\Models\MarketplaceListing;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MarketplaceListingResource extends Resource
{
    protected static ?string $model = MarketplaceListing::class;
    protected static ?string $navigationGroup = 'Administracja marketplace';
    protected static ?string $navigationIcon = null;
    protected static ?string $navigationLabel = 'Ovoko';
    protected static ?int $navigationSort = 2;
    protected static ?string $modelLabel = 'Listing Ovoko';
    protected static ?string $pluralModelLabel = 'Ovoko';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('marketplace', 'ovoko')->with('part');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('part_id')->relationship('part', 'name')->searchable()->label('Produkt Laravel'),
            Forms\Components\TextInput::make('external_offer_id')->label('Ovoko ID')->disabled(),
            Forms\Components\TextInput::make('sku')->label('SKU'),
            Forms\Components\TextInput::make('title')->label('Nazwa Ovoko'),
            Forms\Components\TextInput::make('sync_status')->label('Sync status'),
            Forms\Components\TextInput::make('match_status')->label('Match status'),
            Forms\Components\TextInput::make('match_reason')->label('Powód'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query) => $query->with('part'))
            ->columns([
                Tables\Columns\TextColumn::make('external_offer_id')->label('Ovoko ID')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('part.name')->label('Produkt Laravel')->searchable()->wrap()->placeholder('—'),
                Tables\Columns\TextColumn::make('title')->label('Nazwa produktu')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('sku')->label('SKU')->searchable(),
                Tables\Columns\TextColumn::make('price')->label('Cena Ovoko')->money('PLN')->sortable(),
                Tables\Columns\TextColumn::make('quantity')->label('Stan Ovoko')->sortable(),
                Tables\Columns\TextColumn::make('status')->label('Status Ovoko'),
                Tables\Columns\TextColumn::make('match_status')->label('match_status')->badge()->searchable(),
                Tables\Columns\TextColumn::make('sync_status')->label('sync_status')->badge()->searchable(),
                Tables\Columns\TextColumn::make('match_confidence')->label('confidence')->sortable(),
                Tables\Columns\TextColumn::make('match_reason')->label('match_reason')->wrap(),
                Tables\Columns\TextColumn::make('last_synced_at')->label('last_synced_at')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('sync_status')->label('Status')->options(['mapped'=>'mapped','unmatched'=>'unmatched','conflict'=>'conflict','ignored'=>'ignored','sync_error'=>'sync_error']),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Podgląd'),
                Tables\Actions\Action::make('confirm')->label('Zatwierdź mapowanie')->action(fn (MarketplaceListing $record) => $record->update(['match_status'=>'confirmed','sync_status'=>'mapped','match_confidence'=>100])),
                Tables\Actions\Action::make('detach')->label('Odłącz')->requiresConfirmation()->action(fn (MarketplaceListing $record) => $record->update(['part_id'=>null,'match_status'=>'unmatched','sync_status'=>'unmatched','match_confidence'=>0,'match_reason'=>'manual_detach'])),
                Tables\Actions\Action::make('ignore')->label('Ignoruj')->action(fn (MarketplaceListing $record) => $record->update(['sync_status'=>'ignored'])),
                Tables\Actions\Action::make('conflict')->label('Oznacz konflikt')->action(fn (MarketplaceListing $record) => $record->update(['match_status'=>'conflict','sync_status'=>'conflict','match_reason'=>'manual_conflict'])),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListMarketplaceListings::route('/'), 'view' => Pages\ViewMarketplaceListing::route('/{record}')];
    }
}
