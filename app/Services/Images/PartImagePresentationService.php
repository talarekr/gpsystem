<?php

namespace App\Services\Images;

use App\Models\PartImage;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PartImagePresentationService
{
    private const PRESENTATION_VERSION = '2026-06-auto-crop-v2';
    private const LISTING_CANVAS = ['width' => 522, 'height' => 336];
    private const PRODUCT_CANVAS = ['width' => 900, 'height' => 675];
    private const LISTING_TARGET_FILL = 1.0;
    private const PRODUCT_TARGET_FILL = 0.94;
    private const LISTING_MAX_OVERFLOW = 0.05;
    private const PRODUCT_MAX_OVERFLOW = 0.0;
    private const LISTING_MARGIN_RATIO = 0.006;
    private const PRODUCT_MARGIN_RATIO = 0.026;
    private const LISTING_MAX_MARGIN = 5;
    private const PRODUCT_MAX_MARGIN = 24;

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

            $candidates = $this->detectObjectCandidates($source);

            if ($candidates === []) {
                $warnings[] = 'Object bounding box was not detected; using contain fallback on the full image.';
                $candidates[] = $this->buildCandidate('fallback_full_image', ['x' => 0, 'y' => 0, 'width' => imagesx($source), 'height' => imagesy($source)], imagesx($source), imagesy($source));
            }

            $listingCandidate = $this->selectListingCandidate($candidates);
            $productCandidate = $this->selectProductCandidate($candidates);

            $baseName = $this->variantBaseName($partImage, $sourcePath, $force);
            $listingPath = 'parts/photos/presentation/listing/'.$baseName.'.jpg';
            $productPath = 'parts/photos/presentation/product/'.$baseName.'.jpg';

            $originalMetrics = ['width' => imagesx($source), 'height' => imagesy($source)];
            $listingMetrics = $this->renderVariant($source, $listingCandidate, self::LISTING_CANVAS['width'], self::LISTING_CANVAS['height'], $listingPath, self::LISTING_TARGET_FILL, self::LISTING_MARGIN_RATIO, self::LISTING_MAX_MARGIN, self::LISTING_MAX_OVERFLOW);
            $productMetrics = $this->renderVariant($source, $productCandidate, self::PRODUCT_CANVAS['width'], self::PRODUCT_CANVAS['height'], $productPath, self::PRODUCT_TARGET_FILL, self::PRODUCT_MARGIN_RATIO, self::PRODUCT_MAX_MARGIN, self::PRODUCT_MAX_OVERFLOW);

            foreach ($listingMetrics['warnings'] as $warning) {
                $warnings[] = 'Listing image: '.$warning;
            }

            foreach ($productMetrics['warnings'] as $warning) {
                $warnings[] = 'Product image: '.$warning;
            }

            imagedestroy($source);

            $listingFillRatio = $listingMetrics['fill_ratio'];

            $presentation = [
                'listing_path' => $listingPath,
                'product_path' => $productPath,
                'listing_fill_width_ratio' => $listingFillRatio['width_ratio'],
                'listing_fill_height_ratio' => $listingFillRatio['height_ratio'],
                'listing_dominant_ratio' => $listingFillRatio['dominant_ratio'],
                'object_aspect_ratio' => $listingCandidate['object_aspect_ratio'],
                'selected_crop_pass' => $listingCandidate['pass'],
                'source_path' => $sourcePath,
                'processed_at' => now()->toISOString(),
                'processor' => 'gd',
                'bounding_box' => $productCandidate['box'],
                'crop_candidates' => $candidates,
                'selected_crops' => [
                    'listing' => $listingCandidate,
                    'product' => $productCandidate,
                ],
                'metrics' => [
                    'original' => $originalMetrics,
                    'listing' => $listingMetrics,
                    'product' => $productMetrics,
                ],
                'presentation_version' => self::PRESENTATION_VERSION,
                'forced' => $force,
            ];

            $presentation['listing_score'] = PartImage::calculateProposedListingScore($presentation);

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
        $candidates = [
            Storage::disk('public')->path($relative),
            public_path('storage/'.$relative),
            dirname(base_path()).'/public_html/storage/'.$relative,
        ];

        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;

        if (is_string($documentRoot) && trim($documentRoot) !== '') {
            $candidates[] = rtrim($documentRoot, '/').'/storage/'.$relative;
        }

        foreach ($candidates as $candidate) {
            if (is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
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

    private function detectObjectCandidates(mixed $image): array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $passes = [
            'normal' => ['level' => 0],
            'aggressive' => ['level' => 1],
            'very_aggressive' => ['level' => 2],
        ];
        $candidates = [];
        $seen = [];

        foreach ($passes as $name => $options) {
            $box = $this->scanObjectBox($image, $options['level']);

            if (! $box) {
                continue;
            }

            $candidate = $this->buildCandidate($name, $box, $width, $height);
            $key = implode(':', [$box['x'], $box['y'], $box['width'], $box['height']]);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $candidates[] = $candidate;
        }

        return $candidates;
    }

    private function buildCandidate(string $pass, array $box, int $imageWidth, int $imageHeight): array
    {
        $areaRatio = ($box['width'] * $box['height']) / max(1, $imageWidth * $imageHeight);
        $aspectRatio = $box['width'] / max(1, $box['height']);
        $listingFill = $this->predictFill($box, self::LISTING_CANVAS['width'], self::LISTING_CANVAS['height'], self::LISTING_TARGET_FILL, self::LISTING_MAX_OVERFLOW);
        $productFill = $this->predictFill($box, self::PRODUCT_CANVAS['width'], self::PRODUCT_CANVAS['height'], self::PRODUCT_TARGET_FILL, self::PRODUCT_MAX_OVERFLOW);
        $suspiciouslySmall = $areaRatio < 0.015 || $box['width'] < 24 || $box['height'] < 24;
        $cutsTooMuch = ($pass === 'very_aggressive' && $areaRatio < 0.045) || ($pass !== 'normal' && $areaRatio < 0.35 && ($box['x'] <= 1 || $box['y'] <= 1 || $box['x'] + $box['width'] >= $imageWidth - 1 || $box['y'] + $box['height'] >= $imageHeight - 1));

        return [
            'pass' => $pass,
            'box' => $box + ['area_ratio' => round($areaRatio, 4)],
            'crop_area_ratio' => round($areaRatio, 4),
            'object_aspect_ratio' => round($aspectRatio, 4),
            'predicted_listing_fill_ratio' => $listingFill,
            'predicted_product_fill_ratio' => $productFill,
            'suspiciously_small' => $suspiciouslySmall,
            'cuts_too_much' => $cutsTooMuch,
        ];
    }

    private function predictFill(array $box, int $canvasWidth, int $canvasHeight, float $targetFill, float $maxOverflow): array
    {
        $dominant = $box['width'] >= $box['height'] ? 'width' : 'height';
        $limit = 1 + $maxOverflow;
        $dominantScale = ($dominant === 'width' ? $canvasWidth : $canvasHeight) * $targetFill / max(1, $dominant === 'width' ? $box['width'] : $box['height']);
        $containScale = min(($canvasWidth * $limit) / max(1, $box['width']), ($canvasHeight * $limit) / max(1, $box['height']));
        $scale = min($dominantScale, $containScale);
        $widthRatio = ($box['width'] * $scale) / max(1, $canvasWidth);
        $heightRatio = ($box['height'] * $scale) / max(1, $canvasHeight);

        return [
            'width_ratio' => round($widthRatio, 4),
            'height_ratio' => round($heightRatio, 4),
            'dominant_ratio' => round(max($widthRatio, $heightRatio), 4),
            'dominant_axis' => $dominant,
            'allowed_overflow' => $maxOverflow > 0,
        ];
    }

    private function selectListingCandidate(array $candidates): array
    {
        $usable = array_values(array_filter($candidates, fn (array $candidate): bool => ! $candidate['suspiciously_small'] && ! $candidate['cuts_too_much']));
        $usable = $usable ?: $candidates;
        usort($usable, fn (array $a, array $b): int => [$b['predicted_listing_fill_ratio']['dominant_ratio'], $this->passAggression($b['pass'])] <=> [$a['predicted_listing_fill_ratio']['dominant_ratio'], $this->passAggression($a['pass'])]);

        return $usable[0];
    }

    private function selectProductCandidate(array $candidates): array
    {
        $usable = array_values(array_filter($candidates, fn (array $candidate): bool => ! $candidate['suspiciously_small'] && ! $candidate['cuts_too_much']));
        $usable = $usable ?: $candidates;
        usort($usable, fn (array $a, array $b): int => [$this->passSafety($b['pass']), $b['predicted_product_fill_ratio']['dominant_ratio']] <=> [$this->passSafety($a['pass']), $a['predicted_product_fill_ratio']['dominant_ratio']]);

        return $usable[0];
    }

    private function passAggression(string $pass): int
    {
        return ['normal' => 0, 'aggressive' => 1, 'very_aggressive' => 2][$pass] ?? 0;
    }

    private function passSafety(string $pass): int
    {
        return ['normal' => 3, 'aggressive' => 2, 'very_aggressive' => 1][$pass] ?? 0;
    }

    private function scanObjectBox(mixed $image, int $aggression): ?array
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

                if ($this->isBackgroundPixel($rgba, $aggression)) {
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

    private function isBackgroundPixel(int $rgba, int $aggression): bool
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

        if ($brightness > 225 && $saturation < 0.055) {
            return true;
        }

        if ($aggression >= 1 && $brightness > 218 && $saturation < 0.08 && $chroma < 24) {
            return true;
        }

        return $aggression >= 2 && $brightness > 210 && $saturation < 0.105 && $chroma < 30;
    }

    private function renderVariant(mixed $source, array $candidate, int $canvasWidth, int $canvasHeight, string $targetPath, float $targetFill, float $marginRatio, int $maxMargin, float $maxOverflow): array
    {
        $box = $candidate['box'];
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
        $dominant = $cropWidth >= $cropHeight ? 'width' : 'height';

        $canvas = imagecreatetruecolor($canvasWidth, $canvasHeight);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);

        $limit = 1 + $maxOverflow;
        $dominantScale = ($dominant === 'width' ? $canvasWidth : $canvasHeight) * $targetFill / max(1, $dominant === 'width' ? $cropWidth : $cropHeight);
        $containScale = min(($canvasWidth * $limit) / $cropWidth, ($canvasHeight * $limit) / $cropHeight);
        $scale = min($dominantScale, $containScale);
        $targetWidth = max(1, (int) round($cropWidth * $scale));
        $targetHeight = max(1, (int) round($cropHeight * $scale));
        $dstX = (int) floor(($canvasWidth - $targetWidth) / 2);
        $dstY = (int) floor(($canvasHeight - $targetHeight) / 2);

        imagecopyresampled($canvas, $source, $dstX, $dstY, $cropX, $cropY, $targetWidth, $targetHeight, $cropWidth, $cropHeight);
        Storage::disk('public')->makeDirectory(dirname($targetPath));
        imagejpeg($canvas, Storage::disk('public')->path($targetPath), 88);
        imagedestroy($canvas);

        $widthRatio = $targetWidth / $canvasWidth;
        $heightRatio = $targetHeight / $canvasHeight;
        $dominantRatio = max($widthRatio, $heightRatio);
        $variantWarnings = [];

        if ($dominantRatio < 0.85) {
            $variantWarnings[] = 'dominant fill ratio below 0.85';
        }

        return [
            'canvas' => ['width' => $canvasWidth, 'height' => $canvasHeight],
            'candidate_pass' => $candidate['pass'],
            'crop_box' => ['x' => $cropX, 'y' => $cropY, 'width' => $cropWidth, 'height' => $cropHeight],
            'bounding_box' => ['width' => $box['width'], 'height' => $box['height'], 'area_ratio' => $box['area_ratio'] ?? null],
            'margin_px' => ['x' => $marginX, 'y' => $marginY],
            'final_scale' => round($scale, 4),
            'final_object' => ['width' => $targetWidth, 'height' => $targetHeight],
            'fill_ratio' => [
                'width_ratio' => round($widthRatio, 4),
                'height_ratio' => round($heightRatio, 4),
                'dominant_ratio' => round($dominantRatio, 4),
                'dominant_axis' => $dominant,
                'aggressive_crop' => $candidate['pass'] !== 'normal',
                'allowed_overflow' => $maxOverflow > 0,
            ],
            'final_fill_ratio' => round($dominantRatio, 4),
            'warnings' => $variantWarnings,
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
            'original' => $presentation['metrics']['original'] ?? null,
            'crop_candidates' => $presentation['crop_candidates'] ?? null,
            'selected_crops' => $presentation['selected_crops'] ?? null,
            'bounding_box' => $presentation['bounding_box'] ?? null,
            'metrics' => $presentation['metrics'] ?? null,
            'presentation_version' => $presentation['presentation_version'] ?? null,
            'forced' => $presentation['forced'] ?? null,
            'processor' => $presentation['processor'] ?? null,
            'warnings' => $warnings,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
