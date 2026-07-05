<?php

namespace App\Services\JarekGearboxes;

use App\Models\JarekGearbox;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use App\Support\Marketplace\AllegroUserAgent;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AllegroJarekImportService
{
    public const REQUIRED_ENV = [
        'client_id' => 'ALLEGRO_JAREK_CLIENT_ID',
        'client_secret' => 'ALLEGRO_JAREK_CLIENT_SECRET',
        'access_token' => 'ALLEGRO_JAREK_ACCESS_TOKEN',
    ];

    /** @var array<string, array<string, mixed>> */
    private array $categoryCache = [];

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
        $result = $this->fetchDetailedOffers($limit, $offset);
        $mapped = collect($result['offers'])->map(fn (array $offer): array => $this->mapOffer($offer))->values();
        $categoryRows = $mapped->pluck('category_payload')->filter()->unique('id')->values();

        return [
            'marketplace_write' => false,
            'database_write' => false,
            'requested_limit' => $result['pagination']['requested_limit'],
            'offset' => $result['pagination']['offset'],
            'effective_limit' => $result['pagination']['effective_limit'],
            'page_size' => $result['pagination']['page_size'],
            'pages_fetched' => $result['pagination']['pages_fetched'],
            'found' => $mapped->count(),
            'reached_requested_limit' => $result['pagination']['reached_requested_limit'],
            'has_more_after_limit' => $result['pagination']['has_more_after_limit'],
            'would_create' => $mapped->where(fn ($row) => ! JarekGearbox::where('allegro_offer_id', $row['allegro_offer_id'])->exists())->count(),
            'would_update' => $mapped->where(fn ($row) => JarekGearbox::where('allegro_offer_id', $row['allegro_offer_id'])->exists())->count(),
            'created' => 0,
            'updated' => 0,
            'categories_count' => $categoryRows->count(),
            'categories_sample' => $categoryRows->take(10)->map(fn ($category) => $this->categorySummary((array) $category))->all(),
            'missing_category_name_count' => $mapped->where(fn ($row) => blank($row['category_name']))->count(),
            'missing_category_id_count' => $mapped->where(fn ($row) => blank($row['category_id']))->count(),
            'missing_description_count' => $mapped->where(fn ($row) => blank($row['description']) && blank($row['plain_description']))->count(),
            'missing_parameters_count' => $mapped->where(fn ($row) => count($row['parameters'] ?? []) === 0)->count(),
            'missing_images_count' => $mapped->where(fn ($row) => count($row['images'] ?? []) === 0)->count(),
            'single_image_only_count' => $mapped->where(fn ($row) => count($row['images'] ?? []) === 1)->count(),
            'sample' => $mapped->take(5)->map(fn (array $row): array => $this->sampleRow($row))->all(),
        ];
    }

    public function apply(int $limit = 20, int $offset = 0): array
    {
        $created = 0;
        $updated = 0;

        $result = $this->fetchDetailedOffers($limit, $offset, 200);

        foreach ($result['offers'] as $offer) {
            $data = $this->mapOffer($offer);
            $existing = JarekGearbox::where('allegro_offer_id', $data['allegro_offer_id'])->first();
            $existing ? $existing->fill($data)->save() : JarekGearbox::create($data);
            $existing ? $updated++ : $created++;
        }

        return compact('created', 'updated') + $result['pagination'] + [
            'found' => count($result['offers']),
            'would_create' => 0,
            'would_update' => 0,
            'marketplace_write' => false,
            'database_write' => true,
            'deleted' => 0,
        ];
    }

    public function status(): array
    {
        return [
            'marketplace_write' => false,
            'total_rows' => JarekGearbox::query()->count(),
            'distinct_allegro_offer_id_count' => JarekGearbox::query()->distinct('allegro_offer_id')->count('allegro_offer_id'),
            'latest_imported_at' => JarekGearbox::query()->max('imported_at'),
            'latest_updated_from_allegro_at' => JarekGearbox::query()->max('updated_from_allegro_at'),
            'counts_by_allegro_status' => $this->countsBy('allegro_status'),
            'counts_by_category' => JarekGearbox::query()
                ->select('category_id', 'category_name', DB::raw('count(*) as total'))
                ->groupBy('category_id', 'category_name')
                ->orderByDesc('total')
                ->limit(100)
                ->get()
                ->map(fn (JarekGearbox $row): array => [
                    'category_id' => $row->category_id,
                    'category_name' => $row->category_name,
                    'total' => (int) $row->total,
                ])
                ->values()
                ->all(),
        ];
    }

    private function countsBy(string $column): array
    {
        return JarekGearbox::query()
            ->select($column, DB::raw('count(*) as total'))
            ->groupBy($column)
            ->orderByDesc('total')
            ->pluck('total', $column)
            ->map(fn ($total): int => (int) $total)
            ->all();
    }

    private function fetchDetailedOffers(int $limit, int $offset, ?int $maxLimit = null): array
    {
        $result = $this->fetchOffers($limit, $offset, $maxLimit);
        $result['offers'] = collect($result['offers'])
            ->map(fn (array $offer): array => array_replace_recursive($offer, $this->fetchOfferDetails((string) Arr::get($offer, 'id'))))
            ->all();

        return $result;
    }

    /**
     * @return array{offers: array<int, array<string, mixed>>, pagination: array<string, int|bool>}
     */
    private function fetchOffers(int $limit, int $offset, ?int $maxLimit = null): array
    {
        $requestedLimit = max(1, $limit);
        $effectiveRequestedLimit = $maxLimit === null ? $requestedLimit : min($requestedLimit, max(1, $maxLimit));
        $pageSize = 100;
        $currentOffset = max(0, $offset);
        $offers = [];
        $pagesFetched = 0;
        $lastPageCount = 0;

        while (count($offers) < $effectiveRequestedLimit) {
            $remaining = $effectiveRequestedLimit - count($offers);
            $currentPageSize = min($pageSize, $remaining);
            $response = $this->allegro()->get($this->baseUrl().'/sale/offers', [
                'limit' => $currentPageSize,
                'offset' => $currentOffset,
            ]);

            if (! $response->successful()) {
                throw new RuntimeException('Allegro Jarek read-only import failed: HTTP '.$response->status());
            }

            $pageOffers = $response->json('offers', []);
            $pageOffers = is_array($pageOffers) ? array_values($pageOffers) : [];
            $lastPageCount = count($pageOffers);
            $pagesFetched++;

            foreach ($pageOffers as $offer) {
                if (is_array($offer)) {
                    $offers[] = $offer;
                }
            }

            if ($lastPageCount < $currentPageSize) {
                break;
            }

            $currentOffset += $currentPageSize;
        }

        $found = count($offers);
        $reachedRequestedLimit = $found >= $requestedLimit;
        $reachedEffectiveLimit = $found >= $effectiveRequestedLimit;

        return [
            'offers' => array_slice($offers, 0, $effectiveRequestedLimit),
            'pagination' => [
                'requested_limit' => $requestedLimit,
                'offset' => max(0, $offset),
                'effective_limit' => min($effectiveRequestedLimit, $found),
                'page_size' => $pageSize,
                'pages_fetched' => $pagesFetched,
                'reached_requested_limit' => $reachedRequestedLimit,
                'has_more_after_limit' => $reachedEffectiveLimit && $lastPageCount === min($pageSize, $effectiveRequestedLimit - (($pagesFetched - 1) * $pageSize)),
            ],
        ];
    }

    private function fetchOfferDetails(string $offerId): array
    {
        if (blank($offerId)) {
            return [];
        }

        foreach (['/sale/product-offers/'.$offerId, '/sale/offers/'.$offerId] as $path) {
            $response = $this->allegro()->get($this->baseUrl().$path);
            if ($response->successful()) {
                return $response->json() ?? [];
            }
        }

        return [];
    }

    private function mapOffer(array $offer): array
    {
        $images = $this->mapImages($offer);
        $price = Arr::get($offer, 'sellingMode.price.amount') ?? Arr::get($offer, 'sellingMode.minimalPrice.amount');
        $description = $this->mapDescription($offer);
        $category = $this->mapCategory(Arr::get($offer, 'category', []));

        return [
            'source_account' => 'jarek',
            'allegro_account' => 'jarek',
            'allegro_offer_id' => (string) Arr::get($offer, 'id'),
            'allegro_offer_url' => filled(Arr::get($offer, 'id')) ? 'https://allegro.pl/oferta/'.Arr::get($offer, 'id') : null,
            'title' => (string) Arr::get($offer, 'name', 'Oferta Allegro Jarka'),
            'description' => $description['structured'],
            'plain_description' => $description['plain'],
            'price' => $price !== null ? (float) $price : null,
            'currency' => (string) (Arr::get($offer, 'sellingMode.price.currency') ?? 'PLN'),
            'quantity' => (int) (Arr::get($offer, 'stock.available') ?? 0),
            'allegro_status' => Arr::get($offer, 'publication.status'),
            'main_image_url' => $images[0] ?? null,
            'images' => $images,
            'category_id' => $category['id'],
            'category_name' => $category['name'],
            'category_path' => $category['path'],
            'category_payload' => $category['payload'],
            'parameters' => $this->mapParameters(Arr::get($offer, 'parameters', [])),
            'raw_payload' => $offer,
            'import_status' => 'imported',
            'imported_at' => now(),
            'updated_from_allegro_at' => now(),
        ];
    }

    private function mapDescription(array $offer): array
    {
        $raw = Arr::get($offer, 'description');
        $structured = is_array($raw) ? json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (is_string($raw) ? $raw : null);
        $plainParts = [];
        foreach (Arr::get($offer, 'description.sections', []) as $section) {
            foreach (Arr::get($section, 'items', []) as $item) {
                $plainParts[] = strip_tags((string) Arr::get($item, 'content', ''));
            }
        }
        $plain = trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($plainParts))) ?? '');

        return ['structured' => $structured, 'plain' => $plain !== '' ? $plain : strip_tags((string) $structured)];
    }

    private function mapParameters(mixed $parameters): array
    {
        return collect(is_array($parameters) ? $parameters : [])->map(fn ($parameter): array => Arr::only((array) $parameter, ['id', 'name', 'values', 'valuesIds', 'unit', 'rangeValue', 'valuesLabels']))->values()->all();
    }

    private function mapCategory(mixed $category): array
    {
        $categoryId = (string) Arr::get((array) $category, 'id', '');
        $payload = $categoryId !== '' ? $this->fetchCategory($categoryId) : [];
        $name = Arr::get($category, 'name') ?: Arr::get($payload, 'name');
        $path = Arr::get($payload, 'path', []);

        return ['id' => $categoryId ?: null, 'name' => $name, 'path' => $path, 'payload' => $payload ?: (array) $category];
    }

    private function fetchCategory(string $categoryId): array
    {
        if (isset($this->categoryCache[$categoryId])) {
            return $this->categoryCache[$categoryId];
        }

        $response = $this->allegro()->get($this->baseUrl().'/sale/categories/'.$categoryId);
        if (! $response->successful()) {
            return $this->categoryCache[$categoryId] = ['id' => $categoryId];
        }

        $category = $response->json() ?? ['id' => $categoryId];
        $parentId = Arr::get($category, 'parent.id');
        $parentPath = filled($parentId) ? Arr::get($this->fetchCategory((string) $parentId), 'path', []) : [];
        $category['path'] = array_values(array_merge($parentPath, [[
            'id' => (string) Arr::get($category, 'id', $categoryId),
            'name' => Arr::get($category, 'name'),
        ]]));

        return $this->categoryCache[$categoryId] = $category;
    }

    private function categorySummary(array $category): array
    {
        return ['id' => Arr::get($category, 'id'), 'name' => Arr::get($category, 'name'), 'path' => Arr::get($category, 'path', [])];
    }

    private function sampleRow(array $row): array
    {
        return Arr::only($row, ['allegro_offer_id', 'title', 'price', 'currency', 'quantity', 'allegro_status', 'category_id', 'category_name', 'category_path', 'main_image_url', 'images']) + [
            'has_description' => filled($row['description']) || filled($row['plain_description']),
            'description_length' => mb_strlen((string) ($row['description'] ?? '')),
            'plain_description_length' => mb_strlen((string) ($row['plain_description'] ?? '')),
            'parameters_count' => count($row['parameters'] ?? []),
            'parameters_sample' => array_slice($row['parameters'] ?? [], 0, 5),
            'images_count' => count($row['images'] ?? []),
            'category_payload_summary' => $this->categorySummary((array) ($row['category_payload'] ?? [])),
        ];
    }

    private function mapImages(array $offer): array
    {
        $urls = [];
        foreach ([Arr::get($offer, 'primaryImage.url'), Arr::get($offer, 'images', []), Arr::get($offer, 'gallery', [])] as $source) {
            $urls = array_merge($urls, $this->extractImageUrls($source));
        }

        return collect($urls)->filter(fn ($url): bool => is_string($url) && filled($url))->map(fn (string $url): string => trim($url))->unique()->values()->all();
    }

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

    private function allegro(): PendingRequest
    {
        $status = $this->configStatus();
        if (! $status['present']) {
            throw new RuntimeException('Missing .env/config key for Allegro Jarek: '.$status['missing'][0]);
        }

        return AllegroUserAgent::request()->withToken((string) config('services.allegro_jarek.access_token'))
            ->accept('application/vnd.allegro.public.v1+json')
            ->timeout(20);
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.allegro_jarek.base_url', 'https://api.allegro.pl'), '/');
    }
}
