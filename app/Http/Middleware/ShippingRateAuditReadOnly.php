<?php

namespace App\Http\Middleware;

use App\Services\Marketplace\ShippingRateAudit\ReadOnlyDatabase;
use Closure;

final class ShippingRateAuditReadOnly
{
    public function handle($request, Closure $next)
    {
        return ReadOnlyDatabase::run(fn () => $next($request));
    }
}
