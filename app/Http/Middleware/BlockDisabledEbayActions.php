<?php

namespace App\Http\Middleware;

use App\Services\Marketplace\EbayConnectionGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockDisabledEbayActions
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = strtolower($request->path());
        $isEbayTool = str_contains($path, 'ebay');
        $isAction = ! $request->isMethodSafe() || preg_match('/(?:apply|publish|revise|relist|end|update|sync|runner|oauth)/', $path);
        $isLocalPreview = $request->isMethodSafe()
            && preg_match('/(?:preview|dry-run|coverage-check|connection-status)/', $path)
            && ! preg_match('/(?:apply|run-next-batch|start)/', $path);

        // Pure local preview/coverage/status pages remain usable. Tool actions
        // (including legacy GET actions) fail consistently before controller code.
        if ($isEbayTool && $isAction && ! $isLocalPreview) {
            app(EbayConnectionGate::class)->assertEnabled('admin_endpoint:'.$request->method().':/'.$path);
        }

        return $next($request);
    }
}
