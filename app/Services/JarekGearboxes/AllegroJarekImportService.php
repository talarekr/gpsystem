<?php

namespace App\Services\JarekGearboxes;

use App\Models\JarekGearbox;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AllegroJarekImportService
{
    public const REQUIRED_ENV = [
        'client_id' => 'ALLEGRO_JAREK_CLIENT_ID',
        'client_secret' => 'ALLEGRO_JAREK_CLIENT_SECRET',
        'access_token' => 'ALLEGRO_JAREK_ACCESS_TOKEN',
    ];

    public function configStatus(): array
    {
        $missing = [];
        foreach (self::REQUIRED_ENV as $key => $env) {
            if (blank(config("services.allegro_jarek.{$key}"))) {
                $missing[] = $env;
            }
        }

        return ['present' => $missing === [], 'missing' => $missing];
    }

    public function dryRun(int $limit = 20, int $offset = 0): array
    {
        $offers = $this->fetchOffers($limit, $offset);
        $mapped = collect($offers)->map(fn (array $offer): array => $this->mapOffer($offer))->values();

        return [
            'marketplace_write' => false,
            'database_write' => false,
            'found' => count($offers),
            'would_create' => $mapped->where(fn ($row) => ! JarekGearbox::where('allegro_offer_id', $row['allegro_offer_id'])->exists())->count(),
            'would_update' => $mapped->where(fn ($row) => JarekGearbox::where('allegro_offer_id', $row['allegro_offer_id'])->exists())->count(),
            'sample' => $mapped->take(5)->all(),
        ];
    }

    public function apply(int $limit = 20, int $offset = 0): array
    {
        $created = 0;
        $updated = 0;

        foreach ($this->fetchOffers($limit, $offset) as $offer) {
            $data = $this->mapOffer($offer);
            $existing = JarekGearbox::where('allegro_offer_id', $data['allegro_offer_id'])->first();
            $existing ? $existing->fill($data)->save() : JarekGearbox::create($data);
            $existing ? $updated++ : $created++;
        }

        return compact('created', 'updated') + ['marketplace_write' => false, 'deleted' => 0];
    }

    private function fetchOffers(int $limit, int $offset): array
    {
        $status = $this->configStatus();
        if (! $status['present']) {
            throw new RuntimeException('Missing .env/config key for Allegro Jarek: '.$status['missing'][0]);
        }

        $baseUrl = rtrim((string) config('services.allegro_jarek.base_url', 'https://api.allegro.pl'), '/');
        $response = Http::withToken((string) config('services.allegro_jarek.access_token'))
            ->accept('application/vnd.allegro.public.v1+json')
            ->timeout(20)
            ->get($baseUrl.'/sale/offers', ['limit' => max(1, min($limit, 100)), 'offset' => max(0, $offset)]);

        if (! $response->successful()) {
            throw new RuntimeException('Allegro Jarek read-only import failed: HTTP '.$response->status());
        }

        return $response->json('offers', []);
    }

    private function mapOffer(array $offer): array
    {
        $images = $this->mapImages($offer);
        $price = Arr::get($offer, 'sellingMode.price.amount') ?? Arr::get($offer, 'sellingMode.minimalPrice.amount');
        $category = Arr::get($offer, 'category', []);

        return [
            'source_account' => 'jarek',
            'allegro_account' => 'jarek',
            'allegro_offer_id' => (string) Arr::get($offer, 'id'),
            'allegro_offer_url' => filled(Arr::get($offer, 'id')) ? 'https://allegro.pl/oferta/'.Arr::get($offer, 'id') : null,
            'title' => (string) Arr::get($offer, 'name', 'Oferta Allegro Jarka'),
            'description' => is_array(Arr::get($offer, 'description')) ? json_encode(Arr::get($offer, 'description'), JSON_UNESCAPED_UNICODE) : Arr::get($offer, 'description'),
            'plain_description' => strip_tags((string) (Arr::get($offer, 'description.sections.0.items.0.content') ?? '')),
            'price' => $price !== null ? (float) $price : null,
            'currency' => (string) (Arr::get($offer, 'sellingMode.price.currency') ?? 'PLN'),
            'quantity' => (int) (Arr::get($offer, 'stock.available') ?? 0),
            'allegro_status' => Arr::get($offer, 'publication.status'),
            'main_image_url' => $images[0] ?? null,
            'images' => $images,
            'category_id' => Arr::get($category, 'id'),
            'category_name' => Arr::get($category, 'name'),
            'parameters' => Arr::get($offer, 'parameters', []),
            'raw_payload' => $offer,
            'import_status' => 'imported',
            'imported_at' => now(),
            'updated_from_allegro_at' => now(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function mapImages(array $offer): array
    {
        $urls = [];

        foreach ([
            Arr::get($offer, 'primaryImage.url'),
            Arr::get($offer, 'images', []),
            Arr::get($offer, 'gallery', []),
        ] as $source) {
            $urls = array_merge($urls, $this->extractImageUrls($source));
        }

        return collect($urls)
            ->filter(fn ($url): bool => is_string($url) && filled($url))
            ->map(fn (string $url): string => trim($url))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function extractImageUrls(mixed $source): array
    {
        if (is_string($source)) {
            return [$source];
        }

        if (! is_array($source)) {
            return [];
        }

        if (isset($source['url']) && is_string($source['url'])) {
            return [$source['url']];
        }

        $urls = [];
        foreach ($source as $item) {
            $urls = array_merge($urls, $this->extractImageUrls($item));
        }

        return $urls;
    }
}
