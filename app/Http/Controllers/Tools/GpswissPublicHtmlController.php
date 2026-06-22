<?php

namespace App\Http\Controllers\Tools;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class GpswissPublicHtmlController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';
    private const SOURCE_PUBLIC_HTML = '/home/gpsystem/domains/gpsystem.thecamels.pl/public_html';
    private const TARGET_PUBLIC_HTML = '/home/gpsystem/domains/gpswiss.pl/public_html';
    private const APP_PATH = '/home/gpsystem/domains/gpsystem.thecamels.pl/app';

    /** @var list<string> */
    private const PUBLIC_FILES = [
        'index.php',
        '.htaccess',
        'favicon.ico',
        'robots.txt',
        'site.webmanifest',
        'manifest.json',
        'mix-manifest.json',
        'vite.svg',
    ];

    /** @var list<string> */
    private const PUBLIC_DIRECTORIES = [
        'build',
        'assets',
        'css',
        'js',
        'images',
        'img',
        'fonts',
        'favicon',
    ];

    public function check(Request $request): JsonResponse
    {
        if ($unauthorized = $this->unauthorizedResponse($request)) {
            return $unauthorized;
        }

        $warnings = [];
        $blockers = [];
        $sourceExists = is_dir(self::SOURCE_PUBLIC_HTML);
        $targetExists = is_dir(self::TARGET_PUBLIC_HTML);
        $targetIndexPhp = self::TARGET_PUBLIC_HTML.'/index.php';
        $targetHtaccess = self::TARGET_PUBLIC_HTML.'/.htaccess';
        $targetBuild = self::TARGET_PUBLIC_HTML.'/build';
        $targetStorage = self::TARGET_PUBLIC_HTML.'/storage';
        $targetIndexHtml = self::TARGET_PUBLIC_HTML.'/index.html';

        if (! $sourceExists) {
            $blockers[] = 'Source public_html does not exist.';
        }

        if (! $targetExists) {
            $blockers[] = 'Target public_html does not exist.';
        }

        $indexPointsToApp = $this->indexPointsToApp($targetIndexPhp);
        if ($targetExists && ! file_exists($targetIndexPhp)) {
            $warnings[] = 'Target index.php is missing.';
        }

        if ($targetExists && ! file_exists($targetHtaccess)) {
            $warnings[] = 'Target .htaccess is missing.';
        }

        if ($targetExists && file_exists($targetIndexPhp) && ! $indexPointsToApp) {
            $warnings[] = 'Target index.php does not point to the expected shared Laravel app path.';
        }

        $looksLikeLaravel = file_exists($targetIndexPhp)
            && file_exists($targetHtaccess)
            && (is_dir($targetBuild) || is_file(self::TARGET_PUBLIC_HTML.'/mix-manifest.json') || is_file(self::TARGET_PUBLIC_HTML.'/manifest.json'));

        return $this->json([
            'ok' => count($blockers) === 0,
            'dry_run' => true,
            'source_exists' => $sourceExists,
            'target_exists' => $targetExists,
            'checks' => [
                'source_public_html' => self::SOURCE_PUBLIC_HTML,
                'target_public_html' => self::TARGET_PUBLIC_HTML,
                'target_index_html_directadmin_exists' => file_exists($targetIndexHtml),
                'target_index_php_exists' => file_exists($targetIndexPhp),
                'target_htaccess_exists' => file_exists($targetHtaccess),
                'target_build_exists' => is_dir($targetBuild),
                'target_storage_exists' => file_exists($targetStorage),
                'target_storage_is_symlink' => is_link($targetStorage),
                'target_index_php_points_to_app_path' => $indexPointsToApp,
                'target_looks_like_laravel_public_html' => $looksLikeLaravel,
            ],
            'copied_files' => [],
            'copied_directories' => [],
            'backed_up_files' => [],
            'skipped' => [],
            'storage' => $this->storagePlan(true),
            'warnings' => $warnings,
            'blockers' => $blockers,
            'next_steps' => $this->nextSteps($blockers, $warnings),
        ]);
    }

    public function sync(Request $request): JsonResponse
    {
        if ($unauthorized = $this->unauthorizedResponse($request)) {
            return $unauthorized;
        }

        $dryRun = (string) $request->query('dry_run', '1') !== '0';
        $warnings = [];
        $blockers = [];
        $copiedFiles = [];
        $copiedDirectories = [];
        $backedUpFiles = [];
        $skipped = [];
        $sourceExists = is_dir(self::SOURCE_PUBLIC_HTML);
        $targetExists = is_dir(self::TARGET_PUBLIC_HTML);

        if (! $sourceExists) {
            $blockers[] = 'Source public_html does not exist.';
        }

        if (! $targetExists) {
            $blockers[] = 'Target public_html does not exist.';
        }

        $filesToCopy = $this->existingRelativeFiles();
        $directoriesToCopy = $this->existingRelativeDirectories();
        $backupCandidates = file_exists(self::TARGET_PUBLIC_HTML.'/index.html') ? ['index.html'] : [];
        $storage = $this->storagePlan($dryRun);
        $warnings = array_merge($warnings, $storage['warnings']);
        $blockers = array_merge($blockers, $storage['blockers']);

        if ($dryRun || count($blockers) > 0) {
            return $this->json([
                'ok' => count($blockers) === 0,
                'dry_run' => true,
                'source' => self::SOURCE_PUBLIC_HTML,
                'target' => self::TARGET_PUBLIC_HTML,
                'source_exists' => $sourceExists,
                'target_exists' => $targetExists,
                'files_that_would_copy' => $filesToCopy,
                'directories_that_would_copy' => $directoriesToCopy,
                'files_that_would_backup' => $backupCandidates,
                'files_that_would_skip' => $this->skippedPublicEntries($filesToCopy, $directoriesToCopy),
                'storage_plan' => $storage,
                'safe_to_run' => count($blockers) === 0,
                'copied_files' => [],
                'copied_directories' => [],
                'backed_up_files' => [],
                'skipped' => [],
                'storage' => $storage,
                'warnings' => $warnings,
                'blockers' => $blockers,
                'next_steps' => $this->nextSteps($blockers, $warnings),
            ]);
        }

        foreach ($filesToCopy as $relativeFile) {
            $this->copyFile($relativeFile);
            $copiedFiles[] = $relativeFile;
        }

        $this->prepareTargetIndexPhp();

        foreach ($directoriesToCopy as $relativeDirectory) {
            $this->copyDirectory($relativeDirectory);
            $copiedDirectories[] = $relativeDirectory;
        }

        $storage = $this->storagePlan(false);
        if (($storage['action'] ?? null) === 'create_symlink') {
            symlink((string) $storage['source_target'], self::TARGET_PUBLIC_HTML.'/storage');
            $storage['created'] = true;
        }

        if (file_exists(self::TARGET_PUBLIC_HTML.'/index.html') && file_exists(self::TARGET_PUBLIC_HTML.'/index.php') && $this->indexPointsToApp(self::TARGET_PUBLIC_HTML.'/index.php')) {
            $backup = 'index.html.directadmin.bak.'.now()->format('YmdHis');
            rename(self::TARGET_PUBLIC_HTML.'/index.html', self::TARGET_PUBLIC_HTML.'/'.$backup);
            $backedUpFiles[] = 'index.html => '.$backup;
        }

        return $this->json([
            'ok' => true,
            'dry_run' => false,
            'source_exists' => $sourceExists,
            'target_exists' => $targetExists,
            'copied_files' => $copiedFiles,
            'copied_directories' => $copiedDirectories,
            'backed_up_files' => $backedUpFiles,
            'skipped' => $this->skippedPublicEntries($filesToCopy, $directoriesToCopy),
            'storage' => $storage,
            'warnings' => array_merge($warnings, $storage['warnings']),
            'blockers' => [],
            'next_steps' => $this->nextSteps([], array_merge($warnings, $storage['warnings'])),
        ]);
    }

    private function unauthorizedResponse(Request $request): ?JsonResponse
    {
        if (hash_equals(self::TOKEN, (string) $request->query('token', ''))) {
            return null;
        }

        return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
    }

    /** @return list<string> */
    private function existingRelativeFiles(): array
    {
        return array_values(array_filter(self::PUBLIC_FILES, fn (string $file): bool => is_file(self::SOURCE_PUBLIC_HTML.'/'.$file)));
    }

    /** @return list<string> */
    private function existingRelativeDirectories(): array
    {
        return array_values(array_filter(self::PUBLIC_DIRECTORIES, fn (string $directory): bool => is_dir(self::SOURCE_PUBLIC_HTML.'/'.$directory)));
    }

    /** @return list<string> */
    private function skippedPublicEntries(array $filesToCopy, array $directoriesToCopy): array
    {
        $expected = array_merge(self::PUBLIC_FILES, self::PUBLIC_DIRECTORIES);
        $present = array_merge($filesToCopy, $directoriesToCopy);

        return array_values(array_diff($expected, $present));
    }

    private function copyFile(string $relativeFile): void
    {
        $source = self::SOURCE_PUBLIC_HTML.'/'.$relativeFile;
        $target = self::TARGET_PUBLIC_HTML.'/'.$relativeFile;
        $targetDirectory = dirname($target);

        if (! is_dir($targetDirectory)) {
            mkdir($targetDirectory, 0755, true);
        }

        copy($source, $target);
    }

    private function copyDirectory(string $relativeDirectory): void
    {
        $sourceRoot = self::SOURCE_PUBLIC_HTML.'/'.$relativeDirectory;
        $targetRoot = self::TARGET_PUBLIC_HTML.'/'.$relativeDirectory;

        if (! is_dir($targetRoot)) {
            mkdir($targetRoot, 0755, true);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $target = $targetRoot.'/'.substr($item->getPathname(), strlen($sourceRoot) + 1);
            if ($item->isDir()) {
                if (! is_dir($target)) {
                    mkdir($target, 0755, true);
                }
                continue;
            }

            copy($item->getPathname(), $target);
        }
    }

    private function prepareTargetIndexPhp(): void
    {
        $indexPath = self::TARGET_PUBLIC_HTML.'/index.php';
        $contents = file_get_contents($indexPath) ?: '';
        $contents = preg_replace("~require(?:_once)?\s+[^;]*vendor/autoload\.php['\"];~", "require '".self::APP_PATH."/vendor/autoload.php';", $contents) ?? $contents;
        $contents = preg_replace("~\$app\s*=\s*require(?:_once)?\s+[^;]*bootstrap/app\.php['\"];~", "\$app = require_once '".self::APP_PATH."/bootstrap/app.php';", $contents) ?? $contents;
        file_put_contents($indexPath, $contents);
    }

    private function indexPointsToApp(string $indexPath): bool
    {
        if (! is_file($indexPath)) {
            return false;
        }

        $contents = file_get_contents($indexPath) ?: '';

        return str_contains($contents, self::APP_PATH.'/vendor/autoload.php')
            && str_contains($contents, self::APP_PATH.'/bootstrap/app.php');
    }

    /** @return array<string, mixed> */
    private function storagePlan(bool $dryRun): array
    {
        $source = self::SOURCE_PUBLIC_HTML.'/storage';
        $target = self::TARGET_PUBLIC_HTML.'/storage';
        $warnings = [];
        $blockers = [];
        $plan = [
            'source' => $source,
            'target' => $target,
            'source_exists' => file_exists($source),
            'source_is_symlink' => is_link($source),
            'target_exists' => file_exists($target),
            'target_is_symlink' => is_link($target),
            'action' => 'none',
            'created' => false,
            'warnings' => [],
            'blockers' => [],
        ];

        if (! file_exists($source)) {
            $warnings[] = 'Source public_html/storage does not exist; storage was not planned.';
        } elseif (file_exists($target)) {
            $warnings[] = 'Target public_html/storage already exists; it will not be overwritten.';
        } elseif (is_link($source)) {
            $sourceTarget = readlink($source);
            if (is_string($sourceTarget) && ! str_starts_with($sourceTarget, '/')) {
                $sourceTarget = dirname($source).'/'.$sourceTarget;
            }
            $plan['source_target'] = $sourceTarget;
            $plan['action'] = 'create_symlink';
            $plan['can_create_symlink'] = function_exists('symlink') && is_writable(self::TARGET_PUBLIC_HTML);
            if (! $plan['can_create_symlink']) {
                $warnings[] = 'Source storage is a symlink, but target symlink creation may not be available/writable.';
                $plan['action'] = 'manual_required';
            }
        } elseif (is_dir($source)) {
            $warnings[] = 'Source public_html/storage is a directory; it will not be copied automatically to avoid copying large photo storage.';
            $plan['action'] = 'manual_required';
        }

        $plan['dry_run'] = $dryRun;
        $plan['warnings'] = $warnings;
        $plan['blockers'] = $blockers;

        return $plan;
    }

    /** @return list<string> */
    private function nextSteps(array $blockers, array $warnings): array
    {
        if (count($blockers) > 0) {
            return ['Resolve blockers, then run the dry-run endpoint again.'];
        }

        if (count($warnings) > 0) {
            return ['Review warnings/manual_required items before running live sync.', 'Run /tools/sync-gpswiss-public-html?token=...&dry_run=0 only after dry-run output is acceptable.'];
        }

        return ['Run the dry-run endpoint first, then live sync after confirming the plan.', 'After live sync, test gpswiss.pl manually.'];
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload): JsonResponse
    {
        return response()->json($payload, 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
