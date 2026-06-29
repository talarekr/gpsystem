<?php

namespace App\Filament\Pages\Marketplace;

use App\Models\MarketplaceSyncLog;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MarketplaceSyncLogs extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationGroup = 'Administracja marketplace';
    protected static ?string $navigationIcon = null;
    protected static ?string $navigationLabel = 'Logi';
    protected static ?string $title = 'Logi integracji API';
    protected static ?int $navigationSort = 3;
    protected static string $view = 'filament.pages.marketplace.sync-logs';

    public function table(Table $table): Table
    {
        return $table
            ->query(MarketplaceSyncLog::query()->with(['marketplaceListing', 'part', 'order', 'shipment']))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Czas')->dateTime('Y-m-d H:i:s')->sortable(),
                Tables\Columns\TextColumn::make('marketplace')->label('Integracja')->badge()->searchable()->sortable(),
                Tables\Columns\TextColumn::make('action')->label('Akcja')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (string $state): string => match ($state) {
                    'success' => 'success',
                    'error' => 'danger',
                    'warning' => 'warning',
                    default => 'gray',
                })->sortable(),
                Tables\Columns\TextColumn::make('related')->label('Powiązanie')->state(fn (MarketplaceSyncLog $record): string => collect([
                    $record->order_id ? 'Zam. #'.$record->order_id : null,
                    $record->shipment_id ? 'Przes. #'.$record->shipment_id : null,
                    $record->marketplace_listing_id ? 'Listing #'.$record->marketplace_listing_id : null,
                    $record->part_id ? 'Część #'.$record->part_id : null,
                ])->filter()->implode(' / ') ?: '—')->wrap(),
                Tables\Columns\TextColumn::make('http_status')->label('Kod/status')->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('message')->label('Komunikat')->searchable()->limit(80)->wrap(),
                Tables\Columns\TextColumn::make('duration_ms')->label('Czas ms')->sortable()->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('marketplace')->label('Integracja')->options(fn (): array => MarketplaceSyncLog::query()->distinct()->pluck('marketplace', 'marketplace')->filter()->all()),
                Tables\Filters\SelectFilter::make('status')->label('Status')->options(['success' => 'success', 'error' => 'error', 'warning' => 'warning', 'pending' => 'pending', 'not_ready' => 'not_ready']),
                Tables\Filters\Filter::make('created_at')->label('Data')
                    ->form([DatePicker::make('from')->label('Od'), DatePicker::make('until')->label('Do')])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date))),
                Tables\Filters\Filter::make('related')->label('Order/przesyłka')
                    ->form([TextInput::make('order_id')->label('Order ID')->numeric(), TextInput::make('shipment_id')->label('Shipment ID')->numeric()])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['order_id'] ?? null, fn (Builder $query, $id): Builder => $query->where('order_id', $id))
                        ->when($data['shipment_id'] ?? null, fn (Builder $query, $id): Builder => $query->where('shipment_id', $id))),
            ])
            ->actions([
                Tables\Actions\Action::make('details')
                    ->label('Szczegóły')
                    ->modalHeading(fn (MarketplaceSyncLog $record): string => 'Log API #'.$record->id)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Zamknij')
                    ->modalContent(fn (MarketplaceSyncLog $record) => view('filament.pages.marketplace.sync-log-details', ['log' => $record])),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100]);
    }
}
