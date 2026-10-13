<?php

namespace App\Http\Middleware;

use App\Services\Storefront\StorefrontSeo;
use App\Support\StorefrontContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FrenchStorefrontSeoHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! StorefrontContext::isFrench($request)) {
            return $response;
        }
        $seo = app(StorefrontSeo::class);
        if ($seo->privatePage($request) || $response->getStatusCode() >= 400) {
            $response->headers->set('X-Robots-Tag', 'noindex, noarchive');
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('Referrer-Policy', 'no-referrer');
        } elseif ($seo->filtered($request)) {
            $response->headers->set('X-Robots-Tag', 'noindex, follow');
        }

        return $response;
    }
}
