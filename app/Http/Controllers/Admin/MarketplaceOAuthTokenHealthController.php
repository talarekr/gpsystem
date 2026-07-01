<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\OAuthTokenManager;

class MarketplaceOAuthTokenHealthController extends Controller
{
    public function __invoke(OAuthTokenManager $tokens)
    {
        return response()->json(['ok' => true, 'generated_at' => now()->toISOString(), 'items' => $tokens->tokenHealth()]);
    }
}
