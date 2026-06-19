<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class PrivacyPolicyController extends Controller
{
    public function __invoke(): View
    {
        return view('storefront.privacy-policy', [
            'metaTitle' => 'Polityka prywatności - GPSwiss',
            'metaDescription' => 'Polityka prywatności sklepu internetowego GPSwiss.',
            'breadcrumbs' => [
                ['label' => 'Strona główna', 'url' => route('storefront.home')],
                ['label' => 'Polityka prywatności'],
            ],
        ]);
    }
}
