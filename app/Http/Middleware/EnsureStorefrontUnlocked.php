<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStorefrontUnlocked
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->boolean('storefront_unlocked')) {
            return $next($request);
        }

        if ($request->isMethod('GET')) {
            $request->session()->put('storefront_intended_url', $request->fullUrl());
        }

        return response()->view('storefront.access.password', [
            'metaTitle' => 'Dostęp do sklepu - GPSwiss',
        ]);
    }
}
