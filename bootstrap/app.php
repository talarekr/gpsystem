<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Session\TokenMismatchException;
use App\Http\Middleware\EnsureAdminPanelAccess;
use App\Http\Middleware\FrontendMaintenanceMode;
use App\Http\Middleware\BlockDisabledEbayActions;
use App\Http\Middleware\SetStorefrontLocale;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use App\Exceptions\EbayConnectionDisabledException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SetStorefrontLocale::class,
            FrontendMaintenanceMode::class,
            BlockDisabledEbayActions::class,
        ]);
        $middleware->alias([
            'admin.panel' => EnsureAdminPanelAccess::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'payu/notify',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (EbayConnectionDisabledException $exception, Request $request) {
            $payload = ['ok' => false, 'code' => 'ebay_connection_disabled', 'message' => $exception->getMessage(), 'blocked_action' => $exception->blockedAction, 'marketplace_write' => false];
            if ($request->expectsJson()) return response()->json($payload, 423);
            return response()->view('admin.tools.marketplace.ebay-disabled', $payload, 423);
        });
        $exceptions->render(function (TokenMismatchException $exception, Request $request) {
            if (! $request->is('admin', 'admin/*')) {
                return null;
            }

            if ($request->expectsJson() || $request->header('X-Livewire')) {
                return response()->json([
                    'message' => 'Sesja panelu administracyjnego wygasła. Zaloguj się ponownie.',
                    'redirect' => url('/admin/login'),
                ], 419);
            }

            return redirect()
                ->guest(url('/admin/login'))
                ->with('status', 'Sesja panelu administracyjnego wygasła. Zaloguj się ponownie.');
        });
    })
    ->create();
