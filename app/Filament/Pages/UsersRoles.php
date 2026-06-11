<?php

namespace App\Filament\Pages;

class UsersRoles extends OperationalPlaceholderPage
{
    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationLabel = 'Users & Roles';
    protected static ?string $navigationGroup = 'Administration / Settings';
    protected static ?string $title = 'Users & Roles';
    protected static ?int $navigationSort = 113;

    public function getPlaceholderDescription(): string
    {
        return 'Future home for user management, roles, permissions, and access review. Existing MVP roles remain configured, but no role-management workflow is implemented here.';
    }
}
