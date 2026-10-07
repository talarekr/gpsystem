<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveStorefrontHost
{
    public function handle(Request $request, Closure $next): Response
    {
        // Only customer-facing routes: never admin, tools, integrations or webhooks.
        if ($request->is('admin', 'admin/*', 'livewire/*', 'tools', 'tools/*')
            || ! $request->routeIs('storefront.*', 'password.request', 'password.email', 'password.reset', 'password.update')
            || $request->routeIs('storefront.api-info')) {
            return $next($request);
        }

        // Host names contain dots, so look up the literal key rather than a config path.
        $profile = config('storefronts', [])[$request->getHost()] ?? null;

        if (is_array($profile)) {
            $request->attributes->set('storefront_terms_profile', $profile['terms_profile']);

            // PL retains its existing session/cookie language selection.
            if ($request->getHost() === 'gpswiss.fr') {
                $request->attributes->set('storefront_locale', $profile['locale']);
            }
        }

        return $next($request);
    }
}
