<?php

namespace App\Http\Controllers\Tools;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class CheckDomainHardcodedLinksController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    public function __invoke(Request $request): JsonResponse
    {
        if (! hash_equals(self::TOKEN, (string) $request->query('token', ''))) {
            return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        }

        $occurrences = $this->scan();
        $blockers = array_values(array_filter($occurrences, fn (array $item): bool => $item['severity'] === 'blocker'));
        $warnings = array_values(array_filter($occurrences, fn (array $item): bool => $item['severity'] === 'warning'));

        return response()->json([
            'ok' => true,
            'hardcoded_old_domain_count' => count(array_filter($occurrences, fn (array $item): bool => $item['domain'] === 'old')),
            'occurrences' => array_map(fn (array $item): array => collect($item)->except('domain')->all(), $occurrences),
            'blockers' => $blockers,
            'warnings' => $warnings,
            'safe_to_switch_app_url' => count($blockers) === 0,
        ], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<int, array<string, mixed>> */
    private function scan(): array
    {
        $roots = ['app', 'resources/views', 'routes', 'config', 'database/migrations', 'storage/app/private'];
        $publicFiles = array_filter(array_merge([public_path('robots.txt')], glob(public_path('sitemap*')) ?: []), 'is_file');
        $files = [];

        foreach ($roots as $root) {
            $path = base_path($root);
            if (is_dir($path)) {
                foreach (File::allFiles($path) as $file) {
                    $relative = $file->getRelativePathname();
                    if (Str::contains($relative, ['vendor/', 'node_modules/', 'storage/logs/'])) {
                        continue;
                    }
                    $files[] = $file->getPathname();
                }
            }
        }

        $files = array_merge($files, $publicFiles);
        $patterns = [
            'old' => ['gpsystem.'.'thecamels.pl', 'http://gpsystem.'.'thecamels.pl', 'https://gpsystem.'.'thecamels.pl'],
            'target' => ['gpswiss.pl'],
            'app_url' => ['APP_URL', 'config(\'app.url\')', 'config("app.url")'],
        ];
        $occurrences = [];

        foreach (array_unique($files) as $path) {
            $relative = Str::after($path, base_path().DIRECTORY_SEPARATOR);
            $lines = @file($path, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }
            foreach ($lines as $index => $line) {
                foreach ($patterns as $domain => $needles) {
                    foreach ($needles as $needle) {
                        if (! Str::contains($line, $needle)) {
                            continue;
                        }
                        $occurrences[] = [
                            'domain' => $domain,
                            'file' => $relative,
                            'line' => $index + 1,
                            'snippet' => trim(Str::limit($line, 220)),
                            'severity' => $this->severity($relative, $domain),
                            'recommendation' => $this->recommendation($domain),
                        ];
                        break 2;
                    }
                }
            }
        }

        return $occurrences;
    }

    private function severity(string $file, string $domain): string
    {
        if ($domain === 'app_url') {
            return 'info';
        }

        if (Str::startsWith($file, 'app/Http/Controllers/Tools/')) {
            return 'info';
        }

        if (Str::startsWith($file, 'storage/app/private') || Str::startsWith($file, 'public/sitemap') || $file === 'public/robots.txt') {
            return 'warning';
        }

        return $domain === 'old' ? 'blocker' : 'info';
    }

    private function recommendation(string $domain): string
    {
        return match ($domain) {
            'old' => 'Replace own-shop hardcoded URLs with route(), url(), asset(), Storage URL helpers, or config(\'app.url\').',
            'target' => 'Avoid hardcoding gpswiss.pl; prefer APP_URL-driven helpers unless this is an email address or company identity text.',
            default => 'APP_URL usage found; verify generated absolute URLs follow config(\'app.url\') after cache clear.',
        };
    }
}
