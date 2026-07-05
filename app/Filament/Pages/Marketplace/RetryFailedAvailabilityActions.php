<?php

namespace App\Filament\Pages\Marketplace;

use App\Enums\UserRole;
use App\Models\MarketplaceSyncLog;
use App\Services\Marketplace\FailedMarketplaceAvailabilityActionRetryService;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RetryFailedAvailabilityActions extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationGroup = 'Administracja marketplace';
    protected static ?string $navigationIcon = null;
    protected static ?string $navigationLabel = 'Retry błędów dostępności';
    protected static ?string $title = 'Retry failed marketplace availability actions';
    protected static ?int $navigationSort = 4;
    protected static string $view = 'filament.pages.marketplace.retry-failed-availability-actions';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(UserRole::OwnerAdmin->value) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(MarketplaceSyncLog::query()->with(['marketplaceListing', 'part'])->where('status', 'error')->whereIn('action', ['allegro_end_offer', 'allegro_activate_offer', 'availability_update', 'crm/changePartStatus', 'ebay_set_inventory_quantity']))
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('Log ID')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('created_at')->label('Czas')->dateTime('Y-m-d H:i:s')->sortable(),
                Tables\Columns\TextColumn::make('marketplace')->label('Marketplace')->badge()->searchable()->sortable(),
                Tables\Columns\TextColumn::make('action')->label('Action')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('marketplace_listing_id')->label('Listing ID')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('part_id')->label('Part ID')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('external_id')->label('External ID')->searchable(),
                Tables\Columns\TextColumn::make('http_status')->label('HTTP')->sortable(),
                Tables\Columns\TextColumn::make('message')->label('Message')->limit(80)->wrap(),
            ])
            ->filters([
                Filter::make('ids')->label('IDs')->form([
                    \Filament\Forms\Components\TextInput::make('log_id')->label('Log ID')->numeric(),
                    \Filament\Forms\Components\TextInput::make('part_id')->label('Part ID')->numeric(),
                    \Filament\Forms\Components\TextInput::make('marketplace_listing_id')->label('Marketplace listing ID')->numeric(),
                ])->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['log_id'] ?? null, fn (Builder $q, $id): Builder => $q->whereKey($id))
                    ->when($data['part_id'] ?? null, fn (Builder $q, $id): Builder => $q->where('part_id', $id))
                    ->when($data['marketplace_listing_id'] ?? null, fn (Builder $q, $id): Builder => $q->where('marketplace_listing_id', $id))),
                SelectFilter::make('marketplace')->label('Marketplace')->options(fn (): array => MarketplaceSyncLog::query()->where('status', 'error')->distinct()->pluck('marketplace', 'marketplace')->filter()->all()),
                SelectFilter::make('action')->label('Action')->options(fn (): array => MarketplaceSyncLog::query()->where('status', 'error')->distinct()->pluck('action', 'action')->filter()->all()),
            ])
            ->actions([
                Tables\Actions\Action::make('preview')->label('Dry-run')->modalSubmitAction(false)->modalCancelActionLabel('Zamknij')->modalContent(fn (MarketplaceSyncLog $record) => view('filament.pages.marketplace.retry-failed-availability-preview', ['preview' => app(FailedMarketplaceAvailabilityActionRetryService::class)->preview($record)])),
                Tables\Actions\Action::make('retry')->label('Retry target')->requiresConfirmation()->action(fn (MarketplaceSyncLog $record) => app(FailedMarketplaceAvailabilityActionRetryService::class)->retry($record))->visible(fn () => static::canAccess()),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100]);
    }
}
