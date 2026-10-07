<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFrenchCatalogTranslationAdmin
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower(rtrim($request->getHost(), '.'));
        $frenchStorefrontHosts = ['gpswiss.fr', 'www.gpswiss.fr'];
        foreach (config('storefronts', []) as $storefrontHost => $storefront) {
            if (($storefront['locale'] ?? null) === 'fr') {
                $storefrontHost = strtolower(preg_replace('/^www\./i', '', $storefrontHost));
                $frenchStorefrontHosts[] = $storefrontHost;
                $frenchStorefrontHosts[] = 'www.'.$storefrontHost;
            }
        }

        abort_if(in_array($host, $frenchStorefrontHosts, true), 403);
        abort_unless($request->user()?->hasAnyRole([
            UserRole::OwnerAdmin->value,
            UserRole::Manager->value,
        ]), 403);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
