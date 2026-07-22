<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class PrivacyPolicyController extends Controller
{
    public function __invoke(): View
    {
        return view('storefront.privacy-policy', [
            'metaTitle' => __('storefront.privacy_title'),
            'metaDescription' => __('storefront.privacy_desc'),
            'breadcrumbs' => [
                ['label' => __('storefront.home'), 'url' => route('storefront.home')],
                ['label' => __('storefront.privacy_policy')],
            ],
        ]);
    }
}
