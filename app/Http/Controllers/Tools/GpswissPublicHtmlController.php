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

        $indexInfo = $this->targetIndexPhpInfo($targetIndexPhp);
        $indexPointsToApp = (bool) $indexInfo['points_to_app_path'];
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
                'target_index_php_autoload_path' => $indexInfo['autoload_path'],
                'target_index_php_bootstrap_path' => $indexInfo['bootstrap_path'],
                'target_looks_like_laravel_public_html' => $looksLikeLaravel,
                'index_php_snippet_safe' => $indexPointsToApp ? null : $indexInfo['snippet_safe'],
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

        $indexPreparation = $this->prepareTargetIndexPhp();
        if (! $indexPreparation['ok']) {
            $blockers = array_merge($blockers, $indexPreparation['blockers']);

            return $this->json([
                'ok' => false,
                'dry_run' => false,
                'source_exists' => $sourceExists,
                'target_exists' => $targetExists,
                'copied_files' => $copiedFiles,
                'copied_directories' => $copiedDirectories,
                'backed_up_files' => $backedUpFiles,
                'skipped' => $this->skippedPublicEntries($filesToCopy, $directoriesToCopy),
                'storage' => $storage,
                'index_php_preparation' => $indexPreparation,
                'warnings' => array_merge($warnings, $storage['warnings']),
                'blockers' => $blockers,
                'next_steps' => $this->nextSteps($blockers, array_merge($warnings, $storage['warnings'])),
            ]);
        }

        foreach ($directoriesToCopy as $relativeDirectory) {
            $this->copyDirectory($relativeDirectory);
            $copiedDirectories[] = $relativeDirectory;
        }

        $storage = $this->storagePlan(false);

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
            'index_php_preparation' => $indexPreparation,
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

    /** @return array{ok: bool, autoload_replaced: bool, bootstrap_replaced: bool, autoload_path: ?string, bootstrap_path: ?string, snippet_safe: ?string, blockers: list<string>} */
    private function prepareTargetIndexPhp(): array
    {
        $indexPath = self::TARGET_PUBLIC_HTML.'/index.php';
        if (! is_file($indexPath)) {
            return [
                'ok' => false,
                'autoload_replaced' => false,
                'bootstrap_replaced' => false,
                'autoload_path' => null,
                'bootstrap_path' => null,
                'snippet_safe' => null,
                'blockers' => ['Target index.php does not exist after copy.'],
            ];
        }

        $contents = file_get_contents($indexPath) ?: '';
        $autoloadReplacement = "require '".self::APP_PATH."/vendor/autoload.php';";
        $bootstrapReplacement = "\$app = require_once '".self::APP_PATH."/bootstrap/app.php';";

        [$contents, $autoloadCount] = $this->replaceIndexPhpRequire($contents, 'vendor/autoload.php', $autoloadReplacement);
        [$contents, $bootstrapCount] = $this->replaceIndexPhpRequire($contents, 'bootstrap/app.php', $bootstrapReplacement, true);

        if ($autoloadCount === 0 || $bootstrapCount === 0) {
            $info = $this->indexPhpInfoFromContents($contents);

            return [
                'ok' => false,
                'autoload_replaced' => $autoloadCount > 0,
                'bootstrap_replaced' => $bootstrapCount > 0,
                'autoload_path' => $info['autoload_path'],
                'bootstrap_path' => $info['bootstrap_path'],
                'snippet_safe' => $info['snippet_safe'],
                'blockers' => ['Could not automatically rewrite target index.php Laravel app paths. Review index_php_preparation.snippet_safe and update the replacement patterns.'],
            ];
        }

        file_put_contents($indexPath, $contents);
        $info = $this->targetIndexPhpInfo($indexPath);

        return [
            'ok' => (bool) $info['points_to_app_path'],
            'autoload_replaced' => true,
            'bootstrap_replaced' => true,
            'autoload_path' => $info['autoload_path'],
            'bootstrap_path' => $info['bootstrap_path'],
            'snippet_safe' => $info['snippet_safe'],
            'blockers' => $info['points_to_app_path'] ? [] : ['Target index.php was rewritten, but does not point to the expected app path.'],
        ];
    }

    /** @return array{0: string, 1: int} */
    private function replaceIndexPhpRequire(string $contents, string $requiredSuffix, string $replacement, bool $allowAssignment = false): array
    {
        $suffix = preg_quote($requiredSuffix, '~');
        $prefix = $allowAssignment ? '(?:\$app\s*=\s*)?' : '';
        $pattern = '~'.$prefix.'require(?:_once)?\s*(?:\(?\s*)?(?:(?:__DIR__|dirname\s*\(\s*__DIR__\s*\)|base_path\s*\([^)]*\))\s*\.\s*)?[\'\"][^\'\"]*'.$suffix.'[\'\"]\s*\)?\s*;~m';
        $count = 0;
        $updated = preg_replace($pattern, $replacement, $contents, -1, $count);

        return [$updated ?? $contents, $count];
    }

    private function indexPointsToApp(string $indexPath): bool
    {
        return (bool) $this->targetIndexPhpInfo($indexPath)['points_to_app_path'];
    }

    /** @return array{points_to_app_path: bool, autoload_path: ?string, bootstrap_path: ?string, snippet_safe: ?string} */
    private function targetIndexPhpInfo(string $indexPath): array
    {
        if (! is_file($indexPath)) {
            return [
                'points_to_app_path' => false,
                'autoload_path' => null,
                'bootstrap_path' => null,
                'snippet_safe' => null,
            ];
        }

        return $this->indexPhpInfoFromContents(file_get_contents($indexPath) ?: '');
    }

    /** @return array{points_to_app_path: bool, autoload_path: ?string, bootstrap_path: ?string, snippet_safe: string} */
    private function indexPhpInfoFromContents(string $contents): array
    {
        $autoloadPath = $this->extractRequiredPath($contents, 'vendor/autoload.php');
        $bootstrapPath = $this->extractRequiredPath($contents, 'bootstrap/app.php');

        return [
            'points_to_app_path' => $autoloadPath === self::APP_PATH.'/vendor/autoload.php'
                && $bootstrapPath === self::APP_PATH.'/bootstrap/app.php',
            'autoload_path' => $autoloadPath,
            'bootstrap_path' => $bootstrapPath,
            'snippet_safe' => $this->safeSnippet($contents),
        ];
    }

    private function extractRequiredPath(string $contents, string $requiredSuffix): ?string
    {
        $suffix = preg_quote($requiredSuffix, '~');
        if (preg_match('~[\'\"]([^\'\"]*'.$suffix.')[\'\"]~', $contents, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function safeSnippet(string $contents): string
    {
        $snippet = implode("\n", array_slice(preg_split('/\R/', $contents) ?: [], 0, 80));
        $snippet = preg_replace('~token=([^\s\'\"]+)~i', 'token=[redacted]', $snippet) ?? $snippet;

        return mb_substr($snippet, 0, 4000);
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
            $plan['action'] = 'skipped_no_touch';
            $warnings[] = 'Source public_html/storage is a symlink; storage will not be touched by this sync.';
        } elseif (is_dir($source)) {
            $warnings[] = 'Source public_html/storage is a directory; storage will not be touched by this sync.';
            $plan['action'] = 'skipped_no_touch';
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
