<?php

namespace App\Support;

use Illuminate\Http\Request;

final class StorefrontContact
{
    public static function email(Request $request): string
    {
        return StorefrontContext::isFrench($request)
            ? config('storefront-contact.fr.email')
            : 'biuro@gpswiss.pl';
    }

    public static function phone(Request $request): string
    {
        return StorefrontContext::isFrench($request)
            ? config('storefront-contact.fr.phone')
            : '+48 504 266 984';
    }

    public static function phoneUri(Request $request): string
    {
        return StorefrontContext::isFrench($request)
            ? config('storefront-contact.fr.phone_uri')
            : '+48504266984';
    }
}
