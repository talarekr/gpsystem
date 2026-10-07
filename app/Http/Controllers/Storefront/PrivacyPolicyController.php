<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PrivacyPolicyController extends Controller
{
    public function __invoke(Request $request): View
    {
        $view = $request->attributes->get('storefront_terms_profile') === 'fr'
            ? 'storefront.fr.privacy-policy' : 'storefront.privacy-policy';

        return view($view, [
            'metaTitle' => __('storefront.privacy_title'),
            'metaDescription' => __('storefront.privacy_desc'),
            'breadcrumbs' => [
                ['label' => __('storefront.home'), 'url' => route('storefront.home')],
                ['label' => __('storefront.privacy_policy')],
            ],
        ]);
    }
}
