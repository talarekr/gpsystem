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
            Tables\Columns\TextColumn::make('order_number')->label('Numer zamówienia')->searchable()->sortable()->weight('bold')->extraHeaderAttributes(['class' => 'gps-order-col gps-order-col-number'])->extraCellAttributes(['class' => 'gps-order-col gps-order-col-number']),
            Tables\Columns\TextColumn::make('marketplace')->label('Marketplace')->badge()->placeholder('Sklep')->sortable()->extraHeaderAttributes(['class' => 'gps-order-col'])->extraCellAttributes(['class' => 'gps-order-col']),
            Tables\Columns\TextColumn::make('marketplace_status')->label('Status marketplace')->badge()->toggleable()->extraHeaderAttributes(['class' => 'gps-order-col'])->extraCellAttributes(['class' => 'gps-order-col']),
            Tables\Columns\IconColumn::make('test_import')->label('TEST IMPORT')->boolean()->extraHeaderAttributes(['class' => 'gps-order-col'])->extraCellAttributes(['class' => 'gps-order-col']),
            Tables\Columns\TextColumn::make('source_batch')->label('Batch')->toggleable(isToggledHiddenByDefault: true)->extraHeaderAttributes(['class' => 'gps-order-col'])->extraCellAttributes(['class' => 'gps-order-col']),
            Tables\Columns\TextColumn::make('created_at')->label('Data')->dateTime('Y-m-d H:i')->sortable()->extraHeaderAttributes(['class' => 'gps-order-col'])->extraCellAttributes(['class' => 'gps-order-col']),
            Tables\Columns\TextColumn::make('customer_name')->label('Klient')->searchable()->extraHeaderAttributes(['class' => 'gps-order-col'])->extraCellAttributes(['class' => 'gps-order-col']),
            Tables\Columns\TextColumn::make('email')->label('E-mail')->searchable()->extraHeaderAttributes(['class' => 'gps-order-col'])->extraCellAttributes(['class' => 'gps-order-col']),
            Tables\Columns\TextColumn::make('phone')->label('Telefon')->searchable()->extraHeaderAttributes(['class' => 'gps-order-col'])->extraCellAttributes(['class' => 'gps-order-col']),
            Tables\Columns\TextColumn::make('status')->label('Status')->formatStateUsing(fn (string $state): string => Order::statusOptions()[$state] ?? $state)->badge()->extraHeaderAttributes(['class' => 'gps-order-col'])->extraCellAttributes(['class' => 'gps-order-col']),
            Tables\Columns\TextColumn::make('total')->label('Kwota')->money('PLN')->sortable()->extraHeaderAttributes(['class' => 'gps-order-col'])->extraCellAttributes(['class' => 'gps-order-col']),
        ])->filters([
            Tables\Filters\SelectFilter::make('marketplace')->label('Marketplace')->options(['allegro' => 'Allegro', 'ebay' => 'eBay', 'ovoko' => 'Ovoko']),
            Tables\Filters\SelectFilter::make('status')->label('Status')->options(Order::statusOptions()),
            Tables\Filters\TernaryFilter::make('test_import')->label('TEST IMPORT'),
            Tables\Filters\SelectFilter::make('source_batch')->label('Batch źródłowy')->options(fn (): array => Order::query()->whereNotNull('source_batch')->distinct()->pluck('source_batch', 'source_batch')->all()),
        ])->actions([
            Tables\Actions\ViewAction::make()->label('Szczegóły'),
            Tables\Actions\EditAction::make()->label('Zmień status'),
        ])->defaultSort('created_at', 'desc');
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
