<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetStorefrontLocale
{
    public const SUPPORTED = ['pl', 'de', 'en', 'fr', 'uk'];
    public const COOKIE = 'gpswiss_locale';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('admin', 'admin/*', 'livewire/*')) {
            return $next($request);
        }

        $locale = $request->session()->get('locale', $request->cookie(self::COOKIE, 'pl'));
        $locale = in_array($locale, self::SUPPORTED, true) ? $locale : 'pl';

        App::setLocale($locale);
        $request->session()->put('locale', $locale);

        $response = $next($request);
        $response->headers->setCookie(cookie(self::COOKIE, $locale, 60 * 24 * 365));

        return $response;
    }
}
