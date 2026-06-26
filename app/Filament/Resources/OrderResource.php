<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Models\Order;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
        return $table->columns([
            Tables\Columns\ViewColumn::make('order_card')
                ->label('Zamówienie')
                ->view('filament.resources.orders.table-order-card')
                ->viewData(fn (Order $record): array => ['order' => $record])
                ->searchable(['order_number', 'marketplace_order_id', 'customer_name', 'phone', 'company_name', 'email'])
                ->sortable(['ordered_at']),
        ])->filters([
            Tables\Filters\SelectFilter::make('marketplace')->label('Marketplace')->options(['allegro' => 'Allegro', 'ebay' => 'eBay', 'ovoko' => 'Ovoko', 'sklep' => 'Sklep']),
            Tables\Filters\SelectFilter::make('status')->label('Status')->options(Order::statusOptions()),
            Tables\Filters\TernaryFilter::make('test_import')->label('TEST IMPORT'),
            Tables\Filters\SelectFilter::make('source_batch')->label('Batch źródłowy')->options(fn (): array => Order::query()->whereNotNull('source_batch')->distinct()->pluck('source_batch', 'source_batch')->all()),
        ])->defaultSort('ordered_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['items', 'shipments']));
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
        return $infolist->schema([
            Infolists\Components\Section::make('Dane klienta')->columns(3)->schema([
                Infolists\Components\TextEntry::make('order_number')->label('Numer'),
                Infolists\Components\TextEntry::make('marketplace')->label('Marketplace')->badge()->placeholder('Sklep'),
                Infolists\Components\TextEntry::make('marketplace_order_id')->label('ID marketplace')->placeholder('—'),
                Infolists\Components\TextEntry::make('marketplace_status')->label('Status marketplace')->badge()->placeholder('—'),
                Infolists\Components\IconEntry::make('test_import')->label('TEST IMPORT')->boolean(),
                Infolists\Components\TextEntry::make('source_batch')->label('Batch')->placeholder('—'),
                Infolists\Components\TextEntry::make('status')->label('Status')->formatStateUsing(fn (string $state): string => Order::statusOptions()[$state] ?? $state)->badge(),
                Infolists\Components\TextEntry::make('total')->label('Suma')->money('PLN'),
                Infolists\Components\TextEntry::make('payment_status')->label('Płatność')->placeholder('—'),
                Infolists\Components\TextEntry::make('delivery_method')->label('Dostawa')->placeholder('—'),
                Infolists\Components\TextEntry::make('customer_name')->label('Klient'),
                Infolists\Components\TextEntry::make('email')->label('E-mail'),
                Infolists\Components\TextEntry::make('phone')->label('Telefon'),
                Infolists\Components\TextEntry::make('company_name')->label('Firma')->placeholder('—'),
                Infolists\Components\TextEntry::make('nip')->label('NIP')->placeholder('—'),
            ]),
            Infolists\Components\Section::make('Adres i uwagi')->schema([
                Infolists\Components\TextEntry::make('address_line1')->label('Ulica i numer'),
                Infolists\Components\TextEntry::make('postal_code')->label('Kod pocztowy'),
                Infolists\Components\TextEntry::make('city')->label('Miasto'),
                Infolists\Components\TextEntry::make('country')->label('Kraj'),
                Infolists\Components\TextEntry::make('notes')->label('Uwagi')->placeholder('—')->columnSpanFull(),
            ])->columns(4),
            Infolists\Components\Section::make('Pozycje zamówienia')->schema([
                Infolists\Components\RepeatableEntry::make('items')->label('')->schema([
                    Infolists\Components\TextEntry::make('product_name')->label('Produkt'),
                    Infolists\Components\TextEntry::make('part_number')->label('Numer części')->placeholder('—'),
                    Infolists\Components\TextEntry::make('sku')->label('SKU')->placeholder('—'),
                    Infolists\Components\TextEntry::make('unit_price')->label('Cena')->money('PLN'),
                    Infolists\Components\TextEntry::make('quantity')->label('Ilość'),
                    Infolists\Components\TextEntry::make('line_total')->label('Razem')->money('PLN'),
                ])->columns(6),
            ]),
        ]);
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
