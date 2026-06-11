<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

abstract class OperationalPlaceholderPage extends Page
{
    protected static string $view = 'filament.pages.operational-placeholder';

    protected static ?string $navigationGroup = 'Operations';

    public function getPlaceholderDescription(): string
    {
        return 'Placeholder for MVP Ticket 1. This navigation item is intentionally present, but the module workflow will be implemented in a later ticket.';
    }
}
