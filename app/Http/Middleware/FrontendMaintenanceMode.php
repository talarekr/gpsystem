<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FrontendMaintenanceMode
{
    /** @var list<string> */
    private const ALWAYS_ALLOWED_PATTERNS = [
        'admin',
        'admin/*',
        'tools',
        'tools/*',
        'deploy.php',
        'storage',
        'storage/*',
        'css',
        'css/*',
        'js',
        'js/*',
        'images',
        'images/*',
        'build',
        'build/*',
        'assets/build',
        'assets/build/*',
        'favicon.ico',
        'robots.txt',
        'livewire/*',
        'product-images-dry-run',
        'product-images-import',
        'product-images-import-runner',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('frontend-maintenance.enabled')) {
            return $next($request);
        }

        if ($this->isAlwaysAllowed($request) || $this->isAdminUser($request)) {
            return $next($request);
        }

        return response()->view('maintenance.frontend', [
            'message' => config('frontend-maintenance.message'),
        ], 503);
    }

    public static function allowedPatterns(): array
    {
        return self::ALWAYS_ALLOWED_PATTERNS;
    }

    public function isAlwaysAllowed(Request $request): bool
    {
        foreach (self::ALWAYS_ALLOWED_PATTERNS as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }

        return false;
    }

    public function isAdminUser(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->canAccessPanel(filament()->getPanel('admin'));
    }
}
