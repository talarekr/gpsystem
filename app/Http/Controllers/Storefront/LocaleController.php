<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetStorefrontLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class LocaleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $locale = $request->input('locale', 'pl');
        $locale = in_array($locale, SetStorefrontLocale::SUPPORTED, true) ? $locale : 'pl';

        App::setLocale($locale);
        $request->session()->put('locale', $locale);

        return back()->withCookie(cookie(SetStorefrontLocale::COOKIE, $locale, 60 * 24 * 365));
    }
}
