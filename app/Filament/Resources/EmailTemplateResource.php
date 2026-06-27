<?php

namespace App\Filament\Resources;

use App\Enums\EmailTemplateType;
use App\Filament\Resources\EmailTemplateResource\Pages;
use App\Models\EmailTemplate;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class EmailTemplateResource extends Resource
{
    protected static ?string $model = EmailTemplate::class;
    protected static ?string $navigationIcon = 'heroicon-o-envelope';
    protected static ?string $navigationLabel = 'Wiadomości E-mail';
    protected static ?string $modelLabel = 'szablon e-mail';
    protected static ?string $pluralModelLabel = 'Wiadomości E-mail';
    protected static ?int $navigationSort = 62;

    public static function form(Form $form): Form
    {
        return $form
            ->columns(1)
            ->schema([
                Section::make('Dane szablonu')
                    ->description('Lokalny szablon sklepu. Zmiany nie uruchamiają wysyłki e-mail ani żadnego API marketplace.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('template_key')
                            ->label('Typ / klucz szablonu')
                            ->options(EmailTemplateType::options())
                            ->disabled()
                            ->dehydrated()
                            ->required(),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Aktywny')
                            ->inline(false),
                        Forms\Components\TextInput::make('name')
                            ->label('Nazwa w adminie')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('subject')
                            ->label('Temat wiadomości')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('body')
                            ->label('Treść wiadomości')
                            ->required()
                            ->rows(14)
                            ->columnSpanFull(),
                        Forms\Components\Placeholder::make('available_placeholders')
                            ->label('Dostępne placeholdery')
                            ->content(new HtmlString('<code>{customer_name}</code>, <code>{order_number}</code>, <code>{order_total}</code>, <code>{payment_url}</code>, <code>{tracking_number}</code>, <code>{return_url}</code>, <code>{password_reset_url}</code>'))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmailTemplates::route('/'),
            'edit' => Pages\EditEmailTemplate::route('/{record}/edit'),
        ];
    }
}
