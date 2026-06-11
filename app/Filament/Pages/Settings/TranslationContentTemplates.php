<?php

namespace App\Filament\Pages\Settings;

class TranslationContentTemplates extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationLabel = 'Tłumaczenia i szablony treści';
    protected static ?string $title = 'Tłumaczenia i szablony treści';

    public function getPlaceholderDescription(): string
    {
        return 'Miejsce na przyszłe szablony treści, wskazówki tłumaczeniowe i przygotowanie opisów marketplace. Generowanie treści nie jest wdrożone.';
    }
}
