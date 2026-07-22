<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class TermsController extends Controller
{
    public function __invoke(): View
    {
        return view('storefront.terms', [
            'metaTitle' => __('storefront.terms_title'),
            'metaDescription' => __('storefront.terms_desc'),
            'breadcrumbs' => [
                ['label' => __('storefront.home'), 'url' => route('storefront.home')],
                ['label' => __('storefront.terms')],
            ],
        ]);
    }
}
