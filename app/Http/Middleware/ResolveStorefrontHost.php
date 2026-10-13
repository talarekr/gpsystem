<?php

namespace App\Http\Middleware;

use App\Support\StorefrontContext;
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
        // Safe canonical redirect for navigation only. POSTs retain their body
        // and receive FR context, so checkout cannot bypass the FR guard.
        if ($request->getHost() === 'www.gpswiss.fr' && in_array($request->method(), ['GET', 'HEAD'], true)) {
            return redirect()->away('https://gpswiss.fr'.$request->getRequestUri(), 301);
        }
        $profile = StorefrontContext::profile($request);

        if (is_array($profile)) {
            $request->attributes->set('storefront_code', $profile['storefront_code']);
            $request->attributes->set('storefront_payment_provider', $profile['payment_provider']);
            $request->attributes->set('storefront_terms_profile', $profile['terms_profile']);

            // PL retains its existing session/cookie language selection.
            if (StorefrontContext::isFrench($request)) {
                $request->attributes->set('storefront_locale', $profile['locale']);
            }
        }

        return $next($request);
    }
}
