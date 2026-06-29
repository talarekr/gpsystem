<?php

namespace App\Services\Marketplace;

use App\Models\Part;
use App\Models\PartImage;
use Illuminate\Support\Facades\Http;
use Throwable;

class MarketplaceImageSelectionService
{
    /**
     * @return array{urls: array<int, string>, selected: array<int, array<string, mixed>>, diagnostics: array<string, mixed>}
     */
    public function selectForPart(Part $part, int $limit = 5, bool $withHttpChecks = false): array
    {
        $images = $this->orderedImages($part);
        $selected = [];

        foreach ($images as $image) {
            $choice = $this->selectForImage($image);
            if ($choice === null) {
                continue;
            }

            $selected[] = $choice + [
                'part_image_id' => $image->id,
                'is_primary' => (bool) $image->is_primary,
                'sort_order' => $image->sort_order,
            ];

            if (count($selected) >= $limit) {
                break;
            }
        }

        $urls = array_values(array_map(fn (array $image): string => $image['selected_image_url'], $selected));
        $first = $selected[0] ?? null;
        $main = $images->first();
        $mainPreserved = $first !== null && $main !== null && (int) ($first['part_image_id'] ?? 0) === (int) $main->id;
        $checks = $withHttpChecks ? array_map(fn (string $url): array => $this->checkPublicImageUrl($url), array_slice($urls, 0, 2)) : [];

        return [
            'urls' => $urls,
            'selected' => $selected,
            'diagnostics' => [
                'selected_image_variant' => $first['selected_image_variant'] ?? null,
                'selected_image_source_path' => $first['selected_image_source_path'] ?? null,
                'selected_image_url' => $first['selected_image_url'] ?? null,
                'selected_images_count' => count($urls),
                'main_image_preserved_as_first' => $mainPreserved,
                'fallback_reason' => $first['fallback_reason'] ?? null,
                'selected_images' => $selected,
                'http_checks' => $checks,
            ],
        ];
    }

    private function orderedImages(Part $part): \Illuminate\Support\Collection
    {
        $part->loadMissing('images');

        return $part->images
            ->filter(fn ($image): bool => $image instanceof PartImage)
            ->sortBy([
                ['is_primary', 'desc'],
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->values();
    }

    /** @return array<string, mixed>|null */
    private function selectForImage(PartImage $image): ?array
    {
        foreach ($this->candidates($image) as $candidate) {
            $url = $this->httpsUrl($candidate['url'] ?? null);
            if ($url === null) {
                continue;
            }

            return [
                'selected_image_variant' => $candidate['variant'],
                'selected_image_source_path' => $candidate['source_path'],
                'selected_image_url' => $url,
                'fallback_reason' => $candidate['variant'] === 'imported' ? null : $this->fallbackReason($image, $candidate['variant']),
            ];
        }

        return null;
    }

    /** @return array<int, array{variant: string, source_path: ?string, url: ?string}> */
    private function candidates(PartImage $image): array
    {
        $importedUrl = $image->isImportedPhoto() ? $image->absolutePublicUrl() : null;
        $productPath = data_get($image->legacy_payload, 'presentation.product_path');
        $listingPath = data_get($image->legacy_payload, 'presentation.listing_path');

        return [
            ['variant' => 'imported', 'source_path' => $image->isImportedPhoto() ? $image->path : null, 'url' => $importedUrl],
            ['variant' => 'presentation_product', 'source_path' => is_string($productPath) ? $productPath : null, 'url' => $image->productUrl()],
            ['variant' => 'presentation_listing', 'source_path' => is_string($listingPath) ? $listingPath : null, 'url' => $image->listingPresentationUrl()],
            ['variant' => 'other', 'source_path' => is_string($image->path) ? $image->path : null, 'url' => $image->absolutePublicUrl()],
        ];
    }

    private function httpsUrl(mixed $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $url = trim($url);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);

        return $scheme === 'https' && $host !== '' ? $url : null;
    }

    private function fallbackReason(PartImage $image, string $variant): string
    {
        if (! $image->isImportedPhoto()) {
            return 'source_image_is_not_imported';
        }

        $url = $image->absolutePublicUrl();
        if ($this->httpsUrl($url) === null) {
            return 'imported_url_missing_or_not_https';
        }

        return 'imported_unavailable_for_marketplace';
    }

    /** @return array<string, mixed> */
    public function checkPublicImageUrl(string $url): array
    {
        $base = [
            'url' => $url,
            'status' => null,
            'content_type' => null,
            'content_length' => null,
            'final_host' => parse_url($url, PHP_URL_HOST),
            'redirect_count' => 0,
            'accessible_publicly' => false,
        ];

        try {
            $response = Http::timeout(5)->withOptions(['allow_redirects' => ['track_redirects' => true]])->head($url);
            $contentType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

            if ($response->status() !== 200 || ! str_starts_with($contentType, 'image/')) {
                $response = Http::timeout(6)->withHeaders(['Range' => 'bytes=0-1023'])->withOptions(['allow_redirects' => ['track_redirects' => true]])->get($url);
                $contentType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
            }

            $finalUrl = (string) ($response->handlerStats()['url'] ?? $url);
            $redirectHistory = $response->header('X-Guzzle-Redirect-History');

            return array_merge($base, [
                'status' => $response->status(),
                'content_type' => $contentType ?: null,
                'content_length' => is_numeric($response->header('Content-Length')) ? (int) $response->header('Content-Length') : null,
                'final_host' => parse_url($finalUrl, PHP_URL_HOST) ?: $base['final_host'],
                'redirect_count' => $redirectHistory ? count(array_filter(array_map('trim', explode(',', $redirectHistory)))) : 0,
                'accessible_publicly' => $response->status() === 200 && str_starts_with($contentType, 'image/'),
            ]);
        } catch (Throwable $e) {
            return array_merge($base, ['error' => $e::class]);
        }
    }
}
