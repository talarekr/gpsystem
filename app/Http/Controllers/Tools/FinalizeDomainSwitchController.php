<?php

namespace App\Http\Controllers\Tools;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;

class FinalizeDomainSwitchController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';
    private const TARGETS = [
        'APP_URL' => 'https://gpswiss.pl',
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
    ];

    public function __invoke(Request $request): JsonResponse
    {
        if (! hash_equals(self::TOKEN, (string) $request->query('token', ''))) {
            return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        }

        $dryRun = (string) $request->query('dry_run', '1') !== '0';
        $warnings = [];
        $blockers = [];
        $commandsRun = [];
        $commandOutputsSafe = [];
        $envPath = base_path('.env');

        if (! is_file($envPath)) {
            $blockers[] = '.env file does not exist at application base path.';
        } elseif (! is_readable($envPath)) {
            $blockers[] = '.env file is not readable.';
        }

        $envContents = $blockers === [] ? file_get_contents($envPath) : false;
        if ($envContents === false && $blockers === []) {
            $blockers[] = 'Unable to read .env file.';
        }

        $before = $envContents === false ? [] : $this->readEnvValues($envContents);
        $after = array_merge($before, self::TARGETS);
        $changed = [];

        foreach (self::TARGETS as $key => $targetValue) {
            $changed[$key] = ($before[$key] ?? null) !== $targetValue;
        }

        if (! $dryRun && $blockers === []) {
            if (! is_writable($envPath)) {
                $blockers[] = '.env file is not writable.';
            } else {
                $updatedContents = $this->writeEnvValues($envContents, self::TARGETS);
                if (file_put_contents($envPath, $updatedContents, LOCK_EX) === false) {
                    $blockers[] = 'Unable to write updated .env file.';
                } else {
                    foreach (['config:clear', 'route:clear', 'view:clear', 'cache:clear'] as $command) {
                        $commandsRun[] = 'php artisan '.$command;
                        $exitCode = Artisan::call($command);
                        $commandOutputsSafe[$command] = [
                            'exit_code' => $exitCode,
                            'output' => trim(Artisan::output()),
                        ];

                        if ($exitCode !== 0) {
                            $blockers[] = 'Command failed: php artisan '.$command;
                        }
                    }
                }
            }
        }

        if ($dryRun) {
            $warnings[] = 'Dry-run only: .env was not changed and artisan cache clear commands were not run.';
        }

        return response()->json([
            'ok' => $blockers === [],
            'dry_run' => $dryRun,
            'before' => $before,
            'after' => $after,
            'changed' => $changed,
            'commands_run' => $commandsRun,
            'command_outputs_safe' => $commandOutputsSafe,
            'warnings' => $warnings,
            'blockers' => $blockers,
            'next_steps' => $dryRun
                ? ['Review changed values, then run with dry_run=0 on gpswiss.pl when ready.']
                : ['Re-run /tools/post-domain-switch-check?token='.self::TOKEN.' on https://gpswiss.pl.'],
        ], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, string|null>
     */
    private function readEnvValues(string $contents): array
    {
        $values = [];
        foreach (array_keys(self::TARGETS) as $key) {
            $values[$key] = null;
            if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $contents, $matches) === 1) {
                $values[$key] = trim($matches[1], " \t\n\r\0\x0B\"'");
            }
        }

        return $values;
    }

    /**
     * @param array<string, string> $values
     */
    private function writeEnvValues(string $contents, array $values): string
    {
        foreach ($values as $key => $value) {
            $line = $key.'='.$value;
            if (preg_match('/^'.preg_quote($key, '/').'=.*$/m', $contents) === 1) {
                $contents = preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $contents) ?? $contents;
            } else {
                $contents = rtrim($contents).PHP_EOL.$line.PHP_EOL;
            }
        }

        return $contents;
    }
}
