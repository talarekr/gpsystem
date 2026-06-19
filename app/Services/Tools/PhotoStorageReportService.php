<?php

namespace App\Services\Tools;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

class PhotoStorageReportService
{
    /** @return array<string, mixed> */
    public function report(): array
    {
        $publicHtml = dirname(base_path()).DIRECTORY_SEPARATOR.'public_html';
        $publicImported = $publicHtml.'/storage/parts/photos/imported';
        $publicPresentation = $publicHtml.'/storage/parts/photos/presentation';
        $appImported = storage_path('app/public/parts/photos/imported');
        $appPresentation = storage_path('app/public/parts/photos/presentation');
        $sourceUploads = storage_path('app/imports/gpswiss-uploads');
        $csv = storage_path('app/imports/product_images.csv');
        $imports = storage_path('app/imports');

        $publicStorage = public_path('storage');
        $storagePublic = storage_path('app/public');
        $documentRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), DIRECTORY_SEPARATOR);

        $appImportedSafeToDelete = is_dir($appImported) && is_dir($publicImported);
        $appPresentationSafeToDelete = is_dir($appPresentation) && is_dir($publicPresentation);
        $deleteNow = [];
        if ($appImportedSafeToDelete) {
            $deleteNow[] = $appImported;
        }
        if ($appPresentationSafeToDelete) {
            $deleteNow[] = $appPresentation;
        }

        return [
            'mode' => 'report_only',
            'safety' => 'Ten raport tylko czyta metadane plików. Nie usuwa ani nie modyfikuje plików, bazy danych, importerów ani runnerów.',
            'generated_at' => now()->toISOString(),
            'storefront_usage_summary' => [
                'storefront_url_prefix' => '/storage/parts/photos',
                'active_public_directories' => [$publicImported, $publicPresentation],
                'code_requires_laravel_public_disk' => false,
                'public_lookup_order' => [
                    'public_html/storage/{relativePath}',
                    'document_root/storage/{relativePath}',
                    "Storage::disk('public')->exists({relativePath}) fallback",
                ],
                'laravel_public_disk_directories_needed_by_current_code' => [],
                'reason' => 'Storefront sprawdza teraz najpierw fizyczne pliki w public_html/storage i document_root/storage. Laravel public disk jest tylko fallbackiem, więc kopie storage/app/public nie są wymagane przez kod URL po potwierdzeniu istnienia kopii public_html.',
            ],
            'paths' => [
                'base_path' => base_path(),
                'base_path_realpath' => realpath(base_path()) ?: null,
                'public_path' => public_path(),
                'public_path_realpath' => realpath(public_path()) ?: null,
                'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? null,
                'document_root_realpath' => $documentRoot === '' ? null : (realpath($documentRoot) ?: null),
                'sibling_public_html' => $publicHtml,
                'sibling_public_html_realpath' => realpath($publicHtml) ?: null,
            ],
            'public_storage_link' => [
                'path' => $publicStorage,
                'exists' => file_exists($publicStorage),
                'is_symlink' => is_link($publicStorage),
                'realpath' => realpath($publicStorage) ?: null,
                'expected_target' => $storagePublic,
                'expected_target_realpath' => realpath($storagePublic) ?: null,
                'points_to_expected_target' => $this->sameRealpath($publicStorage, $storagePublic),
            ],
            'directories' => [
                $this->directoryReport('public_html_storage_parts_photos_imported', $publicImported, false, 'AKTYWNE: oryginalne zdjęcia produktów dostępne pod /storage/parts/photos/imported; storefront używa ich jako fallback i dla zdjęć bez wariantów presentation.'),
                $this->directoryReport('public_html_storage_parts_photos_presentation', $publicPresentation, false, 'AKTYWNE: warianty listing/product używane przez listingi i karty produktu.'),
                $this->directoryReport('storage_app_public_parts_photos_imported', $appImported, $appImportedSafeToDelete, $appImportedSafeToDelete ? 'DUPLIKAT DO USUNIĘCIA: public_html/storage/parts/photos/imported istnieje, a kod URL nie wymaga już Laravel public disk dla zdjęć parts/photos.' : 'NIE USUWAĆ TERAZ: brak potwierdzonej kopii public_html/storage/parts/photos/imported albo katalog app nie istnieje.'),
                $this->directoryReport('storage_app_public_parts_photos_presentation', $appPresentation, $appPresentationSafeToDelete, $appPresentationSafeToDelete ? 'DUPLIKAT DO USUNIĘCIA: public_html/storage/parts/photos/presentation istnieje, a kod URL nie wymaga już Laravel public disk dla wariantów presentation.' : 'NIE USUWAĆ TERAZ: brak potwierdzonej kopii public_html/storage/parts/photos/presentation albo katalog app nie istnieje.'),
                $this->directoryReport('imports_gpswiss_uploads', $sourceUploads, false, 'NIE USUWAĆ TERAZ: źródła importu/fallback; bez dodatkowej weryfikacji procesu importu nie oznaczamy jako bezpieczne.'),
            ],
            'files' => [
                $this->fileReport('imports_product_images_csv', $csv, false, 'NIE USUWAĆ TERAZ: plik CSV importu może być potrzebny do audytu lub wznowienia importu.'),
            ],
            'archives' => $this->archiveReports($imports),
            'recommendations' => [
                'delete_now' => $deleteNow,
                'do_not_delete' => array_values(array_filter([
                    $publicImported,
                    $publicPresentation,
                    $appImportedSafeToDelete ? null : $appImported,
                    $appPresentationSafeToDelete ? null : $appPresentation,
                    $sourceUploads,
                    $csv,
                ])),
                'note' => 'Raport nadal niczego nie usuwa. Komendy usuwania pojawiają się tylko przy katalogach storage/app/public, gdy istnieje public_html copy i kod URL nie wymaga już Laravel public disk.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function directoryReport(string $label, string $path, bool $safe, string $reason): array
    {
        [$size, $count, $errors] = $this->scanDirectory($path);

        return [
            'label' => $label,
            'path' => $path,
            'exists' => file_exists($path),
            'is_dir' => is_dir($path),
            'size_bytes' => $size,
            'size_human' => $this->humanSize($size),
            'files_count' => $count,
            'safe_to_delete' => $safe,
            'reason' => $reason,
            'proposed_delete_command' => $safe ? 'rm -rf -- '.escapeshellarg($path) : null,
            'errors' => $errors,
        ];
    }

    /** @return array<string, mixed> */
    private function fileReport(string $label, string $path, bool $safe, string $reason): array
    {
        $isFile = is_file($path);
        $size = $isFile ? (int) filesize($path) : 0;

        return [
            'label' => $label,
            'path' => $path,
            'exists' => file_exists($path),
            'is_file' => $isFile,
            'size_bytes' => $size,
            'size_human' => $this->humanSize($size),
            'files_count' => $isFile ? 1 : 0,
            'safe_to_delete' => $safe,
            'reason' => $reason,
            'proposed_delete_command' => $safe ? 'rm -f -- '.escapeshellarg($path) : null,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function archiveReports(string $importsPath): array
    {
        $reports = [];
        if (! is_dir($importsPath)) {
            return $reports;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($importsPath, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file->isFile() || ! preg_match('/\.(zip|tar|tar\.gz|tgz|gz|7z|rar)$/i', $file->getFilename())) {
                continue;
            }
            $reports[] = $this->fileReport('imports_archive', $file->getPathname(), false, 'NIE USUWAĆ AUTOMATYCZNIE: archiwum importu; wymaga ręcznego potwierdzenia, że nie jest potrzebne do rollbacku/audytu.');
        }

        usort($reports, fn (array $a, array $b): int => $b['size_bytes'] <=> $a['size_bytes']);
        return $reports;
    }

    /** @return array{0:int,1:int,2:array<int,string>} */
    private function scanDirectory(string $path): array
    {
        $size = 0; $count = 0; $errors = [];
        if (! is_dir($path)) { return [$size, $count, $errors]; }
        try {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
            foreach ($iterator as $file) {
                try { if ($file->isFile()) { $count++; $size += $file->getSize(); } }
                catch (Throwable $e) { if (count($errors) < 20) { $errors[] = $e->getMessage(); } }
            }
        } catch (Throwable $e) { $errors[] = $e->getMessage(); }
        return [$size, $count, $errors];
    }

    private function sameRealpath(string $a, string $b): bool
    {
        $ra = realpath($a); $rb = realpath($b);
        return $ra !== false && $rb !== false && $ra === $rb;
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB']; $size = (float) $bytes; $i = 0;
        while ($size >= 1024 && $i < count($units) - 1) { $size /= 1024; $i++; }
        return $i === 0 ? $bytes.' '.$units[$i] : number_format($size, 2, '.', ' ').' '.$units[$i];
    }
}
