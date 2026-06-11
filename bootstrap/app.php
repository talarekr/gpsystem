<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Ticket 1 keeps middleware defaults. Module-specific middleware belongs in later tickets.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Exception reporting customizations will be added when operational modules exist.
    })
    ->create();
