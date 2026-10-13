<?php

use App\Http\Middleware\EnsureAdminPanelAccess;
use App\Http\Middleware\FrenchStorefrontSeoHeaders;
use App\Http\Middleware\FrontendMaintenanceMode;
use App\Http\Middleware\ResolveStorefrontHost;
use App\Http\Middleware\SetStorefrontLocale;
use App\Support\StorefrontContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;

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
            ResolveStorefrontHost::class,
            SetStorefrontLocale::class,
            FrenchStorefrontSeoHeaders::class,
            FrontendMaintenanceMode::class,
        ]);
        $middleware->alias([
            'admin.panel' => EnsureAdminPanelAccess::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'payu/notify',
            'payments/stripe/fr/webhook',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function ($response, Throwable $exception, Request $request) {
            if (StorefrontContext::isFrench($request)
                && in_array($request->method(), ['GET', 'HEAD'], true) && ! $request->expectsJson()
                && (! $request->route() || $request->routeIs('storefront.*', 'password.*'))
                && ! $request->is('admin', 'admin/*', 'tools', 'tools/*', 'livewire/*', 'payments/*', 'payu/*')
                && in_array($response->getStatusCode(), [403, 404, 419, 500, 503], true)) {
                return response()->view('storefront.fr.error', ['status' => $response->getStatusCode()], $response->getStatusCode())
                    ->header('X-Robots-Tag', 'noindex, noarchive')->header('Cache-Control', 'private, no-store')
                    ->header('Referrer-Policy', 'no-referrer');
            }

            return $response;
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
