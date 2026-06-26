<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Models\Order;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Table;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationLabel = 'Zamówienia';
    protected static ?string $modelLabel = 'Zamówienie';
    protected static ?string $pluralModelLabel = 'Zamówienia';
    protected static ?int $navigationSort = 60;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('status')->label('Status')->options(Order::statusOptions())->required(),
            Forms\Components\Textarea::make('notes')->label('Uwagi')->rows(4)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table;
    }

    public static function displayOrderNumber(Order $order): string
    {
        $number = trim((string) ($order->marketplace_order_id ?: $order->order_number));

        if ($number === '') {
            return '—';
        }

        $marketplace = trim((string) $order->marketplace);

        if ($marketplace !== '') {
            $number = preg_replace('/^'.preg_quote($marketplace, '/').'-/i', '', $number) ?? $number;
        }

        return $number;
    }

    public static function formatOrderTotal(Order $order): string
    {
        if ($order->total === null) {
            return '—';
        }

        return number_format((float) $order->total, 2, ',', ' ').' '.($order->currency ?: 'PLN');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'view' => Pages\ViewOrder::route('/{record}'),
            'edit' => Pages\EditOrder::route('/{record}/edit'),
        ];
    }
}
