<?php

namespace App\Support\ImportMigration;

use RuntimeException;

class ManualImportFileResolver
{
    public const WOO_DIRECTORY = 'imports/manual/woo';

    public function ensureWooDirectoryExists(): void
    {
        $directoryPath = $this->wooDirectoryPath();

        if (! is_dir($directoryPath)) {
            mkdir($directoryPath, 0755, true);
        }
    }

    public function wooDirectoryPath(): string
    {
        return storage_path('app/'.self::WOO_DIRECTORY);
    }

    /** @return array<string, string> */
    public function availableWooFiles(): array
    {
        $this->ensureWooDirectoryExists();

        $directoryPath = $this->wooDirectoryPath();
        $files = collect(scandir($directoryPath) ?: [])
            ->filter(fn (string $filename): bool => $filename !== '.' && $filename !== '..')
            ->filter(fn (string $filename): bool => $filename !== '.gitignore')
            ->filter(fn (string $filename): bool => is_file($directoryPath.DIRECTORY_SEPARATOR.$filename))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return $files->mapWithKeys(fn (string $filename): array => [$filename => $filename])->all();
    }

    public function resolveRequiredWooFile(mixed $filename, string $label, string $extension): string
    {
        if (blank($filename)) {
            throw new RuntimeException("Podaj nazwę pliku {$label} z folderu {$this->wooDirectoryPath()}.");
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

        $directoryPath = $this->wooDirectoryPath();
        $filePath = $directoryPath.DIRECTORY_SEPARATOR.$filename;
        $directoryRealPath = realpath($directoryPath);
        $fileRealPath = realpath($filePath);

        if ($directoryRealPath === false || $fileRealPath === false) {
            throw new RuntimeException("Brak pliku {$label}. Oczekiwany plik: {$filename}. Sprawdzona ścieżka: {$filePath}.");
        }

        $directoryRealPath = rtrim($directoryRealPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (! str_starts_with($fileRealPath, $directoryRealPath)) {
            throw new RuntimeException("Nieprawidłowa ścieżka pliku {$label}. Plik musi znajdować się w {$directoryPath}.");
        }

        if (! is_file($fileRealPath) || ! is_readable($fileRealPath)) {
            throw new RuntimeException("Nie można odczytać pliku {$label}. Oczekiwany plik: {$filename}. Sprawdzona ścieżka: {$filePath}.");
        }

        return $fileRealPath;
    }
}
