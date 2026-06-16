<?php

namespace App\Support\ImportMigration;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;

class UploadedImportFileResolver
{
    public function resolveRequired(mixed $state, string $label, string $importType, string $batchDirectory): string
    {
        return $this->resolve($state, $label, $importType, $batchDirectory)
            ?? throw new RuntimeException("Nie można odczytać przesłanego pliku {$label}. Spróbuj ponownie przesłać plik.");
    }

    public function resolveOptional(mixed $state, string $label, string $importType, string $batchDirectory): ?string
    {
        if (blank($state)) {
            return null;
        }

        return $this->resolve($state, $label, $importType, $batchDirectory);
    }

    private function resolve(mixed $state, string $label, string $importType, string $batchDirectory): ?string
    {
        $file = $this->firstFileState($state);

        if (blank($file)) {
            return null;
        }

        $sourcePath = $this->sourcePath($file);

        if (! is_string($sourcePath) || ! is_file($sourcePath) || ! is_readable($sourcePath)) {
            throw new RuntimeException("Nie można odczytać przesłanego pliku {$label}. Spróbuj ponownie przesłać plik.");
        }

        return $this->copyToStableImportDirectory($sourcePath, $label, $importType, $batchDirectory);
    }

    private function firstFileState(mixed $state): mixed
    {
        if (! is_array($state)) {
            return $state;
        }

        if ($state === []) {
            return null;
        }

        return reset($state);
    }

    private function sourcePath(mixed $file): ?string
    {
        if ($file instanceof TemporaryUploadedFile) {
            return $file->getRealPath();
        }

        if ($file instanceof UploadedFile) {
            return $file->getRealPath();
        }

        if (! is_string($file) || blank($file)) {
            return null;
        }

        if (is_file($file)) {
            return $file;
        }

        foreach ($this->candidateDisks($file) as $disk) {
            if (Storage::disk($disk)->exists($file)) {
                return Storage::disk($disk)->path($file);
            }
        }

        return null;
    }

    /** @return list<string> */
    private function candidateDisks(string $path): array
    {
        $disks = [];

        if (Str::startsWith($path, $this->livewireTemporaryDirectory().'/')) {
            $disks[] = $this->livewireTemporaryDisk();
        }

        $disks[] = 'local';
        $disks[] = config('filesystems.default', 'local');

        return array_values(array_unique(array_filter($disks)));
    }

    private function livewireTemporaryDisk(): string
    {
        return config('livewire.temporary_file_upload.disk') ?: config('filesystems.default', 'local');
    }

    private function livewireTemporaryDirectory(): string
    {
        return trim(config('livewire.temporary_file_upload.directory') ?: 'livewire-tmp', '/');
    }

    private function copyToStableImportDirectory(string $sourcePath, string $label, string $importType, string $batchDirectory): string
    {
        $safeLabel = basename($label);
        $targetRelativePath = "imports/{$importType}/{$batchDirectory}/{$safeLabel}";
        $targetPath = Storage::disk('local')->path($targetRelativePath);

        if (! is_dir(dirname($targetPath))) {
            mkdir(dirname($targetPath), 0777, true);
        }

        if (! copy($sourcePath, $targetPath)) {
            throw new RuntimeException("Nie można odczytać przesłanego pliku {$label}. Spróbuj ponownie przesłać plik.");
        }

        return $targetPath;
    }
}
