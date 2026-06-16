<?php

namespace App\Services\Images;

use App\Models\PartImage;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PartImagePresentationService
{
    private const WHITE_THRESHOLD = 245;
    private const TARGET_FILL = 0.90;

    public function process(PartImage $partImage): array
    {
        $warnings = [];
        $payload = $partImage->legacy_payload ?? [];

        if (! extension_loaded('gd') || ! function_exists('imagecreatetruecolor')) {
            $warnings[] = 'GD extension is not available; presentation variants were not generated.';
            $this->writeLog($partImage, $payload['presentation'] ?? [], $warnings);

            return $this->mergePresentation($partImage, [
                'source_path' => $partImage->path,
                'processed_at' => now()->toISOString(),
                'processor' => 'none',
                'warnings' => $warnings,
            ]);
        }

        $sourcePath = trim((string) $partImage->path);
        $absoluteSourcePath = $this->absoluteSourcePath($sourcePath);

        if ($sourcePath === '' || ! $absoluteSourcePath || ! is_file($absoluteSourcePath)) {
            $warnings[] = 'Source image file does not exist or is not readable.';
            $presentation = [
                'source_path' => $sourcePath,
                'processed_at' => now()->toISOString(),
                'processor' => 'gd',
                'warnings' => $warnings,
            ];
            $this->writeLog($partImage, $presentation, $warnings);

            return $this->mergePresentation($partImage, $presentation);
        }

        try {
            $source = $this->loadImage($absoluteSourcePath);

            if (! $source) {
                $warnings[] = 'Unsupported or unreadable image format.';
                $presentation = ['source_path' => $sourcePath, 'processed_at' => now()->toISOString(), 'processor' => 'gd', 'warnings' => $warnings];
                $this->writeLog($partImage, $presentation, $warnings);

                return $this->mergePresentation($partImage, $presentation);
            }

            $box = $this->detectObjectBox($source);

            if (! $box) {
                $warnings[] = 'Object bounding box was not detected; using contain fallback on the full image.';
                $box = ['x' => 0, 'y' => 0, 'width' => imagesx($source), 'height' => imagesy($source)];
            }

            $baseName = $this->variantBaseName($partImage, $sourcePath);
            $listingPath = 'parts/photos/presentation/listing/'.$baseName.'.jpg';
            $productPath = 'parts/photos/presentation/product/'.$baseName.'.jpg';

            $this->renderVariant($source, $box, 522, 336, $listingPath);
            $this->renderVariant($source, $box, 900, 675, $productPath);
            imagedestroy($source);

            $presentation = [
                'listing_path' => $listingPath,
                'product_path' => $productPath,
                'source_path' => $sourcePath,
                'processed_at' => now()->toISOString(),
                'processor' => 'gd',
                'bounding_box' => $box,
            ];

            if ($warnings) {
                $presentation['warnings'] = $warnings;
            }

            $this->writeLog($partImage, $presentation, $warnings);

            return $this->mergePresentation($partImage, $presentation);
        } catch (Throwable $exception) {
            $warnings[] = $exception->getMessage();
            $presentation = ['source_path' => $sourcePath, 'processed_at' => now()->toISOString(), 'processor' => 'gd', 'warnings' => $warnings];
            $this->writeLog($partImage, $presentation, $warnings);

            return $this->mergePresentation($partImage, $presentation);
        }
    }

    private function mergePresentation(PartImage $partImage, array $presentation): array
    {
        return array_replace_recursive($partImage->legacy_payload ?? [], ['presentation' => $presentation]);
    }

    private function absoluteSourcePath(string $path): ?string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return null;
        }

        $relative = ltrim(str_replace('/storage/', '', $path), '/');
        $publicPath = Storage::disk('public')->path($relative);

        return is_file($publicPath) ? $publicPath : (is_file(public_path($relative)) ? public_path($relative) : null);
    }

    private function loadImage(string $path): mixed
    {
        return match (exif_imagetype($path)) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($path),
            IMAGETYPE_PNG => imagecreatefrompng($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($path) : false,
            IMAGETYPE_GIF => imagecreatefromgif($path),
            default => false,
        };
    }

    private function detectObjectBox(mixed $image): ?array
    {
        $width = imagesx($image); $height = imagesy($image);
        $minX = $width; $minY = $height; $maxX = -1; $maxY = -1;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgba = imagecolorat($image, $x, $y);
                $a = ($rgba & 0x7F000000) >> 24;
                $r = ($rgba >> 16) & 0xFF; $g = ($rgba >> 8) & 0xFF; $b = $rgba & 0xFF;
                if ($a >= 127 || ($r > self::WHITE_THRESHOLD && $g > self::WHITE_THRESHOLD && $b > self::WHITE_THRESHOLD)) {
                    continue;
                }
                $minX = min($minX, $x); $minY = min($minY, $y); $maxX = max($maxX, $x); $maxY = max($maxY, $y);
            }
        }

        if ($maxX < 0 || $maxY < 0) {
            return null;
        }

        $boxWidth = $maxX - $minX + 1; $boxHeight = $maxY - $minY + 1;
        $marginX = (int) ceil($boxWidth * 0.06); $marginY = (int) ceil($boxHeight * 0.06);

        $x = max(0, $minX - $marginX); $y = max(0, $minY - $marginY);
        $right = min($width - 1, $maxX + $marginX); $bottom = min($height - 1, $maxY + $marginY);

        return ['x' => $x, 'y' => $y, 'width' => $right - $x + 1, 'height' => $bottom - $y + 1];
    }

    private function renderVariant(mixed $source, array $box, int $canvasWidth, int $canvasHeight, string $targetPath): void
    {
        $canvas = imagecreatetruecolor($canvasWidth, $canvasHeight);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);

        $scale = min(($canvasWidth * self::TARGET_FILL) / $box['width'], ($canvasHeight * self::TARGET_FILL) / $box['height']);
        $targetWidth = max(1, (int) floor($box['width'] * $scale));
        $targetHeight = max(1, (int) floor($box['height'] * $scale));
        $dstX = (int) floor(($canvasWidth - $targetWidth) / 2);
        $dstY = (int) floor(($canvasHeight - $targetHeight) / 2);

        imagecopyresampled($canvas, $source, $dstX, $dstY, $box['x'], $box['y'], $targetWidth, $targetHeight, $box['width'], $box['height']);
        Storage::disk('public')->makeDirectory(dirname($targetPath));
        imagejpeg($canvas, Storage::disk('public')->path($targetPath), 88);
        imagedestroy($canvas);
    }

    private function variantBaseName(PartImage $partImage, string $sourcePath): string
    {
        return $partImage->id.'-'.substr(sha1($sourcePath.'|'.$partImage->updated_at?->timestamp), 0, 12);
    }

    private function writeLog(PartImage $partImage, array $presentation, array $warnings = []): void
    {
        $dir = storage_path('app/imports/manual/woo');
        if (! is_dir($dir)) { mkdir($dir, 0755, true); }
        file_put_contents($dir.'/part_image_presentation.log', json_encode([
            'timestamp' => now()->toISOString(),
            'part_image_id' => $partImage->id,
            'part_id' => $partImage->part_id,
            'source_path' => $presentation['source_path'] ?? $partImage->path,
            'listing_path' => $presentation['listing_path'] ?? null,
            'product_path' => $presentation['product_path'] ?? null,
            'bounding_box' => $presentation['bounding_box'] ?? null,
            'processor' => $presentation['processor'] ?? null,
            'warnings' => $warnings,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
