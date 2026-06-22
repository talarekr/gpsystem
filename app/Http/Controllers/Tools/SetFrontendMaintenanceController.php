<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class SetFrontendMaintenanceController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    public function __invoke(Request $request): JsonResponse
    {
        if (! hash_equals(self::TOKEN, (string) $request->query('token', ''))) {
            return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        }

        if (! $request->has('enabled') || ! in_array((string) $request->query('enabled'), ['0', '1'], true)) {
            return response()->json(['ok' => false, 'error_message' => 'Use enabled=1 or enabled=0.'], 422);
        }

        $enabled = (string) $request->query('enabled') === '1';
        $envPath = base_path('.env');
        $before = [
            'config_enabled' => (bool) config('frontend-maintenance.enabled'),
            'env_value' => env('FRONTEND_MAINTENANCE'),
        ];

        if (! is_file($envPath) || ! is_writable($envPath)) {
            return response()->json(['ok' => false, 'error_message' => '.env file is not writable.', 'before' => $before], 500);
        }

        $contents = (string) file_get_contents($envPath);
        $line = 'FRONTEND_MAINTENANCE='.($enabled ? 'true' : 'false');

        if (preg_match('/^FRONTEND_MAINTENANCE=.*$/m', $contents)) {
            $contents = preg_replace('/^FRONTEND_MAINTENANCE=.*$/m', $line, $contents, 1) ?? $contents;
        } else {
            $contents = rtrim($contents).PHP_EOL.$line.PHP_EOL;
        }

        file_put_contents($envPath, $contents, LOCK_EX);

        $commandsRun = [];
        Artisan::call('config:clear');
        $commandsRun[] = 'php artisan config:clear';
        Artisan::call('cache:clear');
        $commandsRun[] = 'php artisan cache:clear';

        config(['frontend-maintenance.enabled' => $enabled]);

        return response()->json([
            'ok' => true,
            'before' => $before,
            'after' => [
                'config_enabled' => (bool) config('frontend-maintenance.enabled'),
                'env_value' => $enabled ? 'true' : 'false',
            ],
            'commands_run' => $commandsRun,
        ]);
    }
}
