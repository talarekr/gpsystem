<?php

namespace App\Http\Controllers\Admin\ImportMigration;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

class WooStoragePublicController extends Controller
{
    public function diagnostics(): Response
    {
        $info = $this->storageInfo();
        $sampleFiles = $this->samplePhotoFiles($info['source_parts_photos_path']);

        return $this->html('Diagnostyka public storage', $this->diagnosticsHtml($info, $sampleFiles));
    }

    public function forceCopy(): Response
    {
        $info = $this->storageInfo();
        $warnings = [];
        $errors = [];
        $symlinkRemoved = false;
        $fallbackCopyUsed = false;
        $filesCopied = 0;
        $directoriesEnsured = 0;

        if (! is_dir($info['source'])) {
            $errors[] = 'Source storage does not exist or is not a directory.';
        } else {
            if (is_link($info['target'])) {
                if (@unlink($info['target'])) {
                    $symlinkRemoved = true;
                } else {
                    $errors[] = 'Target is a symlink, but unlink() failed. No files were deleted.';
                }
            } elseif (file_exists($info['target']) && ! is_dir($info['target'])) {
                $errors[] = 'Target exists as a regular file. Nothing was deleted.';
            }

            if ($errors === []) {
                $fallbackCopyUsed = true;

                try {
                    [$filesCopied, $directoriesEnsured] = $this->copyDirectoryWithoutDeleting($info['source'], $info['target']);
                } catch (Throwable $exception) {
                    $errors[] = 'Force fallback copy exception: '.$exception->getMessage();
                }
            }
        }

        $sampleFile = $this->samplePhotoFiles($info['source_parts_photos_path'])[0] ?? null;
        $testUrl = $sampleFile ? url('/storage/parts/photos/'.basename($sampleFile)) : null;

        $this->writeForceCopyLog($info, $symlinkRemoved, $fallbackCopyUsed, $filesCopied, $directoriesEnsured, $warnings, $errors);

        return $this->html('Force fallback copy public storage', $this->forceCopyHtml($info, $symlinkRemoved, $fallbackCopyUsed, $filesCopied, $directoriesEnsured, $warnings, $errors, $testUrl));
    }

    public function ensure(): Response
    {
        $info = $this->storageInfo();
        $warnings = [];
        $errors = [];
        $symlinkCreated = false;
        $fallbackCopyUsed = false;
        $filesCopied = 0;
        $directoriesEnsured = 0;
        $action = 'none';

        if (! is_dir($info['source'])) {
            $errors[] = 'Source storage does not exist or is not a directory.';
        } elseif (file_exists($info['target']) && ! is_link($info['target']) && ! is_dir($info['target'])) {
            $errors[] = 'Target exists as a regular file. Nothing was deleted.';
            $action = 'target_file_error';
        } elseif ($this->targetSymlinkPointsToSource($info['target'], $info['source'])) {
            $action = 'already_valid_symlink';
        } else {
            if (! file_exists($info['target']) && ! is_link($info['target'])) {
                $action = 'create_symlink';

                try {
                    $parent = dirname($info['target']);

                    if (! is_dir($parent)) {
                        mkdir($parent, 0755, true);
                    }

                    $symlinkCreated = @symlink($info['source'], $info['target']);

                    if (! $symlinkCreated) {
                        $warnings[] = 'PHP symlink() failed; fallback copy will be used.';
                    }
                } catch (Throwable $exception) {
                    $warnings[] = 'PHP symlink() exception: '.$exception->getMessage();
                }
            }

            if (! $symlinkCreated && ! $this->targetSymlinkPointsToSource($info['target'], $info['source'])) {
                $action = is_dir($info['target']) ? 'fallback_copy_existing_dir' : 'fallback_copy_after_symlink_failure';
                $fallbackCopyUsed = true;

                try {
                    [$filesCopied, $directoriesEnsured] = $this->copyDirectoryWithoutDeleting($info['source'], $info['target']);
                } catch (Throwable $exception) {
                    $errors[] = 'Fallback copy exception: '.$exception->getMessage();
                }
            }
        }

        $sampleFile = $this->samplePhotoFiles($info['source_parts_photos_path'])[0] ?? null;
        $testUrl = $sampleFile ? url('/storage/parts/photos/'.basename($sampleFile)) : null;

        $this->writeEnsureLog($info, $action, $symlinkCreated, $fallbackCopyUsed, $filesCopied, $directoriesEnsured, $warnings, $errors);

        return $this->html('Ensure public storage', $this->ensureHtml($info, $symlinkCreated, $fallbackCopyUsed, $filesCopied, $directoriesEnsured, $warnings, $errors, $testUrl));
    }

    private function storageInfo(): array
    {
        $source = storage_path('app/public');
        $target = dirname(base_path()).DIRECTORY_SEPARATOR.'public_html'.DIRECTORY_SEPARATOR.'storage';
        $readlink = is_link($target) ? readlink($target) : null;

        return [
            'source' => $source,
            'target' => $target,
            'source_exists' => is_dir($source),
            'source_readable' => is_dir($source) && is_readable($source),
            'target_exists' => file_exists($target) || is_link($target),
            'target_is_symlink' => is_link($target),
            'target_is_dir' => is_dir($target),
            'target_realpath' => realpath($target) ?: null,
            'target_readlink' => $readlink ?: null,
            'source_parts_photos_path' => $source.DIRECTORY_SEPARATOR.'parts'.DIRECTORY_SEPARATOR.'photos',
            'source_parts_photos_exists' => is_dir($source.DIRECTORY_SEPARATOR.'parts'.DIRECTORY_SEPARATOR.'photos'),
        ];
    }

    private function samplePhotoFiles(string $directory): array
    {
        if (! is_dir($directory) || ! is_readable($directory)) {
            return [];
        }

        $files = [];
        foreach (scandir($directory) ?: [] as $entry) {
            $path = $directory.DIRECTORY_SEPARATOR.$entry;
            if ($entry !== '.' && $entry !== '..' && is_file($path)) {
                $files[] = $path;
            }
            if (count($files) >= 5) {
                break;
            }
        }

        return $files;
    }

    private function targetSymlinkPointsToSource(string $target, string $source): bool
    {
        if (! is_link($target)) {
            return false;
        }

        $targetReal = realpath($target);
        $sourceReal = realpath($source);

        return $targetReal !== false && $sourceReal !== false && $targetReal === $sourceReal;
    }

    private function copyDirectoryWithoutDeleting(string $source, string $target): array
    {
        $filesCopied = 0;
        $directoriesEnsured = 0;

        if (! is_dir($target)) {
            mkdir($target, 0755, true);
            $directoriesEnsured++;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($source) + 1);
            $destination = $target.DIRECTORY_SEPARATOR.$relative;

            if ($item->isDir()) {
                if (! is_dir($destination)) {
                    mkdir($destination, 0755, true);
                    $directoriesEnsured++;
                }
                continue;
            }

            $parent = dirname($destination);
            if (! is_dir($parent)) {
                mkdir($parent, 0755, true);
                $directoriesEnsured++;
            }

            if (! is_file($destination) || filesize($destination) !== $item->getSize() || filemtime($item->getPathname()) > filemtime($destination)) {
                if (! copy($item->getPathname(), $destination)) {
                    throw new \RuntimeException('Could not copy file: '.$item->getPathname());
                }

                $filesCopied++;
            }
        }

        return [$filesCopied, $directoriesEnsured];
    }

    private function diagnosticsHtml(array $info, array $sampleFiles): string
    {
        $rows = '';
        foreach ($info as $key => $value) {
            if ($key === 'source_parts_photos_path') continue;
            $rows .= '<tr><th>'.$this->e($key).'</th><td>'.$this->e($this->stringValue($value)).'</td></tr>';
        }
        $files = $sampleFiles ? '<ol><li>'.implode('</li><li>', array_map(fn ($file) => $this->e($file), $sampleFiles)).'</li></ol>' : '<p>Brak przykładowych plików.</p>';
        $ensureUrl = route('admin.import-migration.woo-products.storage-public.ensure');
        $forceCopyUrl = route('admin.import-migration.woo-products.storage-public.force-copy');
        $csrf = csrf_field();

        return <<<HTML
<table border="1" cellpadding="6" cellspacing="0">{$rows}</table>
<p><strong>Hint operatora:</strong> Jeśli URL /storage/... zwraca Forbidden, użyj trybu fallback copy.</p>
<h2>Przykładowe pliki source/parts/photos</h2>{$files}
<form method="POST" action="{$ensureUrl}" style="margin-top: 20px;">{$csrf}<button type="submit">Napraw public storage</button></form>
<form method="POST" action="{$forceCopyUrl}" style="margin-top: 20px;">{$csrf}<p><strong>Ostrzeżenie:</strong> Użyj, jeśli symlink został utworzony, ale URL /storage/... zwraca Forbidden.</p><button type="submit">Wymuś fallback copy</button></form>
HTML;
    }

    private function ensureHtml(array $info, bool $symlinkCreated, bool $fallbackCopyUsed, int $filesCopied, int $directoriesEnsured, array $warnings, array $errors, ?string $testUrl): string
    {
        $warningsHtml = $warnings ? '<ul><li>'.implode('</li><li>', array_map(fn ($w) => $this->e($w), $warnings)).'</li></ul>' : '<p>Brak.</p>';
        $errorsHtml = $errors ? '<ul><li>'.implode('</li><li>', array_map(fn ($e) => $this->e($e), $errors)).'</li></ul>' : '<p>Brak.</p>';
        $testUrlHtml = $testUrl ? '<a href="'.$this->e($testUrl).'" target="_blank" rel="noopener">'.$this->e($testUrl).'</a>' : 'Brak przykładowego zdjęcia.';
        $logPath = storage_path('app/imports/manual/woo/storage_public_ensure.log');

        return '<dl>'
            .'<dt>source</dt><dd>'.$this->e($info['source']).'</dd>'
            .'<dt>target</dt><dd>'.$this->e($info['target']).'</dd>'
            .'<dt>symlink created</dt><dd>'.($symlinkCreated ? 'yes' : 'no').'</dd>'
            .'<dt>fallback copy used</dt><dd>'.($fallbackCopyUsed ? 'yes' : 'no').'</dd>'
            .'<dt>files copied count</dt><dd>'.$filesCopied.'</dd>'
            .'<dt>directories ensured count</dt><dd>'.$directoriesEnsured.'</dd>'
            .'<dt>test URL</dt><dd>'.$testUrlHtml.'</dd>'
            .'<dt>log</dt><dd>'.$this->e($logPath).'</dd>'
            .'</dl><h2>Warnings</h2>'.$warningsHtml.'<h2>Errors</h2>'.$errorsHtml;
    }

    private function forceCopyHtml(array $info, bool $symlinkRemoved, bool $fallbackCopyUsed, int $filesCopied, int $directoriesEnsured, array $warnings, array $errors, ?string $testUrl): string
    {
        $warningsHtml = $warnings ? '<ul><li>'.implode('</li><li>', array_map(fn ($w) => $this->e($w), $warnings)).'</li></ul>' : '<p>Brak.</p>';
        $errorsHtml = $errors ? '<ul><li>'.implode('</li><li>', array_map(fn ($e) => $this->e($e), $errors)).'</li></ul>' : '<p>Brak.</p>';
        $testUrlHtml = $testUrl ? '<a href="'.$this->e($testUrl).'" target="_blank" rel="noopener">'.$this->e($testUrl).'</a>' : 'Brak przykładowego zdjęcia.';
        $logPath = storage_path('app/imports/manual/woo/storage_public_ensure.log');

        return '<dl>'
            .'<dt>source</dt><dd>'.$this->e($info['source']).'</dd>'
            .'<dt>target</dt><dd>'.$this->e($info['target']).'</dd>'
            .'<dt>symlink_removed</dt><dd>'.($symlinkRemoved ? 'yes' : 'no').'</dd>'
            .'<dt>fallback copy used</dt><dd>'.($fallbackCopyUsed ? 'yes' : 'no').'</dd>'
            .'<dt>files copied count</dt><dd>'.$filesCopied.'</dd>'
            .'<dt>directories ensured count</dt><dd>'.$directoriesEnsured.'</dd>'
            .'<dt>test URL</dt><dd>'.$testUrlHtml.'</dd>'
            .'<dt>log</dt><dd>'.$this->e($logPath).'</dd>'
            .'</dl><h2>Warnings</h2>'.$warningsHtml.'<h2>Errors</h2>'.$errorsHtml;
    }

    private function writeEnsureLog(array $info, string $action, bool $symlinkCreated, bool $fallbackCopyUsed, int $filesCopied, int $directoriesEnsured, array $warnings, array $errors): void
    {
        $directory = storage_path('app/imports/manual/woo');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($directory.DIRECTORY_SEPARATOR.'storage_public_ensure.log', json_encode([
            'timestamp' => date(DATE_ATOM),
            'source' => $info['source'],
            'target' => $info['target'],
            'action' => $action,
            'symlink_result' => $symlinkCreated,
            'fallback_result' => $fallbackCopyUsed,
            'files_copied' => $filesCopied,
            'dirs_ensured' => $directoriesEnsured,
            'warnings' => $warnings,
            'errors' => $errors,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function writeForceCopyLog(array $info, bool $symlinkRemoved, bool $fallbackCopyUsed, int $filesCopied, int $directoriesEnsured, array $warnings, array $errors): void
    {
        $directory = storage_path('app/imports/manual/woo');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($directory.DIRECTORY_SEPARATOR.'storage_public_ensure.log', json_encode([
            'timestamp' => date(DATE_ATOM),
            'source' => $info['source'],
            'target' => $info['target'],
            'event' => 'force_copy',
            'symlink_removed' => $symlinkRemoved,
            'fallback_copy_used' => $fallbackCopyUsed,
            'files_copied' => $filesCopied,
            'dirs_ensured' => $directoriesEnsured,
            'warnings' => $warnings,
            'errors' => $errors,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function html(string $title, string $body): Response
    {
        return response('<!doctype html><html lang="pl"><head><meta charset="utf-8"><title>'.$this->e($title).'</title></head><body><h1>'.$this->e($title).'</h1>'.$body.'</body></html>')
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function stringValue(mixed $value): string
    {
        if (is_bool($value)) return $value ? 'yes' : 'no';
        if ($value === null) return '—';
        return (string) $value;
    }
}
