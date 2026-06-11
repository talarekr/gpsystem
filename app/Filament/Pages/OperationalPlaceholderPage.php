<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

abstract class OperationalPlaceholderPage extends Page
{
    protected static string $view = 'filament.pages.operational-placeholder';

    protected static ?string $navigationGroup = 'Operations';

    public function getPlaceholderEyebrow(): string
    {
        return 'GPS Product Hub';
    }

    public function getPlaceholderDescription(): string
    {
        return 'Placeholder for MVP Ticket 1. This navigation item is intentionally present, but the module workflow will be implemented in a later ticket.';
    }

    /**
     * @return array<int, string>
     */
    public function getPlaceholderDetails(): array
    {
        return [
            'This page is intentionally a calm, operational placeholder.',
            'No marketplace publishing, external API writes, risky automation, or production credentials are connected here.',
        ];
    }
}
