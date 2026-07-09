<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Throwable;

class DeployDiagnoseController extends Controller
{
    private const CODE_MARKER = 'deploy_diagnostics_v1_safe';

    public function __invoke(Request $request): JsonResponse
    {
        $errors = [];
        $sectionsCompleted = [];
        $sectionsFailed = [];

        try {
            $payload = [
                'code_marker' => self::CODE_MARKER,
            ];

            if ($request->boolean('minimal')) {
                $payload['minimal'] = true;
                $payload['app'] = $this->runSection('app', fn (): array => $this->appDiagnostics(), $errors, $sectionsCompleted, $sectionsFailed);
                $payload['status'] = empty($errors) ? 'ok' : 'partial';
                $payload['errors'] = $errors;
                $payload['diagnostics_health'] = $this->diagnosticsHealth($payload['status'], $sectionsCompleted, $sectionsFailed);

                return response()->json($payload, 200);
            }

            $payload['app'] = $this->runSection('app', fn (): array => $this->appDiagnostics(), $errors, $sectionsCompleted, $sectionsFailed);
            $payload['git'] = $this->runSection('git', fn (): array => $this->gitDiagnostics(), $errors, $sectionsCompleted, $sectionsFailed);
            $this->collectGitErrors($payload['git'], $errors, $sectionsFailed);
            $routeErrorCount = count($errors);
            $payload['routes'] = $this->runSection('routes', fn (): array => $this->routesDiagnostics($errors), $errors, $sectionsCompleted, $sectionsFailed);
            if (count($errors) > $routeErrorCount) {
                $sectionsFailed[] = 'routes';
            }
            $payload['files'] = $this->runSection('files', fn (): array => $this->filesDiagnostics(), $errors, $sectionsCompleted, $sectionsFailed);
            $this->collectFileErrors($payload['files'], $errors, $sectionsFailed);
            $payload['runtime_expectations'] = $this->runSection('runtime_expectations', fn (): array => $this->runtimeExpectations(), $errors, $sectionsCompleted, $sectionsFailed);
            $payload['safe_next_steps_for_operator'] = $this->runSection('safe_next_steps_for_operator', fn (): array => $this->safeNextStepsForOperator(), $errors, $sectionsCompleted, $sectionsFailed);
            $payload['status'] = empty($errors) ? 'ok' : 'partial';
            $payload['errors'] = $errors;
            $payload['diagnostics_health'] = $this->diagnosticsHealth($payload['status'], $sectionsCompleted, $sectionsFailed);

            return response()->json($payload, 200);
        } catch (Throwable $throwable) {
            $errors[] = $this->formatError('diagnostics', $throwable);
            $sectionsFailed[] = 'diagnostics';

            return response()->json([
                'code_marker' => self::CODE_MARKER,
                'status' => 'error',
                'errors' => $errors,
                'diagnostics_health' => $this->diagnosticsHealth('error', $sectionsCompleted, $sectionsFailed),
            ], 200);
        }
    }

    /** @param callable():array<string,mixed>|array<int,mixed> $callback @param array<int,array{section:string,message:string,class:string}> $errors @param array<int,string> $sectionsCompleted @param array<int,string> $sectionsFailed */
    private function runSection(string $section, callable $callback, array &$errors, array &$sectionsCompleted, array &$sectionsFailed): array
    {
        try {
            $result = $callback();
            $sectionsCompleted[] = $section;

            return $result;
        } catch (Throwable $throwable) {
            $errors[] = $this->formatError($section, $throwable);
            $sectionsFailed[] = $section;

            return [
                'error' => $throwable->getMessage(),
            ];
        }
    }

    /** @param array<int,array{section:string,message:string,class:string}> $errors @param array<int,string> $sectionsFailed */
    private function collectGitErrors(array $gitDiagnostics, array &$errors, array &$sectionsFailed): void
    {
        foreach (($gitDiagnostics['errors'] ?? []) as $message) {
            $errors[] = $this->formatMessageError('git', (string) $message);
            $sectionsFailed[] = 'git';
        }
    }

    /** @param array<int,array{section:string,message:string,class:string}> $errors @param array<int,string> $sectionsFailed */
    private function collectFileErrors(array $filesDiagnostics, array &$errors, array &$sectionsFailed): void
    {
        foreach ($filesDiagnostics as $key => $diagnostics) {
            if (! is_array($diagnostics)) {
                continue;
            }

            foreach (['error', 'mtime_error', 'read_error', 'sha1_error'] as $errorKey) {
                if (! empty($diagnostics[$errorKey])) {
                    $errors[] = $this->formatMessageError('files', $key.': '.(string) $diagnostics[$errorKey]);
                    $sectionsFailed[] = 'files';
                }
            }
        }
    }

    /** @return array{section:string,message:string,class:string} */
    private function formatMessageError(string $section, string $message): array
    {
        return [
            'section' => $section,
            'message' => $message,
            'class' => 'RuntimeException',
        ];
    }

    /** @return array{section:string,message:string,class:string} */
    private function formatError(string $section, Throwable $throwable): array
    {
        return [
            'section' => $section,
            'message' => $throwable->getMessage(),
            'class' => get_class($throwable),
        ];
    }

    /** @param array<int,string> $sectionsCompleted @param array<int,string> $sectionsFailed @return array{ok:bool,status:string,sections_completed:array<int,string>,sections_failed:array<int,string>} */
    private function diagnosticsHealth(string $status, array $sectionsCompleted, array $sectionsFailed): array
    {
        return [
            'ok' => $status !== 'error',
            'status' => $status,
            'sections_completed' => array_values(array_unique($sectionsCompleted)),
            'sections_failed' => array_values(array_unique($sectionsFailed)),
        ];
    }

    /** @return array{environment:string,debug:bool,laravel_version:string,php_version:string} */
    private function appDiagnostics(): array
    {
        return [
            'environment' => (string) app()->environment(),
            'debug' => (bool) config('app.debug'),
            'laravel_version' => (string) app()->version(),
            'php_version' => PHP_VERSION,
        ];
    }

    /** @return array{route_cache_file_exists:bool,config_cache_file_exists:bool,routes_matching_dhl_diagnose:array<int,array<string,mixed>>,routes_matching_deploy_diagnose:array<int,array<string,mixed>>} */
    private function routesDiagnostics(array &$errors): array
    {
        return [
            'route_cache_file_exists' => $this->routeCacheFileExists(),
            'config_cache_file_exists' => (bool) app()->configurationIsCached(),
            'routes_matching_dhl_diagnose' => $this->matchingRoutes('dhl-diagnose', $errors),
            'routes_matching_deploy_diagnose' => $this->matchingRoutes('deploy-diagnose', $errors),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function filesDiagnostics(): array
    {
        return [
            'dhl_config_diagnose_controller' => $this->fileDiagnostics('app/Http/Controllers/Tools/DhlConfigDiagnoseController.php', [
                'contains_marker_dhl_auth_config_diagnostics_v1' => 'dhl_auth_config_diagnostics_v1',
                'contains_marker_dhl_service_selection_country_v1' => 'dhl_service_selection_country_v1',
                'contains_diagnostic_routes' => 'diagnostic_routes',
            ]),
            'routes_web' => $this->fileDiagnostics('routes/web.php', [
                'contains_orders_dhl_diagnose_alias' => '/admin/tools/orders/dhl-diagnose',
                'contains_dhl_config_diagnose' => '/admin/tools/dhl/config-diagnose',
            ]),
            'dhl_shipment_service' => $this->fileDiagnostics('app/Services/Shipments/DhlShipmentService.php', [
                'contains_default_international_service' => 'default_international_service',
                'contains_service_selection_logic' => 'selected_service_type',
            ]),
            'config_services' => $this->fileDiagnostics('config/services.php', [
                'contains_DHL24_DEFAULT_INTERNATIONAL_SERVICE_TYPE' => 'DHL24_DEFAULT_INTERNATIONAL_SERVICE_TYPE',
                'contains_default_international_service' => 'default_international_service',
            ]),
        ];
    }

    /** @return array<string,mixed> */
    private function runtimeExpectations(): array
    {
        return [
            'should_dhl_config_diagnose_marker_be' => 'dhl_service_selection_country_v1',
            'production_currently_reported_old_marker' => true,
            'likely_issue' => 'old deploy or cached routes/opcache',
        ];
    }

    /** @return array<int,string> */
    private function safeNextStepsForOperator(): array
    {
        return [
            'Sprawdź, czy produkcja działa na commicie b6e4be0 albo nowszym.',
            'Po deployu wyczyść Laravel route/config/view/cache zgodnie z procesem deployu.',
            'Zrestartuj PHP-FPM/opcache, jeśli środowisko tego wymaga.',
            'Ponownie otwórz /admin/tools/dhl/config-diagnose?order_id=153&json=1.',
            'Oczekuj markera dhl_service_selection_country_v1 oraz sekcji dhl_service_selection z receiver_country=IT i selected_service_type=EK.',
        ];
    }

    /** @return array{current_commit:?string,current_branch:?string,latest_commit_subject:?string,has_git_available:bool,errors:array<int,string>} */
    private function gitDiagnostics(): array
    {
        $errors = [];

        if (! is_dir(base_path('.git'))) {
            $errors[] = 'git repository metadata not found';

            return $this->emptyGitDiagnostics($errors);
        }

        $commit = $this->runGit(['rev-parse', 'HEAD'], $errors);
        $branch = $this->runGit(['rev-parse', '--abbrev-ref', 'HEAD'], $errors);
        $subject = $this->runGit(['log', '-1', '--pretty=%s'], $errors);

        return [
            'current_commit' => $commit,
            'current_branch' => $branch,
            'latest_commit_subject' => $subject,
            'has_git_available' => $commit !== null || $branch !== null || $subject !== null,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /** @param array<int,string> $errors @return array{current_commit:null,current_branch:null,latest_commit_subject:null,has_git_available:false,errors:array<int,string>} */
    private function emptyGitDiagnostics(array $errors): array
    {
        return [
            'current_commit' => null,
            'current_branch' => null,
            'latest_commit_subject' => null,
            'has_git_available' => false,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /** @param array<int,string> $arguments @param array<int,string> $errors */
    private function runGit(array $arguments, array &$errors): ?string
    {
        try {
            if (! function_exists('exec')) {
                $errors[] = 'git unavailable or command disabled';
                return null;
            }

            $disabledFunctions = array_map('trim', explode(',', (string) ini_get('disable_functions')));
            if (in_array('exec', $disabledFunctions, true)) {
                $errors[] = 'git unavailable or command disabled';
                return null;
            }

            $command = 'git -C '.escapeshellarg(base_path()).' '.implode(' ', array_map('escapeshellarg', $arguments)).' 2>&1';
            $output = [];
            $exitCode = 0;
            exec($command, $output, $exitCode);

            if ($exitCode !== 0) {
                $errors[] = trim(implode("\n", $output)) ?: 'git command failed: '.implode(' ', $arguments);
                return null;
            }

            return trim(implode("\n", $output)) ?: null;
        } catch (Throwable $throwable) {
            $errors[] = $throwable->getMessage() ?: 'git unavailable or command disabled';
            return null;
        }
    }

    private function routeCacheFileExists(): bool
    {
        try {
            return file_exists(base_path('bootstrap/cache/routes-v7.php'))
                || file_exists(base_path('bootstrap/cache/routes.php'));
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<int,array{section:string,message:string,class:string}> $errors @return array<int,array{uri:string,name:?string,methods:array<int,string>,action:string,middleware:array<int,string>}> */
    private function matchingRoutes(string $needle, array &$errors): array
    {
        $matches = [];

        try {
            $routes = Route::getRoutes();
        } catch (Throwable $throwable) {
            $errors[] = $this->formatError('routes', $throwable);
            return $matches;
        }

        foreach ($routes as $route) {
            $uri = 'unavailable';
            $name = null;
            $action = 'unavailable';
            $methods = [];
            $middleware = [];

            try {
                $uri = (string) $route->uri();
                $name = $route->getName() !== null ? (string) $route->getName() : null;
                $methods = array_values(array_map('strval', $route->methods()));
            } catch (Throwable $throwable) {
                $errors[] = $this->formatError('routes', $throwable);
            }

            try {
                $action = (string) $route->getActionName();
            } catch (Throwable $throwable) {
                $errors[] = $this->formatError('routes', $throwable);
            }

            try {
                $middleware = array_values(array_map('strval', $route->gatherMiddleware()));
            } catch (Throwable $throwable) {
                $errors[] = $this->formatError('routes', $throwable);
            }

            $haystack = implode(' ', array_filter([$uri, $name, $action, implode(' ', $middleware)]));
            if (! Str::contains($haystack, $needle)) {
                continue;
            }

            $matches[] = [
                'uri' => $uri,
                'name' => $name,
                'methods' => $methods,
                'action' => $action,
                'middleware' => $middleware,
            ];
        }

        return $matches;
    }

    /** @param array<string,string> $containsChecks @return array<string,mixed> */
    private function fileDiagnostics(string $relativePath, array $containsChecks): array
    {
        $path = base_path($relativePath);
        $diagnostics = [
            'path' => $relativePath,
            'exists' => false,
            'readable' => false,
            'mtime' => null,
            'sha1' => null,
        ];

        foreach ($containsChecks as $key => $needle) {
            $diagnostics[$key] = null;
        }

        try {
            $diagnostics['exists'] = file_exists($path);
            if (! $diagnostics['exists']) {
                $diagnostics['error'] = 'file does not exist';
                return $diagnostics;
            }

            $diagnostics['readable'] = is_readable($path);
            if (! $diagnostics['readable']) {
                $diagnostics['error'] = 'file is not readable';
                return $diagnostics;
            }

            try {
                $mtime = filemtime($path);
                $diagnostics['mtime'] = $mtime !== false ? date('c', $mtime) : null;
            } catch (Throwable $throwable) {
                $diagnostics['mtime_error'] = $throwable->getMessage();
            }

            try {
                $contents = file_get_contents($path);
            } catch (Throwable $throwable) {
                $contents = false;
                $diagnostics['read_error'] = $throwable->getMessage();
            }

            foreach ($containsChecks as $key => $needle) {
                $diagnostics[$key] = is_string($contents) ? Str::contains($contents, $needle) : null;
            }

            try {
                $sha1 = sha1_file($path);
                $diagnostics['sha1'] = $sha1 !== false ? $sha1 : null;
            } catch (Throwable $throwable) {
                $diagnostics['sha1_error'] = $throwable->getMessage();
            }
        } catch (Throwable $throwable) {
            $diagnostics['error'] = $throwable->getMessage();
        }

        return $diagnostics;
    }
}
