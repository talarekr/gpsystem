<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TermsController extends Controller
{
    public function __invoke(Request $request): View
    {
        $view = $request->attributes->get('storefront_terms_profile') === 'fr'
            ? 'storefront.fr.terms' : 'storefront.terms';

        return view($view, [
            'metaTitle' => __('storefront.terms_title'),
            'metaDescription' => __('storefront.terms_desc'),
            'breadcrumbs' => [
                ['label' => __('storefront.home'), 'url' => route('storefront.home')],
                ['label' => __('storefront.terms')],
            ],
        ]);
    }
}
