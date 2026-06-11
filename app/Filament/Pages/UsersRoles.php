<?php

namespace App\Filament\Pages;

class UsersRoles extends OperationalPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationLabel = 'Pracownicy';
    protected static ?string $title = 'Pracownicy';
    protected static ?int $navigationSort = 100;

    public function getPlaceholderDescription(): string
    {
        return 'Miejsce na przyszłe zarządzanie pracownikami, rolami, uprawnieniami i przeglądem dostępu. Role MVP pozostają skonfigurowane, ale workflow zarządzania rolami nie jest jeszcze wdrożony.';
    }
}
