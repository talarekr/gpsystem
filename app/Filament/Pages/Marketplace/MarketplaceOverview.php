<?php

namespace App\Filament\Pages\Marketplace;

use App\Models\MarketplaceListing;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class MarketplaceOverview extends Page
{
    protected static ?string $navigationGroup = 'Administracja marketplace';
    protected static ?string $navigationIcon = null;
    protected static ?string $navigationLabel = 'Przegląd';
    protected static ?string $title = 'Administracja marketplace';
    protected static ?int $navigationSort = 1;
    protected static string $view = 'filament.pages.marketplace.overview';

    public function stats(): array
    {
        return ['ovoko' => MarketplaceListing::query()->where('marketplace', 'ovoko')->count(), 'mapped' => MarketplaceListing::query()->where('marketplace', 'ovoko')->where('sync_status', 'mapped')->count(), 'unmatched' => MarketplaceListing::query()->where('marketplace', 'ovoko')->where('sync_status', 'unmatched')->count(), 'conflict' => MarketplaceListing::query()->where('marketplace', 'ovoko')->where('sync_status', 'conflict')->count()];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addMarketplaceUser')
                ->label('Dodaj użytkownika')
                ->modalHeading('Dodaj użytkownika marketplace')
                ->modalSubmitActionLabel('Zapisz')
                ->form([
                    TextInput::make('login')
                        ->label('Login')
                        ->required()
                        ->rule('string')
                        ->maxLength(255)
                        ->unique(table: User::class, column: 'name'),
                    TextInput::make('password')
                        ->label('Hasło')
                        ->password()
                        ->revealable(false)
                        ->autocomplete('new-password')
                        ->required()
                        ->rule(Password::defaults()),
                    TextInput::make('email')
                        ->label('Adres email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(table: User::class, column: 'email'),
                ])
                ->action(function (array $data): void {
                    User::query()->create([
                        'name' => trim((string) $data['login']),
                        'email' => trim((string) $data['email']),
                        'password' => Hash::make((string) $data['password']),
                    ]);

                    Notification::make()
                        ->title('Dodano użytkownika marketplace.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
