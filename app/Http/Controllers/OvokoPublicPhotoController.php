<?php

namespace App\Http\Controllers;

use App\Models\PartImage;
use App\Services\Marketplace\MarketplaceImageSelectionService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OvokoPublicPhotoController extends Controller
{
    public function __invoke(PartImage $partImage, string $signature, string $filename, MarketplaceImageSelectionService $selection): BinaryFileResponse
    {
        $sourcePath = $selection->selectedSourcePathForImage($partImage);

        if ($sourcePath === null || ! $this->signatureMatches($partImage, $sourcePath, $signature) || basename($sourcePath) !== $filename) {
            abort(404);
        }

        $relativePath = $this->publicDiskPath($sourcePath);
        if ($relativePath === null || ! Storage::disk('public')->exists($relativePath)) {
            abort(404);
        }

        $absolutePath = Storage::disk('public')->path($relativePath);
        $mimeType = Storage::disk('public')->mimeType($relativePath) ?: 'image/jpeg';
        if (! Str::startsWith(strtolower($mimeType), 'image/')) {
            abort(404);
        }

        return response()->file($absolutePath, [
            'Content-Type' => $mimeType,
            'Content-Length' => (string) Storage::disk('public')->size($relativePath),
            'Cache-Control' => 'public, max-age=86400',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    public static function signatureFor(PartImage $partImage, string $sourcePath): string
    {
        return substr(hash_hmac('sha256', $partImage->id.'|'.ltrim($sourcePath, '/'), (string) config('app.key')), 0, 24);
    }

    private function signatureMatches(PartImage $partImage, string $sourcePath, string $signature): bool
    {
        return hash_equals(self::signatureFor($partImage, $sourcePath), $signature);
    }

    private function publicDiskPath(string $path): ?string
    {
        $path = trim($path);
        if (Str::startsWith($path, ['http://', 'https://'])) {
            $urlPath = parse_url($path, PHP_URL_PATH);
            if (! is_string($urlPath) || ! Str::startsWith($urlPath, '/storage/')) {
                return null;
            }
            $path = substr($urlPath, strlen('/storage/'));
        }

        $path = ltrim($path, '/');
        if (Str::startsWith($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        return Str::startsWith($path, 'parts/photos/') ? $path : null;
    }
}
