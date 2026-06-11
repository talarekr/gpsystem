<?php

namespace App\Filament\Pages;

class Analytics extends OperationalPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';
    protected static ?string $navigationLabel = 'Analityka';
    protected static ?string $title = 'Analityka';
    protected static ?int $navigationSort = 20;

    public function getPlaceholderDescription(): string
    {
        return 'Miejsce na przyszłe raporty i podsumowania operacyjne. Nie dodano obliczeń analitycznych ani integracji z danymi produkcyjnymi.';
    }
}
