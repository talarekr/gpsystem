<?php

namespace App\Filament\Pages;

class Help extends OperationalPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-question-mark-circle';
    protected static ?string $navigationLabel = 'Pomoc';
    protected static ?string $title = 'Pomoc';
    protected static ?int $navigationSort = 130;

    public function getPlaceholderDescription(): string
    {
        return 'Miejsce na przyszłe instrukcje dla zespołu administracji i magazynu. Dokumentacja operacyjna zostanie dodana w późniejszym etapie.';
    }
}
