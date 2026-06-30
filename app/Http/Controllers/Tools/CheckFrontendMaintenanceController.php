<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Http\Middleware\FrontendMaintenanceMode;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckFrontendMaintenanceController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    public function __invoke(Request $request): JsonResponse
    {
        if (! hash_equals(self::TOKEN, (string) $request->query('token', ''))) {
            return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        }

        $allowed = FrontendMaintenanceMode::allowedPatterns();
        $blockedSample = ['/', '/czesci', '/czesci/silniki', '/produkt/testowy-produkt', '/sklep', '/koszyk', '/zamowienie', '/kontakt'];
        $allowedSample = ['/admin', '/admin/login', '/tools/check-frontend-maintenance', '/deploy.php', '/storage/example.jpg', '/css/app.css', '/js/app.js', '/images/logo.png', '/build/assets/app.css', '/assets/build/app.css', '/ebay-template/assets/icon-packaging.png'];
        $warnings = [];
        $blockers = [];

        if (! in_array('admin/*', $allowed, true)) {
            $blockers[] = 'Admin routes are not listed as always allowed.';
        }

        if (! in_array('tools/*', $allowed, true)) {
            $blockers[] = 'Tools routes are not listed as always allowed.';
        }

        if (config('frontend-maintenance.message') === '') {
            $warnings[] = 'Maintenance message is empty; the view will use its fallback copy.';
        }

        return response()->json([
            'enabled' => (bool) config('frontend-maintenance.enabled'),
            'message' => (string) config('frontend-maintenance.message'),
            'admin_bypass_enabled' => method_exists(FrontendMaintenanceMode::class, 'isAdminUser') && is_subclass_of(User::class, \Filament\Models\Contracts\FilamentUser::class),
            'admin_routes_allowed' => in_array('admin/*', $allowed, true),
            'tools_routes_allowed' => in_array('tools/*', $allowed, true),
            'assets_allowed' => count(array_intersect(['storage/*', 'css/*', 'js/*', 'images/*', 'build/*', 'assets/build/*'], $allowed)) === 6,
            'ebay_template_assets_allowed' => in_array('ebay-template/assets/*', $allowed, true),
            'storefront_blocked_for_guests' => (bool) config('frontend-maintenance.enabled'),
            'storefront_allowed_for_admin' => true,
            'blocked_routes_sample' => $blockedSample,
            'allowed_routes_sample' => $allowedSample,
            'warnings' => $warnings,
            'blockers' => $blockers,
        ]);
    }
}
