<?php

declare(strict_types=1);

/**
 * Browser-triggered Laravel staging deployment helper for DirectAdmin/shared hosting.
 *
 * Copy this file to:
 * /home/gpsystem/domains/gpsystem.thecamels.pl/public_html/deploy.php
 *
 * Then replace DEPLOY_TOKEN on the server only with one fixed, long, random
 * staging token. Do not commit the real token.
 *
 * This helper intentionally does not use proc_open, shell_exec, exec, Composer,
 * or shell-based Artisan calls. It is staging-only glue for the current shared
 * hosting workflow; production should later move to VPS/SSH/CI/CD.
 */

const DEPLOY_TOKEN = 'CHANGE_ME_TO_LONG_RANDOM_TOKEN';
const GITHUB_REPOSITORY = 'talarekr/gpsystem';
const GITHUB_BRANCH = 'main';
const STAGING_URL = 'https://gpsystem.thecamels.pl';
const EXPECTED_ADMIN_URL = 'https://gpsystem.thecamels.pl/admin';

// Including the helper for isolated download tests must never execute a deployment.
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    return;
}

$config = [
    'app_dir' => '/home/gpsystem/domains/gpsystem.thecamels.pl/app',
    'public_dir' => '/home/gpsystem/domains/gpsystem.thecamels.pl/public_html',
    'seed_roles' => true,
    'stale_lock_seconds' => 1800,
];

header('Content-Type: text/html; charset=utf-8');
@ini_set('display_errors', '0');
@set_time_limit(600);

$startedAt = microtime(true);
$tempZip = null;
$tempDir = null;
$lockHandle = null;
$lockPath = rtrim($config['public_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'deploy.lock';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function logLine(string $message, string $level = 'info'): void
{
    $prefix = match ($level) {
        'ok' => '✅',
        'warn' => '⚠️',
        'error' => '❌',
        default => '•',
    };

    echo h($prefix . ' ' . $message) . "\n";

    if (function_exists('ob_flush')) {
        @ob_flush();
    }

    flush();
}

function failDeploy(string $message, int $httpStatus = 500): never
{
    if (!headers_sent()) {
        http_response_code($httpStatus);
    }

    logLine($message, 'error');
    throw new RuntimeException($message);
}

function normalizeRelativePath(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $parts = [];

    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }

        if ($part === '..') {
            throw new RuntimeException('Unsafe relative path contains ..: ' . $path);
        }

        $parts[] = $part;
    }

    return implode('/', $parts);
}

function removePath(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        if (!@unlink($path) && file_exists($path)) {
            throw new RuntimeException('Unable to remove file: ' . $path);
        }
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        $itemPath = $item->getPathname();
        if ($item->isDir() && !$item->isLink()) {
            @rmdir($itemPath);
        } else {
            @unlink($itemPath);
        }
    }

    @rmdir($path);
}

function ensureDirectory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create directory: ' . $path);
    }
}

function copyFileWithDirectory(string $source, string $destination): void
{
    ensureDirectory(dirname($destination));

    if (!copy($source, $destination)) {
        throw new RuntimeException('Unable to copy file to: ' . $destination);
    }

    @chmod($destination, fileperms($source) & 0777);
}

function appPathIsPreserved(string $relativePath): bool
{
    $relativePath = normalizeRelativePath($relativePath);
    $segments = $relativePath === '' ? [] : explode('/', $relativePath);
    $first = $segments[0] ?? '';
    $basename = basename($relativePath);

    if ($relativePath === '') {
        return false;
    }

    if (in_array($first, ['.env', 'storage', 'vendor'], true) || $relativePath === 'vendor.zip') {
        return true;
    }

    if (in_array($basename, ['.git', '.DS_Store'], true)) {
        return true;
    }

    if (in_array($first, ['.git', 'node_modules'], true) || in_array('node_modules', $segments, true)) {
        return true;
    }

    if (in_array($basename, ['.phpunit.result.cache', 'npm-debug.log', 'yarn-error.log', 'pnpm-debug.log'], true)) {
        return true;
    }

    if (str_ends_with($basename, '.log')) {
        return true;
    }

    return preg_match('/(^|\/)\.deploy-/u', $relativePath) === 1;
}

function publicPathIsPreserved(string $relativePath): bool
{
    $relativePath = normalizeRelativePath($relativePath);
    $segments = $relativePath === '' ? [] : explode('/', $relativePath);
    $basename = basename($relativePath);

    if ($relativePath === '') {
        return false;
    }

    if (in_array($relativePath, ['index.php', 'deploy.php', '.htaccess', 'deploy.lock', 'public-assets.zip'], true)) {
        return true;
    }

    if (in_array($basename, ['.DS_Store'], true)) {
        return true;
    }

    if (in_array($segments[0] ?? '', ['.git', 'node_modules'], true)) {
        return true;
    }

    return false;
}

function syncDirectory(string $sourceDir, string $targetDir, callable $isPreserved, bool $deleteStale = true): array
{
    $copied = 0;
    $createdDirs = 0;
    $deleted = 0;

    ensureDirectory($targetDir);

    $sourceIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($sourceIterator as $sourceItem) {
        $relativePath = normalizeRelativePath(substr($sourceItem->getPathname(), strlen($sourceDir) + 1));

        if ($isPreserved($relativePath)) {
            continue;
        }

        $targetPath = $targetDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        if ($sourceItem->isDir() && !$sourceItem->isLink()) {
            ensureDirectory($targetPath);
            $createdDirs++;
            continue;
        }

        copyFileWithDirectory($sourceItem->getPathname(), $targetPath);
        $copied++;
    }

    if (!$deleteStale) {
        return [$copied, $createdDirs, $deleted];
    }

    $targetIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($targetDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($targetIterator as $targetItem) {
        $relativePath = normalizeRelativePath(substr($targetItem->getPathname(), strlen($targetDir) + 1));

        if ($isPreserved($relativePath)) {
            continue;
        }

        $sourcePath = $sourceDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        if (file_exists($sourcePath) || is_link($sourcePath)) {
            continue;
        }

        if ($targetItem->isDir() && !$targetItem->isLink()) {
            @rmdir($targetItem->getPathname());
        } else {
            @unlink($targetItem->getPathname());
        }
        $deleted++;
    }

    return [$copied, $createdDirs, $deleted];
}


function copyPublicStorageFallback(string $sourceDir, string $targetDir): array
{
    $copied = 0;
    $createdDirs = 0;
    $warnings = [];

    ensureDirectory($targetDir);

    $sourceIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($sourceIterator as $sourceItem) {
        if ($sourceItem->isLink()) {
            $warnings[] = 'Skipped symlink inside storage public fallback: ' . $sourceItem->getPathname();
            continue;
        }

        $relativePath = normalizeRelativePath(substr($sourceItem->getPathname(), strlen($sourceDir) + 1));
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        if ($sourceItem->isDir()) {
            if (!is_dir($targetPath)) {
                ensureDirectory($targetPath);
                $createdDirs++;
            }
            continue;
        }

        copyFileWithDirectory($sourceItem->getPathname(), $targetPath);
        $copied++;
    }

    return [$copied, $createdDirs, $warnings];
}

function ensurePublicStorageAccess(string $appDir, string $publicDir): void
{
    $appStoragePublicPath = rtrim($appDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'public';
    $publicStoragePath = rtrim($publicDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'storage';

    logLine('Checking public storage target: ' . $publicStoragePath);
    logLine('Public storage source: ' . $appStoragePublicPath);
    logLine('public_html/storage exists: ' . ((file_exists($publicStoragePath) || is_link($publicStoragePath)) ? 'yes' : 'no'));
    logLine('public_html/storage is symlink: ' . (is_link($publicStoragePath) ? 'yes' : 'no'));

    if (!is_dir($appStoragePublicPath)) {
        logLine('Public storage source is missing; skipped symlink/fallback setup.', 'warn');
        return;
    }

    if (is_link($publicStoragePath)) {
        $linkTarget = readlink($publicStoragePath);
        if ($linkTarget !== false && realpath($publicStoragePath) === realpath($appStoragePublicPath)) {
            logLine('public_html/storage already points to app/storage/app/public; no action needed.', 'ok');
            return;
        }

        logLine('public_html/storage is a symlink but does not point to app/storage/app/public (target: ' . (string) $linkTarget . '). Fallback copy will not overwrite this symlink.', 'warn');
        return;
    }

    if (file_exists($publicStoragePath)) {
        if (is_dir($publicStoragePath)) {
            logLine('public_html/storage already exists as a regular directory; it will not be removed. Using safe fallback copy into the existing directory.', 'warn');
            [$copied, $createdDirs, $warnings] = copyPublicStorageFallback($appStoragePublicPath, $publicStoragePath);
            foreach ($warnings as $warning) {
                logLine($warning, 'warn');
            }
            logLine('Public storage fallback copy complete. Files copied: ' . $copied . '; directories ensured: ' . $createdDirs . '.', 'ok');
            return;
        }

        logLine('public_html/storage exists but is neither a directory nor a symlink; cannot safely replace it.', 'warn');
        return;
    }

    if (function_exists('symlink') && @symlink($appStoragePublicPath, $publicStoragePath)) {
        logLine('Created public_html/storage symlink to app/storage/app/public.', 'ok');
        return;
    }

    logLine('Could not create public_html/storage symlink; using fallback copy.', 'warn');
    [$copied, $createdDirs, $warnings] = copyPublicStorageFallback($appStoragePublicPath, $publicStoragePath);
    foreach ($warnings as $warning) {
        logLine($warning, 'warn');
    }
    logLine('Public storage fallback copy complete. Files copied: ' . $copied . '; directories ensured: ' . $createdDirs . '.', 'ok');
}

/** Only safe numeric transport metadata escapes this function; never curl_error(), bodies or headers. */
function requestDeployFile(string $url, string $destination, int $timeout): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('release_download_curl_required');
    }
    $file = @fopen($destination, 'wb');
    if ($file === false) {
        throw new RuntimeException('release_download_destination_unavailable');
    }
    @chmod($destination, 0600);
    $curl = curl_init($url);
    if ($curl === false) {
        fclose($file);
        return ['http_status' => 0, 'curl_errno' => 2, 'downloaded_bytes' => 0];
    }
    try {
        $options = [
            CURLOPT_FILE => $file,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_FAILONERROR => false,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'gpsystem-browser-deploy/3.0',
            CURLOPT_HTTPHEADER => [str_starts_with($url, 'https://api.github.com/') ? 'Accept: application/vnd.github+json' : 'Accept: application/zip'],
        ];
        if (defined('CURLOPT_PROTOCOLS_STR') && defined('CURLOPT_REDIR_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'https';
            $options[CURLOPT_REDIR_PROTOCOLS_STR] = 'https';
        } else {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
            $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        curl_setopt_array($curl, $options);
        curl_exec($curl);
        fflush($file);
        return ['http_status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'curl_errno' => curl_errno($curl), 'downloaded_bytes' => (int) fstat($file)['size']];
    } finally {
        curl_close($curl);
        fclose($file);
    }
}

/** Three attempts per source. No failed/partial body is retained for the next attempt. */
function downloadDeploySource(string $source, string $url, string $destination, string $expectedSha, int $timeout, callable $request, callable $pause, callable $log): bool
{
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        if (is_file($destination) && !@unlink($destination)) {
            throw new RuntimeException('release_download_cleanup_failed');
        }
        $result = $request($url, $destination, $timeout);
        clearstatcache(true, $destination);
        $status = (int) ($result['http_status'] ?? 0);
        $errno = (int) ($result['curl_errno'] ?? 0);
        $bytes = is_file($destination) ? (int) filesize($destination) : 0;
        $log('download source=' . $source . ' attempt=' . $attempt . '/3 http_status=' . $status . ' curl_errno=' . $errno . ' downloaded_bytes=' . $bytes . ' expected_sha=' . $expectedSha);
        if ($status === 200 && $errno === 0 && $bytes > 0) {
            return true;
        }
        @unlink($destination);
        $retryable = $errno !== 0 || $status === 0 || in_array($status, [429, 500, 502, 503, 504], true);
        if (!$retryable) {
            throw new RuntimeException('release_download_http_blocked source=' . $source . ' http_status=' . $status);
        }
        if ($attempt < 3) {
            $pause($attempt === 1 ? 250000 : 750000);
        }
    }
    return false;
}

/** Resolve main ONCE, before either ZIP source; never re-resolve on retry/fallback. */
function resolveExpectedDeploySha(string $referencePath, mixed $requiredSha = null, ?callable $request = null, ?callable $pause = null, ?callable $log = null): string
{
    if ($requiredSha !== null && (!is_string($requiredSha) || !preg_match('/\A[0-9a-f]{40}\z/iD', $requiredSha))) {
        throw new RuntimeException('expected_sha_invalid');
    }
    $request ??= 'requestDeployFile';
    $pause ??= 'usleep';
    $log ??= 'logLine';
    try {
        $url = 'https://api.github.com/repos/' . GITHUB_REPOSITORY . '/git/ref/heads/' . rawurlencode(GITHUB_BRANCH);
        if (!downloadDeploySource('github_api', $url, $referencePath, $requiredSha ?? 'unresolved', 10, $request, $pause, $log)) {
            throw new RuntimeException('expected_sha_resolution_unavailable');
        }
        $reference = json_decode((string) file_get_contents($referencePath), true);
        $sha = $reference['object']['sha'] ?? null;
        if (($reference['ref'] ?? null) !== 'refs/heads/' . GITHUB_BRANCH || ($reference['object']['type'] ?? null) !== 'commit' || !is_string($sha) || !preg_match('/\A[0-9a-f]{40}\z/iD', $sha)) {
            throw new RuntimeException('expected_sha_response_invalid');
        }
        $sha = strtolower($sha);
        if ($requiredSha !== null && !hash_equals(strtolower($requiredSha), $sha)) {
            throw new RuntimeException('expected_sha_mismatch');
        }
        $log('Resolved branch=' . GITHUB_BRANCH . ' expected_sha=' . $sha);
        return $sha;
    } finally {
        @unlink($referencePath);
    }
}

/** GitHub ZIPs have no .git; bind every entry to the full SHA root and check its commit comment when present. */
function verifyDeployZipSha(string $path, string $expectedSha): void
{
    if (!preg_match('/\A[0-9a-f]{40}\z/D', $expectedSha)) {
        throw new RuntimeException('expected_sha_invalid');
    }
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
        throw new RuntimeException('release_zip_invalid');
    }
    try {
        $root = basename(GITHUB_REPOSITORY) . '-' . $expectedSha . '/';
        $comment = trim((string) $zip->getArchiveComment());
        if ($comment !== '' && $comment !== $expectedSha) {
            throw new RuntimeException('release_zip_sha_mismatch');
        }
        if ($zip->numFiles === 0 || $zip->locateName($root . 'artisan') === false || $zip->locateName($root . 'composer.json') === false) {
            throw new RuntimeException('release_zip_sha_mismatch');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (!str_starts_with($name, $root) || str_contains($name, '\\') || preg_match('~(?:^|/)\.\.(?:/|$)~', $name)) {
                throw new RuntimeException('release_zip_sha_mismatch');
            }
        }
    } finally {
        $zip->close();
    }
}

function downloadZip(string $expectedSha, string $destination, ?callable $request = null, ?callable $pause = null, ?callable $log = null): string
{
    if (!preg_match('/\A[0-9a-f]{40}\z/D', $expectedSha)) {
        throw new RuntimeException('expected_sha_invalid');
    }
    $request ??= 'requestDeployFile';
    $pause ??= 'usleep';
    $log ??= 'logLine';
    $sources = [
        'github_archive' => 'https://github.com/' . GITHUB_REPOSITORY . '/archive/' . $expectedSha . '.zip',
        'codeload' => 'https://codeload.github.com/' . GITHUB_REPOSITORY . '/zip/' . $expectedSha,
    ];
    foreach ($sources as $source => $url) {
        if (downloadDeploySource($source, $url, $destination, $expectedSha, 60, $request, $pause, $log)) {
            verifyDeployZipSha($destination, $expectedSha);
            $log('final_source_used=' . $source . ' expected_sha=' . $expectedSha . ' archive_revision_verified=true');
            return $source;
        }
    }
    throw new RuntimeException('release_zip_sources_unavailable github_archive=exhausted codeload=exhausted expected_sha=' . $expectedSha);
}

function safeExtractZip(string $zipPath, string $destinationDir): array
{
    ensureDirectory($destinationDir);

    $zip = new ZipArchive();
    $opened = $zip->open($zipPath);

    if ($opened !== true) {
        throw new RuntimeException('ZIP open failed for ' . basename($zipPath) . '; ZipArchive code: ' . $opened);
    }

    $files = 0;
    $dirs = 0;

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);

        if (!is_string($name) || $name === '') {
            continue;
        }

        $relativePath = normalizeRelativePath($name);
        if ($relativePath === '') {
            continue;
        }

        $targetPath = $destinationDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        if (str_ends_with($name, '/') || str_ends_with($name, '\\')) {
            ensureDirectory($targetPath);
            $dirs++;
            continue;
        }

        ensureDirectory(dirname($targetPath));
        $stream = $zip->getStream($name);
        if ($stream === false) {
            $zip->close();
            throw new RuntimeException('Unable to read ZIP entry: ' . $relativePath);
        }

        $output = fopen($targetPath, 'wb');
        if ($output === false) {
            fclose($stream);
            $zip->close();
            throw new RuntimeException('Unable to write extracted file: ' . $targetPath);
        }

        stream_copy_to_stream($stream, $output);
        fclose($stream);
        fclose($output);
        $files++;
    }

    $zip->close();

    return [$files, $dirs];
}

function findExtractedRepository(string $extractDir): string
{
    $children = array_values(array_filter(scandir($extractDir) ?: [], static fn (string $name): bool => !in_array($name, ['.', '..'], true)));

    foreach ($children as $child) {
        $path = $extractDir . DIRECTORY_SEPARATOR . $child;
        if (is_dir($path) && file_exists($path . DIRECTORY_SEPARATOR . 'artisan') && file_exists($path . DIRECTORY_SEPARATOR . 'composer.json')) {
            return $path;
        }
    }

    throw new RuntimeException('Could not find extracted Laravel repository directory.');
}

function ensureRuntimeDirectories(string $appDir): void
{
    foreach ([
        'storage/framework/cache/data',
        'storage/framework/sessions',
        'storage/framework/views',
        'storage/logs',
        'bootstrap/cache',
    ] as $relativePath) {
        ensureDirectory($appDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
        logLine('Ensured runtime directory: ' . $relativePath, 'ok');
    }
}

function extractOptionalArchive(string $zipPath, string $destinationDir, string $label): void
{
    if (!file_exists($zipPath)) {
        logLine($label . ' not found; skipping optional archive extraction.', 'warn');
        return;
    }

    logLine('Found ' . $label . ': ' . $zipPath);
    [$files, $dirs] = safeExtractZip($zipPath, $destinationDir);

    if (!@unlink($zipPath)) {
        throw new RuntimeException('Extracted ' . $label . ' but could not delete archive: ' . $zipPath);
    }

    logLine($label . ' extracted into ' . $destinationDir . '. Files: ' . $files . '; directories: ' . $dirs . '. Archive deleted.', 'ok');
}

function extractVendorArchive(string $zipPath, string $appDir): void
{
    if (!file_exists($zipPath)) {
        logLine('vendor.zip not found; preserving existing /app/vendor.', 'warn');
        return;
    }

    $vendorDir = $appDir . DIRECTORY_SEPARATOR . 'vendor';
    $tempVendorDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'gpsystem-vendor-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));

    try {
        logLine('Found vendor.zip: ' . $zipPath);
        [$files, $dirs] = safeExtractZip($zipPath, $tempVendorDir);

        if (file_exists($tempVendorDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php')) {
            $preparedVendorDir = $tempVendorDir . DIRECTORY_SEPARATOR . 'vendor';
        } elseif (file_exists($tempVendorDir . DIRECTORY_SEPARATOR . 'autoload.php')) {
            $preparedVendorDir = $tempVendorDir;
        } else {
            throw new RuntimeException('vendor.zip must contain vendor/autoload.php or autoload.php at the archive root.');
        }

        removePath($vendorDir);
        ensureDirectory(dirname($vendorDir));

        if (!@rename($preparedVendorDir, $vendorDir)) {
            [$copied] = syncDirectory($preparedVendorDir, $vendorDir, static fn (): bool => false, false);
            logLine('vendor.zip fallback copied vendor files: ' . $copied, 'warn');
        }

        if (!@unlink($zipPath)) {
            throw new RuntimeException('Extracted vendor.zip but could not delete archive: ' . $zipPath);
        }

        logLine('vendor.zip extracted into /app/vendor. Files: ' . $files . '; directories: ' . $dirs . '. Archive deleted.', 'ok');
    } finally {
        removePath($tempVendorDir);
    }
}

function callArtisan(object $kernel, string $command, array $parameters = [], bool $critical = true): int
{
    logLine('Running Laravel Console Kernel: ' . $command . ' ' . trim(json_encode($parameters, JSON_UNESCAPED_SLASHES) ?: ''));

    try {
        $exitCode = (int) $kernel->call($command, $parameters);
        $output = method_exists($kernel, 'output') ? trim((string) $kernel->output()) : '';

        if ($output !== '') {
            echo h($output) . "\n";
        }

        if ($exitCode !== 0) {
            $message = 'Laravel Console Kernel command failed with exit code ' . $exitCode . ': ' . $command;
            if ($critical) {
                throw new RuntimeException($message);
            }
            logLine($message, 'warn');
        } else {
            logLine('Laravel Console Kernel command completed: ' . $command, 'ok');
        }

        return $exitCode;
    } catch (Throwable $exception) {
        if ($critical) {
            throw $exception;
        }

        logLine('Optional Laravel Console Kernel command failed: ' . $command . '. ' . $exception->getMessage(), 'warn');
        return 1;
    }
}

function runLaravelMaintenance(string $appDir, bool $seedRoles): void
{
    $autoloadPath = $appDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    $bootstrapPath = $appDir . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';

    if (!file_exists($autoloadPath)) {
        logLine('Laravel vendor/autoload.php is missing; migrations skipped. Upload /app/vendor.zip when dependencies changed or vendor is missing.', 'warn');
        return;
    }

    if (!file_exists($bootstrapPath)) {
        throw new RuntimeException('Laravel bootstrap/app.php is missing; cannot run migrations.');
    }

    require_once $autoloadPath;
    $app = require $bootstrapPath;

    if (!is_object($app) || !method_exists($app, 'make')) {
        throw new RuntimeException('Laravel bootstrap did not return an application container.');
    }

    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    callArtisan($kernel, 'migrate', ['--force' => true], true);

    if ($seedRoles) {
        callArtisan($kernel, 'db:seed', ['--class' => 'RoleSeeder', '--force' => true], false);
    }

    // Application cache holds translation run checkpoints and locks. Preserve it.
    logLine('Clearing compiled route/view/config caches; preserving application cache and checkpoints.');
    callArtisan($kernel, 'route:clear', [], false);
    callArtisan($kernel, 'view:clear', [], false);
    callArtisan($kernel, 'config:clear', [], false);

    if (function_exists('opcache_reset')) {
        if (@opcache_reset()) {
            logLine('PHP OPcache reset after deploy.', 'ok');
        } else {
            logLine('PHP OPcache reset was requested but not completed by the runtime.', 'warn');
        }
    } else {
        logLine('PHP OPcache reset skipped: opcache_reset() is unavailable.', 'warn');
    }
}

function acquireDeployLock(string $lockPath, int $staleSeconds): mixed
{
    if (isset($_GET['unlock']) && $_GET['unlock'] === '1') {
        if (file_exists($lockPath)) {
            @unlink($lockPath);
        }
        logLine('Manual unlock requested; removed deploy lock if it existed.', 'warn');
    }

    if (file_exists($lockPath) && (time() - filemtime($lockPath)) > $staleSeconds) {
        @unlink($lockPath);
        logLine('Removed stale deploy lock older than ' . $staleSeconds . ' seconds.', 'warn');
    }

    $handle = fopen($lockPath, 'c');
    if ($handle === false) {
        throw new RuntimeException('Unable to create deployment lock file: ' . $lockPath);
    }

    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        throw new RuntimeException('Another deployment is already running. If this is stale, retry with &unlock=1 after confirming no deploy is active.');
    }

    ftruncate($handle, 0);
    fwrite($handle, 'Started at ' . date(DATE_ATOM) . PHP_EOL);

    return $handle;
}

try {
    echo '<!doctype html><html><head><meta charset="utf-8"><title>GPS staging browser deploy</title>';
    echo '<style>body{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;background:#0f172a;color:#e2e8f0;padding:24px;line-height:1.5}pre{white-space:pre-wrap}.ok{color:#86efac}</style>';
    echo '</head><body><h1>GPS staging browser deploy</h1><pre>';

    $providedToken = (string) ($_GET['token'] ?? '');

    if (DEPLOY_TOKEN === 'CHANGE_ME_TO_LONG_RANDOM_TOKEN' || DEPLOY_TOKEN === '') {
        failDeploy('Deploy token is not configured on this server.', 403);
    }

    if ($providedToken === '' || !hash_equals(DEPLOY_TOKEN, $providedToken)) {
        failDeploy('Invalid deploy token.', 403);
    }

    if (!extension_loaded('zip') || !class_exists(ZipArchive::class)) {
        failDeploy('PHP ZipArchive extension is required.');
    }

    $appDir = rtrim((string) $config['app_dir'], DIRECTORY_SEPARATOR);
    $publicDir = rtrim((string) $config['public_dir'], DIRECTORY_SEPARATOR);

    if (!is_dir($appDir) || !is_writable($appDir)) {
        failDeploy('Laravel app directory is not writable: ' . $appDir);
    }

    if (!is_dir($publicDir) || !is_writable($publicDir)) {
        failDeploy('Public directory is not writable: ' . $publicDir);
    }

    logLine('Token accepted.', 'ok');
    logLine('App directory: ' . $appDir);
    logLine('Public directory: ' . $publicDir);
    logLine('No Composer, shell, proc_open, external API writes, or marketplace publishing will be used.', 'ok');
    logLine('Expected URLs after deploy: ' . STAGING_URL . ' and ' . EXPECTED_ADMIN_URL);

    $lockHandle = acquireDeployLock($lockPath, (int) $config['stale_lock_seconds']);
    logLine('Deployment lock acquired.', 'ok');

    $tempBase = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
    $uniqueId = 'gpsystem-deploy-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    $tempZip = $tempBase . DIRECTORY_SEPARATOR . $uniqueId . '.zip';
    $tempDir = $tempBase . DIRECTORY_SEPARATOR . $uniqueId;
    ensureDirectory($tempDir);

    $expectedSha = resolveExpectedDeploySha($tempZip . '.ref.json', $_GET['expected_sha'] ?? null);
    logLine('Downloading GitHub ZIP for expected SHA: ' . $expectedSha);
    downloadZip($expectedSha, $tempZip);
    logLine('GitHub ZIP downloaded to temporary storage.', 'ok');

    safeExtractZip($tempZip, $tempDir);
    logLine('GitHub ZIP extracted to temporary directory.', 'ok');

    $sourceDir = findExtractedRepository($tempDir);
    if (basename($sourceDir) !== basename(GITHUB_REPOSITORY) . '-' . $expectedSha) {
        throw new RuntimeException('extracted_release_sha_mismatch');
    }
    logLine('Extracted release verified: expected_sha=' . $expectedSha, 'ok');
    logLine('Found extracted repository package: ' . $sourceDir, 'ok');

    logLine('Copying repository package to /app while preserving .env, storage, and vendor.');
    [$copied, $createdDirs, $deleted] = syncDirectory($sourceDir, $appDir, 'appPathIsPreserved');
    logLine('App sync complete. Files copied: ' . $copied . '; directories ensured: ' . $createdDirs . '; stale paths removed: ' . $deleted . '.', 'ok');

    ensureRuntimeDirectories($appDir);

    extractVendorArchive($appDir . DIRECTORY_SEPARATOR . 'vendor.zip', $appDir);

    $repoPublicDir = $appDir . DIRECTORY_SEPARATOR . 'public';
    if (is_dir($repoPublicDir)) {
        logLine('Syncing deployable repository public files from /app/public to /public_html while preserving index.php, deploy.php, and .htaccess.');
        [$publicCopied, $publicCreatedDirs, $publicDeleted] = syncDirectory($repoPublicDir, $publicDir, 'publicPathIsPreserved', false);
        logLine('Public sync complete. Files copied: ' . $publicCopied . '; directories ensured: ' . $publicCreatedDirs . '; stale paths removed: ' . $publicDeleted . ' (disabled for public assets).', 'ok');
    } else {
        logLine('/app/public is missing; public file sync skipped.', 'warn');
    }

    ensurePublicStorageAccess($appDir, $publicDir);

    extractOptionalArchive(
        $publicDir . DIRECTORY_SEPARATOR . 'public-assets.zip',
        $publicDir,
        'public-assets.zip'
    );

    logLine('Running migrations through Laravel Console Kernel without shell/proc_open.');
    runLaravelMaintenance($appDir, (bool) $config['seed_roles']);

    logLine('Cleaning up temporary files.');
    removePath($tempZip);
    removePath($tempDir);
    $tempZip = null;
    $tempDir = null;

    $duration = number_format(microtime(true) - $startedAt, 2);
    logLine('Deployment completed in ' . $duration . ' seconds.', 'ok');
    echo "\nNext checks:\n- Open " . h(STAGING_URL) . "\n- Open " . h(EXPECTED_ADMIN_URL) . "\n";
} catch (Throwable $exception) {
    logLine('Deployment stopped: ' . $exception->getMessage(), 'error');
} finally {
    if (is_string($tempZip)) {
        try {
            removePath($tempZip);
        } catch (Throwable) {
            // Nothing useful can be done here in a browser helper.
        }
    }

    if (is_string($tempDir)) {
        try {
            removePath($tempDir);
        } catch (Throwable) {
            // Nothing useful can be done here in a browser helper.
        }
    }

    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        @unlink($lockPath);
    }

    echo '</pre></body></html>';
}
