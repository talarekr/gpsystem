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
    protected static ?string $navigationLabel = 'Listingi marketplace';
    protected static ?int $navigationSort = 2;
    protected static ?string $modelLabel = 'Listing marketplace';
    protected static ?string $pluralModelLabel = 'Listingi marketplace';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('marketplace', ['ovoko', 'allegro'])->with(['part', 'account']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('part_id')->relationship('part', 'name')->searchable()->label('Produkt Laravel'),
            Forms\Components\TextInput::make('marketplace')->label('Marketplace')->disabled(),
            Forms\Components\TextInput::make('external_offer_id')->label('External/listing ID')->disabled(),
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
                Tables\Columns\TextColumn::make('marketplace')->label('Marketplace')->badge()->searchable()->sortable(),
                Tables\Columns\TextColumn::make('account.code')->label('Channel/account')->searchable()->sortable()->placeholder('—'),
                Tables\Columns\TextColumn::make('external_offer_id')->label('External/listing ID')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('part.name')->label('Part')->searchable()->wrap()->placeholder('—'),
                Tables\Columns\TextColumn::make('sku')->label('SKU')->searchable(),
                Tables\Columns\TextColumn::make('match_status')->label('Mapping status')->badge()->searchable(),
                Tables\Columns\TextColumn::make('sync_status')->label('Sync status')->badge()->searchable(),
                Tables\Columns\TextColumn::make('url')->label('URL oferty')->url(fn (MarketplaceListing $record): ?string => $record->url)->openUrlInNewTab()->limit(40)->placeholder('—'),
                Tables\Columns\TextColumn::make('updated_at')->label('Updated at')->dateTime('Y-m-d H:i')->sortable(),
                Tables\Columns\TextColumn::make('title')->label('Nazwa produktu')->searchable()->wrap()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('price')->label('Cena')->money('PLN')->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('quantity')->label('Stan')->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('status')->label('Status zewn.')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('match_reason')->label('match_reason')->wrap()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('marketplace')->label('Marketplace')->options(['ovoko'=>'Ovoko','allegro'=>'Allegro']),
                Tables\Filters\SelectFilter::make('marketplace_account_id')->label('Channel/account')->relationship('account', 'code'),
                Tables\Filters\SelectFilter::make('sync_status')->label('Sync status')->options(['mapped'=>'mapped','unmatched'=>'unmatched','conflict'=>'conflict','ignored'=>'ignored','sync_error'=>'sync_error']),
                Tables\Filters\Filter::make('mapped')->query(fn (Builder $query): Builder => $query->where('sync_status', 'mapped')),
                Tables\Filters\Filter::make('conflict')->query(fn (Builder $query): Builder => $query->where('sync_status', 'conflict')),
                Tables\Filters\Filter::make('ignored')->query(fn (Builder $query): Builder => $query->where('sync_status', 'ignored')),
                Tables\Filters\Filter::make('sync_error')->query(fn (Builder $query): Builder => $query->where('sync_status', 'sync_error')),
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
