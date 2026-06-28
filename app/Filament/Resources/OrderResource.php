<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Models\Order;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Schema;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationGroup = 'Zamówienia';
    protected static ?string $navigationLabel = 'Zamówienia';
    protected static ?string $modelLabel = 'Zamówienie';
    protected static ?string $pluralModelLabel = 'Zamówienia';
    protected static ?int $navigationSort = 10;

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


    public static function getNewOrdersNavigationCount(): int
    {
        if (! Schema::hasTable('orders')) {
            return 0;
        }

        return Order::query()->where('status', 'new')->count();
    }

    public static function getAllOrdersNavigationCount(): int
    {
        if (! Schema::hasTable('orders')) {
            return 0;
        }

        return Order::query()->count();
    }

    /**
     * @return array<int, NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        if (! static::shouldRegisterNavigation() || ! static::canViewAny()) {
            return [];
        }

        return [
            NavigationItem::make(static::navigationLabelWithCount('Nowe', static::getNewOrdersNavigationCount()))
                ->group(static::getNavigationGroup())
                ->sort(static::getNavigationSort())
                ->url(static::getUrl('index', ['status' => 'new']))
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.orders.index') && request()->query('status') === 'new'),
            NavigationItem::make(static::navigationLabelWithCount('Wszystkie', static::getAllOrdersNavigationCount()))
                ->group(static::getNavigationGroup())
                ->sort((static::getNavigationSort() ?? 10) + 1)
                ->url(static::getUrl('index'))
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.orders.index') && request()->query('status') !== 'new'),
        ];
    }

    private static function navigationLabelWithCount(string $label, int $count): string
    {
        return sprintf('%s (%d)', $label, $count);
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
