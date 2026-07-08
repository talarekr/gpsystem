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
const GITHUB_ZIP_URL = 'https://github.com/talarekr/gpsystem/archive/refs/heads/main.zip';
const STAGING_URL = 'https://gpsystem.thecamels.pl';
const EXPECTED_ADMIN_URL = 'https://gpsystem.thecamels.pl/admin';

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

function downloadZip(string $url, string $destination): void
{
    if (function_exists('curl_init')) {
        $file = fopen($destination, 'wb');
        if ($file === false) {
            throw new RuntimeException('Unable to open temporary ZIP for writing.');
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FILE => $file,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_FAILONERROR => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_USERAGENT => 'gpsystem-browser-deploy/2.0',
        ]);

        $success = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        fclose($file);

        if (!$success) {
            throw new RuntimeException('ZIP download failed via curl. HTTP status: ' . $status . '. ' . $error);
        }

        return;
    }

    $context = stream_context_create([
        'http' => [
            'follow_location' => 1,
            'timeout' => 300,
            'header' => "User-Agent: gpsystem-browser-deploy/2.0\r\n",
        ],
    ]);

    $data = @file_get_contents($url, false, $context);

    if ($data === false) {
        throw new RuntimeException('ZIP download failed via file_get_contents.');
    }

    if (file_put_contents($destination, $data) === false) {
        throw new RuntimeException('Unable to save downloaded ZIP.');
    }
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

function findVendorArchive(array $candidatePaths): ?string
{
    foreach ($candidatePaths as $candidatePath) {
        if (is_string($candidatePath) && file_exists($candidatePath)) {
            return $candidatePath;
        }
    }

    return null;
}

function extractVendorArchive(array $candidatePaths, string $appDir): void
{
    $zipPath = findVendorArchive($candidatePaths);

    if ($zipPath === null) {
        logLine('vendor.zip not found; composer dependencies may be missing; Socialite may not be installed. Preserving existing /app/vendor.', 'warn');
        return;
    }

    $vendorDir = $appDir . DIRECTORY_SEPARATOR . 'vendor';
    $backupVendorDir = $appDir . DIRECTORY_SEPARATOR . '.deploy-vendor-backup-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    $tempVendorDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'gpsystem-vendor-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    $vendorMovedToBackup = false;

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

        if (file_exists($vendorDir) || is_link($vendorDir)) {
            if (!@rename($vendorDir, $backupVendorDir)) {
                throw new RuntimeException('Unable to move existing /app/vendor to backup for atomic vendor swap.');
            }
            $vendorMovedToBackup = true;
            logLine('Existing /app/vendor moved to backup: ' . basename($backupVendorDir), 'ok');
        }

        ensureDirectory(dirname($vendorDir));

        if (!@rename($preparedVendorDir, $vendorDir)) {
            [$copied] = syncDirectory($preparedVendorDir, $vendorDir, static fn (): bool => false, false);
            logLine('vendor.zip fallback copied vendor files: ' . $copied, 'warn');
        }

        if (!file_exists($vendorDir . DIRECTORY_SEPARATOR . 'autoload.php')) {
            throw new RuntimeException('Extracted vendor.zip but /app/vendor/autoload.php is missing.');
        }

        if ($vendorMovedToBackup) {
            removePath($backupVendorDir);
            $vendorMovedToBackup = false;
            logLine('Old /app/vendor backup removed after successful vendor swap.', 'ok');
        }

        if ($zipPath === $appDir . DIRECTORY_SEPARATOR . 'vendor.zip' && !@unlink($zipPath)) {
            throw new RuntimeException('Extracted vendor.zip but could not delete archive: ' . $zipPath);
        }

        logLine('vendor.zip extracted into /app/vendor. Files: ' . $files . '; directories: ' . $dirs . '.', 'ok');
    } catch (Throwable $exception) {
        if ($vendorMovedToBackup) {
            removePath($vendorDir);
            @rename($backupVendorDir, $vendorDir);
            logLine('Restored previous /app/vendor backup after vendor.zip failure.', 'warn');
        }
        throw $exception;
    } finally {
        removePath($tempVendorDir);
        if ($vendorMovedToBackup && (file_exists($backupVendorDir) || is_link($backupVendorDir))) {
            removePath($backupVendorDir);
        }
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

function runLaravelMaintenance(string $appDir, bool $seedRoles): bool
{
    $autoloadPath = $appDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    $bootstrapPath = $appDir . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';

    if (!file_exists($autoloadPath)) {
        logLine('Laravel vendor/autoload.php is missing; migrations skipped. Upload /app/vendor.zip when dependencies changed or vendor is missing.', 'warn');
        return false;
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

    logLine('Clearing Laravel caches after deploy: optimize:clear, route:clear, view:clear, config:clear.');
    callArtisan($kernel, 'optimize:clear', [], false);
    callArtisan($kernel, 'route:clear', [], false);
    callArtisan($kernel, 'view:clear', [], false);
    callArtisan($kernel, 'config:clear', [], false);
    callArtisan($kernel, 'cache:clear', [], false);

    return true;
}

function logGoogleLoginDiagnostics(string $appDir, bool $migrateRan): void
{
    $autoloadPath = $appDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
    }

    $socialiteInstalled = class_exists('Laravel\\Socialite\\Facades\\Socialite');

    logLine('Post-deploy diagnostics: socialite_installed: ' . ($socialiteInstalled ? 'true' : 'false'), $socialiteInstalled ? 'ok' : 'warn');
    logLine('Post-deploy diagnostics: GOOGLE_CLIENT_ID present: ' . (deployEnvPresent('GOOGLE_CLIENT_ID') ? 'true' : 'false'));
    logLine('Post-deploy diagnostics: GOOGLE_CLIENT_SECRET present: ' . (deployEnvPresent('GOOGLE_CLIENT_SECRET') ? 'true' : 'false'));
    logLine('Post-deploy diagnostics: GOOGLE_REDIRECT_URI present: ' . (deployEnvPresent('GOOGLE_REDIRECT_URI') ? 'true' : 'false'));
    logLine('Post-deploy diagnostics: migrate executed: ' . ($migrateRan ? 'true' : 'false'), $migrateRan ? 'ok' : 'warn');
}

function deployEnvPresent(string $key): bool
{
    if (function_exists('env')) {
        $value = env($key);
        return is_string($value) ? trim($value) !== '' : !empty($value);
    }

    foreach ([$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)] as $value) {
        if (is_string($value) && trim($value) !== '') {
            return true;
        }
    }

    return false;
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

    logLine('Downloading GitHub ZIP: ' . GITHUB_ZIP_URL);
    downloadZip(GITHUB_ZIP_URL, $tempZip);
    logLine('GitHub ZIP downloaded to temporary storage.', 'ok');

    safeExtractZip($tempZip, $tempDir);
    logLine('GitHub ZIP extracted to temporary directory.', 'ok');

    $sourceDir = findExtractedRepository($tempDir);
    logLine('Found extracted repository package: ' . $sourceDir, 'ok');

    logLine('Copying repository package to /app while preserving .env, storage, and vendor.');
    [$copied, $createdDirs, $deleted] = syncDirectory($sourceDir, $appDir, 'appPathIsPreserved');
    logLine('App sync complete. Files copied: ' . $copied . '; directories ensured: ' . $createdDirs . '; stale paths removed: ' . $deleted . '.', 'ok');

    ensureRuntimeDirectories($appDir);

    extractVendorArchive([
        $appDir . DIRECTORY_SEPARATOR . 'vendor.zip',
        $sourceDir . DIRECTORY_SEPARATOR . 'vendor.zip',
    ], $appDir);

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
    $migrateRan = runLaravelMaintenance($appDir, (bool) $config['seed_roles']);
    logGoogleLoginDiagnostics($appDir, $migrateRan);

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
