<?php

namespace App\Filament\Pages\Marketplace;

use App\Enums\UserRole;
use App\Models\MarketplaceSyncLog;
use App\Services\Marketplace\FailedMarketplaceOrderStatusRetryService;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class RetryFailedOrderStatusSyncs extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationGroup = 'Administracja marketplace';
    protected static ?string $navigationIcon = null;
    protected static ?string $navigationLabel = 'Retry statusów zamówień';
    protected static ?string $title = 'Retry błędów synchronizacji statusów zamówień';
    protected static ?int $navigationSort = 5;
    protected static string $view = 'filament.pages.marketplace.retry-failed-order-status-syncs';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(UserRole::OwnerAdmin->value) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(MarketplaceSyncLog::query()->with('order')->where('status', 'error')->whereIn('action', ['order_status_sync', 'ebay_create_shipping_fulfillment']))
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('Log ID')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('created_at')->label('Czas')->dateTime('Y-m-d H:i:s')->sortable(),
                Tables\Columns\TextColumn::make('marketplace')->label('Marketplace')->badge()->sortable()->searchable(),
                Tables\Columns\TextColumn::make('order_id')->label('Order ID')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('external_id')->label('Marketplace order ID')->searchable(),
                Tables\Columns\TextColumn::make('message')->label('Komunikat')->limit(100)->wrap(),
            ])
            ->actions([
                Tables\Actions\Action::make('preview')->label('Dry-run')->modalSubmitAction(false)->modalCancelActionLabel('Zamknij')->modalContent(fn (MarketplaceSyncLog $record) => view('filament.pages.marketplace.retry-failed-availability-preview', ['preview' => app(FailedMarketplaceOrderStatusRetryService::class)->preview($record)])),
                Tables\Actions\Action::make('retry')->label('Retry')->requiresConfirmation()->action(fn (MarketplaceSyncLog $record) => app(FailedMarketplaceOrderStatusRetryService::class)->retry($record))->visible(fn () => static::canAccess()),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100]);
    }
}
