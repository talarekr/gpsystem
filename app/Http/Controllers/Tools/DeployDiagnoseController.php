<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class DeployDiagnoseController extends Controller
{
    private const CODE_MARKER = 'deploy_diagnostics_v1';

    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'code_marker' => self::CODE_MARKER,
            'app' => [
                'environment' => app()->environment(),
                'debug' => (bool) config('app.debug'),
                'laravel_version' => app()->version(),
                'php_version' => PHP_VERSION,
            ],
            'git' => $this->gitDiagnostics(),
            'routes' => [
                'route_cache_file_exists' => $this->routeCacheFileExists(),
                'config_cache_file_exists' => app()->configurationIsCached(),
                'routes_matching_dhl_diagnose' => $this->matchingRoutes('dhl-diagnose'),
                'routes_matching_deploy_diagnose' => $this->matchingRoutes('deploy-diagnose'),
            ],
            'files' => [
                'dhl_config_diagnose_controller' => $this->fileDiagnostics(
                    'app/Http/Controllers/Tools/DhlConfigDiagnoseController.php',
                    [
                        'contains_marker_dhl_auth_config_diagnostics_v1' => 'dhl_auth_config_diagnostics_v1',
                        'contains_marker_dhl_service_selection_country_v1' => 'dhl_service_selection_country_v1',
                        'contains_diagnostic_routes' => 'diagnostic_routes',
                    ],
                ),
                'routes_web' => $this->fileDiagnostics(
                    'routes/web.php',
                    [
                        'contains_orders_dhl_diagnose_alias' => '/admin/tools/orders/dhl-diagnose',
                        'contains_dhl_config_diagnose' => '/admin/tools/dhl/config-diagnose',
                    ],
                ),
                'dhl_shipment_service' => $this->fileDiagnostics(
                    'app/Services/Shipments/DhlShipmentService.php',
                    [
                        'contains_default_international_service' => 'default_international_service',
                        'contains_service_selection_logic' => 'selected_service_type',
                    ],
                ),
                'config_services' => $this->fileDiagnostics(
                    'config/services.php',
                    [
                        'contains_DHL24_DEFAULT_INTERNATIONAL_SERVICE_TYPE' => 'DHL24_DEFAULT_INTERNATIONAL_SERVICE_TYPE',
                        'contains_default_international_service' => 'default_international_service',
                    ],
                ),
            ],
            'runtime_expectations' => [
                'should_dhl_config_diagnose_marker_be' => 'dhl_service_selection_country_v1',
                'production_currently_reported_old_marker' => true,
                'likely_issue' => 'old deploy or cached routes/opcache',
            ],
            'safe_next_steps_for_operator' => [
                'Sprawdź, czy produkcja działa na commicie b6e4be0 albo nowszym.',
                'Po deployu wyczyść Laravel route/config/view/cache zgodnie z procesem deployu.',
                'Zrestartuj PHP-FPM/opcache, jeśli środowisko tego wymaga.',
                'Ponownie otwórz /admin/tools/dhl/config-diagnose?order_id=153&json=1.',
                'Oczekuj markera dhl_service_selection_country_v1 oraz sekcji dhl_service_selection z receiver_country=IT i selected_service_type=EK.',
            ],
        ]);
    }

    /** @return array{current_commit:?string,current_branch:?string,latest_commit_subject:?string,has_git_available:?bool,errors:array<int,string>} */
    private function gitDiagnostics(): array
    {
        $errors = [];
        $commit = $this->runGit(['rev-parse', 'HEAD'], $errors);
        $branch = $this->runGit(['rev-parse', '--abbrev-ref', 'HEAD'], $errors);
        $subject = $this->runGit(['log', '-1', '--pretty=%s'], $errors);

        return [
            'current_commit' => $commit,
            'current_branch' => $branch,
            'latest_commit_subject' => $subject,
            'has_git_available' => $commit !== null || $branch !== null || $subject !== null ? true : (empty($errors) ? null : false),
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /** @param array<int,string> $arguments @param array<int,string> $errors */
    private function runGit(array $arguments, array &$errors): ?string
    {
        $command = 'git -C '.escapeshellarg(base_path()).' '.implode(' ', array_map('escapeshellarg', $arguments)).' 2>&1';
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            $errors[] = trim(implode("\n", $output)) ?: 'git command failed: '.implode(' ', $arguments);
            return null;
        }

        return trim(implode("\n", $output)) ?: null;
    }

    private function routeCacheFileExists(): bool
    {
        return file_exists(base_path('bootstrap/cache/routes-v7.php'))
            || file_exists(base_path('bootstrap/cache/routes.php'));
    }

    /** @return array<int,array{uri:string,name:?string,methods:array<int,string>,action:?string}> */
    private function matchingRoutes(string $needle): array
    {
        $matches = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            $name = $route->getName();
            $action = $route->getActionName();
            $haystack = implode(' ', array_filter([$uri, $name, $action]));

            if (! Str::contains($haystack, $needle)) {
                continue;
            }

            $matches[] = [
                'uri' => $uri,
                'name' => $name,
                'methods' => $route->methods(),
                'action' => $action,
            ];
        }

        return $matches;
    }

    /** @param array<string,string> $containsChecks @return array<string,mixed> */
    private function fileDiagnostics(string $relativePath, array $containsChecks): array
    {
        $path = base_path($relativePath);
        $exists = is_file($path);
        $contents = $exists ? file_get_contents($path) : false;

        $diagnostics = [
            'path' => $relativePath,
            'exists' => $exists,
            'mtime' => $exists ? date('c', (int) filemtime($path)) : null,
        ];

        foreach ($containsChecks as $key => $needle) {
            $diagnostics[$key] = is_string($contents) ? Str::contains($contents, $needle) : null;
        }

        $diagnostics['sha1'] = $exists ? sha1_file($path) : null;

        return $diagnostics;
    }
}
