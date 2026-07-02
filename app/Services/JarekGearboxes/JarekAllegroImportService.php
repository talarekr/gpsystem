<?php

namespace App\Services\JarekGearboxes;

use App\Models\JarekGearbox;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class JarekAllegroImportService
{
    public const ACCOUNT_CODE = 'allegro_jarek';

    public function dryRun(int $limit = 20, int $page = 1, ?string $status = null): array
    {
        $offers = $this->fetchOffers($limit, $page, $status);
        $ids = collect($offers['offers'])->pluck('allegro_offer_id')->filter()->values()->all();
        $existing = $ids === [] ? collect() : JarekGearbox::query()->whereIn('allegro_offer_id', $ids)->pluck('id', 'allegro_offer_id');

        return [
            'ok' => $offers['blockers'] === [],
            'dry_run' => true,
            'read_only' => true,
            'marketplace_write' => false,
            'account_code' => self::ACCOUNT_CODE,
            'credential_source' => '.env/config services.allegro_jarek',
            'limit' => $limit,
            'page' => $page,
            'found_count' => count($offers['offers']),
            'would_create_count' => collect($ids)->reject(fn ($id) => $existing->has($id))->count(),
            'would_update_count' => collect($ids)->filter(fn ($id) => $existing->has($id))->count(),
            'sample' => array_slice($offers['offers'], 0, 10),
            'blockers' => $offers['blockers'],
            'warnings' => $offers['warnings'],
        ];
    }

    public function apply(int $limit = 20, int $page = 1, ?string $status = null, ?string $confirm = null): array
    {
        if ($confirm !== 'jarek-gearboxes-import') {
            return ['ok' => false, 'applied' => false, 'blockers' => ['Missing confirm=jarek-gearboxes-import.'], 'marketplace_write' => false];
        }

        $offers = $this->fetchOffers($limit, $page, $status);
        if ($offers['blockers'] !== []) {
            return ['ok' => false, 'applied' => false, 'blockers' => $offers['blockers'], 'warnings' => $offers['warnings'], 'marketplace_write' => false];
        }

        $created = $updated = 0;
        foreach ($offers['offers'] as $data) {
            $model = JarekGearbox::query()->firstOrNew(['allegro_offer_id' => $data['allegro_offer_id']]);
            $model->exists ? $updated++ : $created++;
            $model->fill($data + ['source_account' => 'jarek']);
            $model->imported_at ??= now();
            $model->updated_from_allegro_at = now();
            $model->save();
        }

        return ['ok' => true, 'applied' => true, 'created_count' => $created, 'updated_count' => $updated, 'idempotency_key' => 'allegro_offer_id', 'marketplace_write' => false, 'warnings' => $offers['warnings']];
    }

    private function fetchOffers(int $limit, int $page, ?string $status): array
    {
        $limit = max(1, min($limit, 100));
        $config = (array) config('services.allegro_jarek', []);
        $baseUrl = rtrim((string) ($config['base_url'] ?? 'https://api.allegro.pl'), '/');
        $token = $config['access_token'] ?? null;
        $blockers = [];
        foreach (['client_id', 'client_secret', 'access_token', 'refresh_token'] as $key) {
            if (blank($config[$key] ?? null)) {
                $blockers[] = 'Missing .env/config key for Allegro Jarek: ALLEGRO_JAREK_'.strtoupper($key).'.';
            }
        }
        if (blank($baseUrl)) $blockers[] = 'Missing .env/config key for Allegro Jarek: ALLEGRO_JAREK_BASE_URL.';
        if ($blockers !== []) return ['offers' => [], 'blockers' => $blockers, 'warnings' => ['Primary credential source is .env via config/services.php; run php artisan config:clear after changing .env.']];

        $query = ['limit' => $limit, 'offset' => max(0, ($page - 1) * $limit)];
        if (filled($status)) $query['publication.status'] = $status;
        $response = Http::withToken((string) $token)->accept('application/vnd.allegro.public.v1+json')->timeout(20)->get($baseUrl.'/sale/offers', $query);
        if (! $response->successful()) return ['offers' => [], 'blockers' => ['Allegro Jarek read failed with HTTP '.$response->status().'.'], 'warnings' => []];

        $rows = $response->json('offers', []);
        return ['offers' => collect($rows)->map(fn (array $row) => $this->mapOffer($row))->filter(fn ($row) => filled($row['allegro_offer_id']))->values()->all(), 'blockers' => [], 'warnings' => ['Primary credential source is .env via config/services.php.', 'Read-only import: GET /sale/offers only; no Allegro writes.']];
    }

    private function mapOffer(array $row): array
    {
        $images = collect(Arr::wrap($row['images'] ?? []))->map(fn ($img) => is_array($img) ? ($img['url'] ?? null) : $img)->filter()->values()->all();
        $id = (string) ($row['id'] ?? '');
        return [
            'source_account' => 'jarek', 'allegro_offer_id' => $id, 'allegro_offer_url' => $id ? 'https://allegro.pl/oferta/'.$id : null,
            'title' => (string) ($row['name'] ?? ''), 'description' => null, 'plain_description' => null,
            'price' => data_get($row, 'sellingMode.price.amount'), 'currency' => data_get($row, 'sellingMode.price.currency', 'PLN'),
            'quantity' => (int) data_get($row, 'stock.available', 0), 'allegro_status' => data_get($row, 'publication.status'),
            'main_image_url' => $images[0] ?? null, 'images' => $images,
            'category_id' => data_get($row, 'category.id'), 'category_name' => null, 'parameters' => $row['parameters'] ?? [],
            'raw_payload' => $row, 'import_status' => 'imported',
        ];
    }
}
