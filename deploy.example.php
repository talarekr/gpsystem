<?php

declare(strict_types=1);

/**
 * Browser-triggered Laravel staging deployment helper.
 *
 * Copy this file to the server as deploy.php, keep it in the Laravel project
 * root (the directory that contains artisan), and replace the placeholder
 * below with one fixed permanent staging token on the server only. Do not
 * commit a real token.
 *
 * Security notes:
 * - This helper is intended for staging/test deployments only, not final
 *   production CI/CD.
 * - Use one fixed permanent staging token that is long, random, and private.
 * - Consider IP-restricting this script at the web server level.
 * - Remove, rename, or IP-restrict deploy.php later if more security is needed.
 * - Never print secrets or .env contents in deployment output.
 */

const DEPLOY_TOKEN = 'CHANGE_ME_TO_LONG_RANDOM_TOKEN';
const GITHUB_ZIP_URL = 'https://github.com/talarekr/gpsystem/archive/refs/heads/main.zip';
const EXPECTED_ADMIN_URL = 'https://gpsystem.thecamels.pl/admin';

$config = [
    // The script should live in the Laravel project root. Override this only if
    // you place deploy.php elsewhere.
    'target_dir' => __DIR__,

    // Preserve vendor by default so a temporary Composer/network failure is less
    // likely to remove the currently working dependencies. Composer install still
    // runs after copying when composer is available.
    'preserve_vendor' => true,

    // Commands can be disabled on constrained shared hosting, but the default is
    // to run the expected Laravel post-deploy steps.
    'run_composer' => true,
    'run_artisan' => true,

    // Adjust paths if the hosting provider uses non-standard binaries.
    'php_binary' => 'php',
    'composer_binary' => 'composer',
];

header('Content-Type: text/html; charset=utf-8');

$startedAt = microtime(true);
$targetDir = realpath($config['target_dir']) ?: $config['target_dir'];
$lockHandle = null;
$tempZip = null;
$tempDir = null;

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

function removePath(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($path);
}

function isExcludedPath(string $relativePath, bool $preserveVendor): bool
{
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
    $segments = $relativePath === '' ? [] : explode('/', $relativePath);
    $basename = basename($relativePath);

    if ($relativePath === '') {
        return false;
    }

    $alwaysPreserve = [
        '.env',
        'storage',
        'deploy.php',
        'deploy.lock',
    ];

    if ($preserveVendor) {
        $alwaysPreserve[] = 'vendor';
    }

    if (in_array($relativePath, $alwaysPreserve, true) || in_array($segments[0] ?? '', $alwaysPreserve, true)) {
        return true;
    }

    $excludedNames = [
        '.git',
        'node_modules',
        '.DS_Store',
        '.idea',
        '.vscode',
        '.phpunit.result.cache',
        '.phpunit.cache',
        '.parcel-cache',
        '.vite',
        'npm-debug.log',
        'yarn-error.log',
        'pnpm-debug.log',
    ];

    if (in_array($basename, $excludedNames, true)) {
        return true;
    }

    foreach ($segments as $segment) {
        if (in_array($segment, ['.git', 'node_modules'], true)) {
            return true;
        }
    }

    if (preg_match('/(^|\\/)\.deploy-/u', $relativePath) === 1) {
        return true;
    }

    // Preserve/remove logs outside storage by excluding them from copy and delete.
    if (str_ends_with($basename, '.log')) {
        return true;
    }

    return false;
}

function copyFileWithDirectory(string $source, string $destination): void
{
    $destinationDir = dirname($destination);

    if (!is_dir($destinationDir) && !mkdir($destinationDir, 0755, true) && !is_dir($destinationDir)) {
        throw new RuntimeException('Unable to create directory: ' . $destinationDir);
    }

    if (!copy($source, $destination)) {
        throw new RuntimeException('Unable to copy file to: ' . $destination);
    }

    @chmod($destination, fileperms($source) & 0777);
}

function syncDirectory(string $sourceDir, string $targetDir, bool $preserveVendor): array
{
    $copied = 0;
    $deleted = 0;
    $createdDirs = 0;

    $sourceIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($sourceIterator as $sourceItem) {
        $relativePath = substr($sourceItem->getPathname(), strlen($sourceDir) + 1);
        $relativePath = str_replace('\\', '/', $relativePath);

        if (isExcludedPath($relativePath, $preserveVendor)) {
            continue;
        }

        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $relativePath;

        if ($sourceItem->isDir() && !$sourceItem->isLink()) {
            if (!is_dir($targetPath) && !mkdir($targetPath, 0755, true) && !is_dir($targetPath)) {
                throw new RuntimeException('Unable to create directory: ' . $targetPath);
            }
            $createdDirs++;
            continue;
        }

        copyFileWithDirectory($sourceItem->getPathname(), $targetPath);
        $copied++;
    }

    // Remove target files/directories that are no longer present in the source,
    // while preserving server-local runtime state and excluded paths.
    $targetIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($targetDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($targetIterator as $targetItem) {
        $relativePath = substr($targetItem->getPathname(), strlen($targetDir) + 1);
        $relativePath = str_replace('\\', '/', $relativePath);

        if (isExcludedPath($relativePath, $preserveVendor)) {
            continue;
        }

        $sourcePath = $sourceDir . DIRECTORY_SEPARATOR . $relativePath;

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
            CURLOPT_USERAGENT => 'gpsystem-browser-deploy/1.0',
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
            'header' => "User-Agent: gpsystem-browser-deploy/1.0\r\n",
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

function runCommand(string $command, string $cwd, bool $critical = true): void
{
    logLine('Running: ' . $command);

    if (!function_exists('proc_open')) {
        $message = 'proc_open is disabled; cannot run command: ' . $command;
        if ($critical) {
            throw new RuntimeException($message);
        }
        logLine($message, 'warn');
        return;
    }

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptorSpec, $pipes, $cwd);

    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start command: ' . $command);
    }

    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errorOutput = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    $combinedOutput = trim($output . "\n" . $errorOutput);

    if ($combinedOutput !== '') {
        $sanitized = preg_replace('/APP_KEY=base64:[A-Za-z0-9+\\/=]+/u', 'APP_KEY=[hidden]', $combinedOutput) ?? $combinedOutput;
        echo h($sanitized) . "\n";
    }

    if ($exitCode !== 0) {
        $message = 'Command failed with exit code ' . $exitCode . ': ' . $command;
        if ($critical) {
            throw new RuntimeException($message);
        }
        logLine($message, 'warn');
        return;
    }

    logLine('Command completed: ' . $command, 'ok');
}

try {
    echo '<!doctype html><html><head><meta charset="utf-8"><title>GPS browser deploy</title>';
    echo '<style>body{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;background:#0f172a;color:#e2e8f0;padding:24px;line-height:1.5}pre{white-space:pre-wrap}.ok{color:#86efac}</style>';
    echo '</head><body><h1>GPS browser deploy</h1><pre>';

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

    if (!is_dir($targetDir) || !is_writable($targetDir)) {
        failDeploy('Target directory is not writable: ' . $targetDir);
    }

    logLine('Token accepted.', 'ok');
    logLine('Target directory: ' . $targetDir);
    logLine('Expected admin URL after deploy: ' . EXPECTED_ADMIN_URL);

    $lockPath = $targetDir . DIRECTORY_SEPARATOR . 'deploy.lock';
    $lockHandle = fopen($lockPath, 'c');

    if ($lockHandle === false) {
        failDeploy('Unable to create deployment lock file.');
    }

    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        failDeploy('Another deployment is already running.', 423);
    }

    fwrite($lockHandle, 'Started at ' . date(DATE_ATOM) . PHP_EOL);
    logLine('Deployment lock acquired.', 'ok');

    $tempBase = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
    $uniqueId = 'gpsystem-deploy-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    $tempZip = $tempBase . DIRECTORY_SEPARATOR . $uniqueId . '.zip';
    $tempDir = $tempBase . DIRECTORY_SEPARATOR . $uniqueId;

    if (!mkdir($tempDir, 0755, true) && !is_dir($tempDir)) {
        failDeploy('Unable to create temporary extraction directory.');
    }

    logLine('Downloading GitHub ZIP: ' . GITHUB_ZIP_URL);
    downloadZip(GITHUB_ZIP_URL, $tempZip);
    logLine('ZIP downloaded to temporary storage.', 'ok');

    $zip = new ZipArchive();
    $opened = $zip->open($tempZip);

    if ($opened !== true) {
        failDeploy('ZIP extract failed; ZipArchive open code: ' . $opened);
    }

    if (!$zip->extractTo($tempDir)) {
        $zip->close();
        failDeploy('ZIP extract failed while extracting files.');
    }

    $zip->close();
    logLine('ZIP extracted to temporary directory.', 'ok');

    $sourceDir = findExtractedRepository($tempDir);
    logLine('Found extracted repository package: ' . $sourceDir, 'ok');

    logLine('Copying repository package to target while preserving .env, storage, runtime data, logs inside storage, and configured local paths.');
    [$copied, $createdDirs, $deleted] = syncDirectory($sourceDir, $targetDir, (bool) $config['preserve_vendor']);
    logLine('Copy complete. Files copied: ' . $copied . '; directories ensured: ' . $createdDirs . '; stale paths removed: ' . $deleted . '.', 'ok');

    if ($config['run_composer'] && file_exists($targetDir . DIRECTORY_SEPARATOR . 'composer.json')) {
        runCommand($config['composer_binary'] . ' install --no-dev --optimize-autoloader', $targetDir, true);
    } else {
        logLine('Composer install skipped by configuration or missing composer.json.', 'warn');
    }

    if ($config['run_artisan'] && file_exists($targetDir . DIRECTORY_SEPARATOR . 'artisan')) {
        $php = $config['php_binary'];
        runCommand($php . ' artisan migrate --force', $targetDir, true);
        runCommand($php . ' artisan storage:link', $targetDir, false);
        runCommand($php . ' artisan config:clear', $targetDir, true);
        runCommand($php . ' artisan cache:clear', $targetDir, true);
        runCommand($php . ' artisan config:cache', $targetDir, true);
        runCommand($php . ' artisan route:cache', $targetDir, true);
        runCommand($php . ' artisan view:cache', $targetDir, true);
        runCommand($php . ' artisan queue:restart', $targetDir, false);
    } else {
        logLine('Artisan commands skipped by configuration or missing artisan.', 'warn');
    }

    logLine('Cleaning up temporary files.');
    removePath($tempZip);
    removePath($tempDir);
    $tempZip = null;
    $tempDir = null;

    $duration = number_format(microtime(true) - $startedAt, 2);
    logLine('Deployment completed in ' . $duration . ' seconds.', 'ok');
    echo "\nNext checks:\n- Open https://gpsystem.thecamels.pl/\n- Open " . h(EXPECTED_ADMIN_URL) . "\n";
} catch (Throwable $exception) {
    logLine('Deployment stopped: ' . $exception->getMessage(), 'error');
} finally {
    if (is_string($tempZip)) {
        removePath($tempZip);
    }
    if (is_string($tempDir)) {
        removePath($tempDir);
    }
    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }

    echo '</pre></body></html>';
}
