<?php

namespace App\Filament\Pages\Settings;

class TranslationContentTemplates extends SettingsPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationLabel = 'Translation / Content Templates';
    protected static ?string $title = 'Translation / Content Templates';
    protected static ?int $navigationSort = 128;

    public function getPlaceholderDescription(): string
    {
        return 'Future home for reusable content templates, translation guidance, and marketplace copy preparation. No generated content workflow is implemented here.';
    }
}
