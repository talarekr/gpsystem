<?php

namespace App\Support\ImportMigration;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ManualImportFileResolver
{
    public const WOO_DIRECTORY = 'imports/manual/woo';

    public function ensureWooDirectoryExists(): void
    {
        Storage::disk('local')->makeDirectory(self::WOO_DIRECTORY);
    }

    /** @return array<string, string> */
    public function availableWooFiles(): array
    {
        $this->ensureWooDirectoryExists();

        $files = collect(Storage::disk('local')->files(self::WOO_DIRECTORY))
            ->map(fn (string $path): string => basename($path))
            ->filter(fn (string $filename): bool => $filename !== '.gitignore')
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return $files->mapWithKeys(fn (string $filename): array => [$filename => $filename])->all();
    }

    public function resolveRequiredWooFile(mixed $filename, string $label, string $extension): string
    {
        if (blank($filename)) {
            throw new RuntimeException("Podaj nazwę pliku {$label} z folderu storage/app/".self::WOO_DIRECTORY.'.');
        }

        return $this->resolveWooFile((string) $filename, $label, $extension);
    }

    public function resolveOptionalWooFile(mixed $filename, string $label, string $extension): ?string
    {
        if (blank($filename)) {
            return null;
        }

        return $this->resolveWooFile((string) $filename, $label, $extension);
    }

    private function resolveWooFile(string $filename, string $label, string $extension): string
    {
        $this->ensureWooDirectoryExists();

        $filename = trim($filename);
        $extension = ltrim(strtolower($extension), '.');

        if (basename($filename) !== $filename || str_contains($filename, "\0")) {
            throw new RuntimeException("Nieprawidłowa nazwa pliku {$label}. Podaj tylko nazwę pliku bez katalogów.");
        }

        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== $extension) {
            throw new RuntimeException("Plik {$label} musi mieć rozszerzenie .{$extension}.");
        }

        $directoryPath = Storage::disk('local')->path(self::WOO_DIRECTORY);
        $filePath = Storage::disk('local')->path(self::WOO_DIRECTORY.'/'.$filename);
        $directoryRealPath = realpath($directoryPath);
        $fileRealPath = realpath($filePath);

        if ($directoryRealPath === false || $fileRealPath === false) {
            throw new RuntimeException("Brak pliku {$label}: storage/app/".self::WOO_DIRECTORY."/{$filename}.");
        }

        $directoryRealPath = rtrim($directoryRealPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (! str_starts_with($fileRealPath, $directoryRealPath)) {
            throw new RuntimeException("Nieprawidłowa ścieżka pliku {$label}. Plik musi znajdować się w storage/app/".self::WOO_DIRECTORY.'.');
        }

        if (! is_file($fileRealPath) || ! is_readable($fileRealPath)) {
            throw new RuntimeException("Nie można odczytać pliku {$label}: storage/app/".self::WOO_DIRECTORY."/{$filename}.");
        }

        return $fileRealPath;
    }
}
