<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Application services will be registered by bounded MVP tickets.
    }

    public function boot(): void
    {
        // Keep Ticket 1 intentionally light: no integration bootstrapping or automation.
    }
}
