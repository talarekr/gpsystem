<?php

namespace App\Services\Images;

use App\Models\JarekFrProduct;
use App\Models\PartImage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Local derivatives only, using the ordinary product processor. Never saves a model. */
class JarekImagePresentation
{
    public function __construct(private PartImagePresentationService $processor) {}

    public function variants(string $url): array
    {
        $source = $this->source($url);
        if (! $source) {
            return ['listing' => $url, 'product' => $url, 'ready' => false, 'listing_score' => null];
        }
        $manifest = $this->manifest($source);

        return ['listing' => $manifest ? Storage::disk('public')->url($manifest['listing_path']) : $url,
            'product' => $manifest ? Storage::disk('public')->url($manifest['product_path']) : $url, 'ready' => $manifest !== null, 'listing_score' => is_numeric($manifest['listing_score'] ?? null) ? (float) $manifest['listing_score'] : null];
    }

    /** At most two images and ten exposures per browser request; cursor is resumable. */
    public function batch(int $afterId = 0, int $afterImage = 0, bool $apply = false): array
    {
        $exposures = JarekFrProduct::query()->active()->with('gearbox')
            ->where('jarek_gearbox_id', '>=', $afterId)->orderBy('jarek_gearbox_id')->limit(10)->get();
        $results = [];
        $cursor = ['after_id' => $afterId, 'after_image' => $afterImage];
        foreach ($exposures as $exposure) {
            $source = $exposure->gearbox;
            $urls = $source->localizedImageUrls();
            foreach ($urls as $url) {
                $index = (int) basename(parse_url($url, PHP_URL_PATH), '.jpg');
                if ((int) $source->id === $afterId && $index <= $afterImage) {
                    continue;
                }
                $local = $this->source($url);
                $result = $local ? $this->prepare($local, $apply) : ['status' => 'missing_local_source'];
                $results[] = ['source_id' => $source->id, 'image_index' => $index] + $result;
                $cursor = ['after_id' => (int) $source->id, 'after_image' => $index];
                if (count($results) >= 2) {
                    return ['results' => $results, 'cursor' => $cursor, 'done' => false];
                }
            }
            if ($urls === []) {
                $results[] = ['source_id' => $source->id, 'status' => 'no_local_images'];
            }
            $cursor = ['after_id' => (int) $source->id, 'after_image' => 60];
        }

        return ['results' => $results, 'cursor' => $cursor, 'done' => $exposures->count() < 10];
    }

    private function prepare(array $source, bool $apply): array
    {
        if ($this->manifest($source)) {
            return ['status' => 'already_ready'];
        }
        if (! $apply) {
            return ['status' => 'would_prepare'];
        }
        if (! extension_loaded('gd')) {
            return ['status' => 'error', 'code' => 'gd_unavailable'];
        }
        $dimensions = @getimagesize($source['absolute']);
        if (! $dimensions || $dimensions[0] * $dimensions[1] > 16000000) {
            return ['status' => 'error', 'code' => 'unreadable_or_too_large'];
        }

        return Cache::lock('jarek-local-image:'.hash('sha256', $source['relative']), 120)->block(3, function () use ($source): array {
            if ($this->manifest($source)) {
                return ['status' => 'already_ready'];
            }
            // An unsaved image descriptor invokes exactly the same crop/canvas algorithm as PartImage.
            $image = new PartImage;
            $image->setKeyType('string');
            $image->forceFill(['id' => 'jarek-'.substr(hash('sha256', $source['relative'].'|'.$source['fingerprint']), 0, 16),
                'path' => $source['relative'], 'updated_at' => Carbon::createFromTimestamp($source['mtime']), 'legacy_payload' => []]);
            $payload = $this->processor->process($image);
            $presentation = $payload['presentation'] ?? [];
            if (! $this->validPaths($presentation)) {
                return ['status' => 'error', 'code' => 'processor_failed'];
            }
            if (collect($presentation['warnings'] ?? [])->contains(fn ($warning) => str_contains((string) $warning, 'could not be mirrored'))) {
                return ['status' => 'error', 'code' => 'public_mirror_unavailable'];
            }
            if ($this->fingerprint($source['absolute']) !== $source['fingerprint']) {
                return ['status' => 'error', 'code' => 'source_changed'];
            }
            Storage::disk('public')->put($this->manifestPath($source['relative']), json_encode([
                'fingerprint' => $source['fingerprint'], 'version' => PartImagePresentationService::PRESENTATION_VERSION,
                'listing_path' => $presentation['listing_path'], 'product_path' => $presentation['product_path'],
                'metrics' => $presentation['metrics'] ?? [], 'listing_score' => $presentation['listing_score'] ?? null,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return ['status' => 'prepared'];
        });
    }

    private function source(string $url): ?array
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || ! preg_match('#^/storage/(jarek-gearboxes/[A-Za-z0-9_-]+/(?:0[1-9]|[1-5][0-9]|60)\.jpg)$#D', $path, $match)) {
            return null;
        }
        $absolute = $this->processor->absoluteSourcePath($match[1]);
        if (! $absolute) {
            return null;
        }

        return ['relative' => $match[1], 'absolute' => $absolute, 'mtime' => filemtime($absolute), 'fingerprint' => $this->fingerprint($absolute)];
    }

    private function fingerprint(string $path): string
    {
        clearstatcache(true, $path);

        return filemtime($path).':'.filesize($path);
    }

    private function manifestPath(string $relative): string
    {
        return 'jarek-gearboxes/presentation/'.hash('sha256', $relative).'.json';
    }

    private function manifest(array $source): ?array
    {
        try {
            $disk = Storage::disk('public');
            $path = $this->manifestPath($source['relative']);
            $manifest = $disk->exists($path) ? json_decode($disk->get($path), true) : null;
            if (! is_array($manifest) || ($manifest['version'] ?? null) !== PartImagePresentationService::PRESENTATION_VERSION
                || ($manifest['fingerprint'] ?? null) !== $source['fingerprint'] || ! $this->validPaths($manifest)) {
                return null;
            }

            return $manifest;
        } catch (Throwable) {
            return null;
        }
    }

    private function validPaths(array $manifest): bool
    {
        foreach (['listing', 'product'] as $kind) {
            $path = $manifest[$kind.'_path'] ?? null;
            if (! is_string($path) || ! preg_match('#^parts/photos/presentation/'.$kind.'/jarek-[a-f0-9]{16}-[a-f0-9]{12}\.jpg$#D', $path)
                || ! $this->processor->absoluteSourcePath($path)) {
                return false;
            }
        }

        return true;
    }
}
