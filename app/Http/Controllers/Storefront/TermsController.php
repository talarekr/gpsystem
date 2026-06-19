<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class TermsController extends Controller
{
    public function __invoke(): View
    {
        return view('storefront.terms', [
            'metaTitle' => 'Regulamin sklepu internetowego - GPSwiss',
            'metaDescription' => 'Regulamin sklepu internetowego GPSwiss.',
            'breadcrumbs' => [
                ['label' => 'Strona główna', 'url' => route('storefront.home')],
                ['label' => 'Regulamin'],
            ],
        ]);
    }
}
