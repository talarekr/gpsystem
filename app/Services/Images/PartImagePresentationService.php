<?php

namespace App\Services\Images;

use App\Models\PartImage;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PartImagePresentationService
{
    private const PRESENTATION_VERSION = '2026-06-tight-fill-v1';
    private const LISTING_TARGET_FILL = 0.95;
    private const PRODUCT_TARGET_FILL = 0.94;
    private const LISTING_MARGIN_RATIO = 0.025;
    private const PRODUCT_MARGIN_RATIO = 0.035;
    private const LISTING_MAX_MARGIN = 12;
    private const PRODUCT_MAX_MARGIN = 30;

    public function process(PartImage $partImage, bool $force = false): array
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

            $baseName = $this->variantBaseName($partImage, $sourcePath, $force);
            $listingPath = 'parts/photos/presentation/listing/'.$baseName.'.jpg';
            $productPath = 'parts/photos/presentation/product/'.$baseName.'.jpg';

            $originalMetrics = ['width' => imagesx($source), 'height' => imagesy($source)];
            $listingMetrics = $this->renderVariant($source, $box, 522, 336, $listingPath, self::LISTING_TARGET_FILL, self::LISTING_MARGIN_RATIO, self::LISTING_MAX_MARGIN);
            $productMetrics = $this->renderVariant($source, $box, 900, 675, $productPath, self::PRODUCT_TARGET_FILL, self::PRODUCT_MARGIN_RATIO, self::PRODUCT_MAX_MARGIN);
            imagedestroy($source);

            $presentation = [
                'listing_path' => $listingPath,
                'product_path' => $productPath,
                'source_path' => $sourcePath,
                'processed_at' => now()->toISOString(),
                'processor' => 'gd',
                'bounding_box' => $box,
                'metrics' => [
                    'original' => $originalMetrics,
                    'listing' => $listingMetrics,
                    'product' => $productMetrics,
                ],
                'presentation_version' => self::PRESENTATION_VERSION,
                'forced' => $force,
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
        $width = imagesx($image);
        $height = imagesy($image);

        $box = $this->scanObjectBox($image, false);

        if (! $box) {
            $box = $this->scanObjectBox($image, true);
        }

        if (! $box) {
            return null;
        }

        $areaRatio = ($box['width'] * $box['height']) / max(1, $width * $height);

        if ($areaRatio > 0.82) {
            $aggressiveBox = $this->scanObjectBox($image, true);

            if ($aggressiveBox) {
                $aggressiveAreaRatio = ($aggressiveBox['width'] * $aggressiveBox['height']) / max(1, $width * $height);

                if ($aggressiveAreaRatio < $areaRatio) {
                    $box = $aggressiveBox;
                    $areaRatio = $aggressiveAreaRatio;
                }
            }
        }

        return $box + ['area_ratio' => round($areaRatio, 4)];
    }

    private function scanObjectBox(mixed $image, bool $aggressive): ?array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $minX = $width;
        $minY = $height;
        $maxX = -1;
        $maxY = -1;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgba = imagecolorat($image, $x, $y);

                if ($this->isBackgroundPixel($rgba, $aggressive)) {
                    continue;
                }

                $minX = min($minX, $x);
                $minY = min($minY, $y);
                $maxX = max($maxX, $x);
                $maxY = max($maxY, $y);
            }
        }

        if ($maxX < 0 || $maxY < 0) {
            return null;
        }

        return ['x' => $minX, 'y' => $minY, 'width' => $maxX - $minX + 1, 'height' => $maxY - $minY + 1];
    }

    private function isBackgroundPixel(int $rgba, bool $aggressive): bool
    {
        $a = ($rgba & 0x7F000000) >> 24;

        if ($a >= 127) {
            return true;
        }

        $r = ($rgba >> 16) & 0xFF;
        $g = ($rgba >> 8) & 0xFF;
        $b = $rgba & 0xFF;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $chroma = $max - $min;
        $brightness = ($r + $g + $b) / 3;
        $saturation = $max === 0 ? 0 : $chroma / $max;

        if ($r > 238 && $g > 238 && $b > 238 && $chroma < 18) {
            return true;
        }

        if ($brightness > 242 && $chroma < 22) {
            return true;
        }

        if ($brightness > 228 && $saturation < 0.055) {
            return true;
        }

        return $aggressive && $brightness > 215 && $saturation < 0.08 && $chroma < 24;
    }

    private function renderVariant(mixed $source, array $box, int $canvasWidth, int $canvasHeight, string $targetPath, float $targetFill, float $marginRatio, int $maxMargin): array
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $marginX = min($maxMargin, (int) ceil($box['width'] * $marginRatio));
        $marginY = min($maxMargin, (int) ceil($box['height'] * $marginRatio));
        $cropX = max(0, $box['x'] - $marginX);
        $cropY = max(0, $box['y'] - $marginY);
        $cropRight = min($sourceWidth - 1, $box['x'] + $box['width'] - 1 + $marginX);
        $cropBottom = min($sourceHeight - 1, $box['y'] + $box['height'] - 1 + $marginY);
        $cropWidth = $cropRight - $cropX + 1;
        $cropHeight = $cropBottom - $cropY + 1;

        $canvas = imagecreatetruecolor($canvasWidth, $canvasHeight);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);

        $scale = min(($canvasWidth * $targetFill) / $cropWidth, ($canvasHeight * $targetFill) / $cropHeight);
        $scale = min($scale, $canvasWidth / $cropWidth, $canvasHeight / $cropHeight);
        $targetWidth = max(1, (int) floor($cropWidth * $scale));
        $targetHeight = max(1, (int) floor($cropHeight * $scale));
        $dstX = (int) floor(($canvasWidth - $targetWidth) / 2);
        $dstY = (int) floor(($canvasHeight - $targetHeight) / 2);

        imagecopyresampled($canvas, $source, $dstX, $dstY, $cropX, $cropY, $targetWidth, $targetHeight, $cropWidth, $cropHeight);
        Storage::disk('public')->makeDirectory(dirname($targetPath));
        imagejpeg($canvas, Storage::disk('public')->path($targetPath), 88);
        imagedestroy($canvas);

        return [
            'canvas' => ['width' => $canvasWidth, 'height' => $canvasHeight],
            'crop_box' => ['x' => $cropX, 'y' => $cropY, 'width' => $cropWidth, 'height' => $cropHeight],
            'bounding_box' => ['width' => $box['width'], 'height' => $box['height'], 'area_ratio' => $box['area_ratio'] ?? null],
            'margin_px' => ['x' => $marginX, 'y' => $marginY],
            'final_scale' => round($scale, 4),
            'final_object' => ['width' => $targetWidth, 'height' => $targetHeight],
            'final_fill_ratio' => round(max($targetWidth / $canvasWidth, $targetHeight / $canvasHeight), 4),
        ];
    }

    private function variantBaseName(PartImage $partImage, string $sourcePath, bool $force): string
    {
        $seed = $sourcePath.'|'.$partImage->updated_at?->timestamp.'|'.self::PRESENTATION_VERSION;

        if ($force) {
            $seed .= '|forced|'.now()->format('Uu');
        }

        return $partImage->id.'-'.substr(sha1($seed), 0, 12);
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
            'metrics' => $presentation['metrics'] ?? null,
            'presentation_version' => $presentation['presentation_version'] ?? null,
            'forced' => $presentation['forced'] ?? null,
            'processor' => $presentation['processor'] ?? null,
            'warnings' => $warnings,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
