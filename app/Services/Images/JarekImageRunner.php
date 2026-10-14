<?php

namespace App\Services\Images;

use App\Models\JarekFrProduct;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Durable technical checkpoints only. No supplier, Part, PartImage, price or stock saves. */
class JarekImageRunner
{
    public const CONFIRM = 'PREPARE-JAREK-FR-LOCAL-IMAGES';

    public const RUNS = 'storefront_jarek_image_runs';

    public const ITEMS = 'storefront_jarek_image_items';

    private const PREVIEW_BATCH = 10;

    private const PREPARE_BATCH = 2;

    private const LEASE_SECONDS = 120;

    public function __construct(private JarekImagePresentation $images) {}

    public function ready(): bool
    {
        return Schema::hasTable(self::RUNS) && Schema::hasTable(self::ITEMS) && Schema::hasTable('jarek_fr_products');
    }

    public function latest(int $actor): ?array
    {
        if (! $this->ready()) {
            return null;
        }
        $row = DB::table(self::RUNS)->where('actor_id', $actor)->orderByDesc('id')->first();

        return $row ? $this->snapshot($this->decode($row)) : null;
    }

    public function preview(int $actor): array
    {
        $this->requireSchema();

        return Cache::lock('jarek-image-controls:'.$actor, 10)->block(3, function () use ($actor): array {
            $latest = $this->latest($actor);
            if ($latest && in_array($latest['status'], ['previewing', 'running', 'paused'], true)) {
                throw ValidationException::withMessages(['run_id' => 'Istnieje aktywny przebieg. Użyj Wznów lub Stop, zamiast tworzyć nowy podgląd.']);
            }
            $ids = JarekFrProduct::query()->active()->orderBy('jarek_gearbox_id')->pluck('jarek_gearbox_id')->map(fn ($id) => (int) $id)->all();
            $run = ['run_id' => (string) Str::uuid(), 'actor_id' => $actor, 'status' => 'previewing', 'phase' => 'preview',
                'candidate_ids' => $ids, 'preview_offset' => 0, 'total_active_exposures' => count($ids), 'previewed_exposures' => 0,
                'preview' => ['total_images' => 0, 'ready_count' => 0, 'missing_count' => 0, 'failed_count' => 0, 'skipped_count' => 0],
                'progress' => ['total' => 0, 'processed' => 0, 'generated' => 0, 'skipped' => 0, 'failed' => 0, 'remaining' => 0],
                'cursor' => ['source_id' => null, 'image_number' => null], 'current_product' => null,
                'last_success' => null, 'last_error' => null, 'lease_token' => null, 'lease_until' => 0, '_revision' => 0];
            DB::transaction(function () use (&$run): void {
                DB::table(self::RUNS)->insert(['run_id' => $run['run_id'], 'actor_id' => $run['actor_id'], 'status' => $run['status'],
                    'phase' => $run['phase'], 'checkpoint' => json_encode($run, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
                if ($run['candidate_ids'] === []) {
                    $run['status'] = 'ready';
                    $this->save($run);
                }
            });

            return $this->snapshot($run);
        });
    }

    public function start(string $id, int $actor, string $confirm): array
    {
        $this->requireSchema();
        if ($confirm !== self::CONFIRM) {
            throw ValidationException::withMessages(['confirm' => 'Wpisz dokładny token przygotowania lokalnych zdjęć.']);
        }

        return DB::transaction(function () use ($id, $actor): array {
            $run = $this->owned($id, $actor, true);
            if ($run['phase'] === 'prepare' && in_array($run['status'], ['running', 'completed'], true)) {
                return $this->snapshot($run);
            }
            if ($run['status'] !== 'ready' || $this->latest($actor)['run_id'] !== $id) {
                throw ValidationException::withMessages(['run_id' => 'Start wymaga zakończonego aktualnego podglądu. Dla przerwanego przebiegu użyj Wznów.']);
            }
            $run['phase'] = 'prepare';
            $run['status'] = $run['progress']['remaining'] > 0 ? 'running' : 'completed';
            $this->save($run);

            return $this->snapshot($run);
        });
    }

    public function control(string $id, int $actor, string $action): array
    {
        $this->requireSchema();

        return DB::transaction(function () use ($id, $actor, $action): array {
            $run = $this->owned($id, $actor, true);
            if ($action === 'pause' && in_array($run['status'], ['previewing', 'running'], true)) {
                $run['status'] = 'paused';
            } elseif ($action === 'resume' && in_array($run['status'], ['paused', 'running', 'previewing'], true)) {
                $run['status'] = $run['phase'] === 'preview' ? 'previewing' : 'running';
            } elseif ($action === 'stop' && in_array($run['status'], ['ready', 'paused', 'running', 'previewing'], true)) {
                $run['status'] = 'stopped';
            }
            $this->save($run);

            return $this->snapshot($run);
        });
    }

    public function tick(string $id, int $actor): array
    {
        $this->requireSchema();
        $run = $this->owned($id, $actor);
        if ($run['status'] === 'previewing') {
            return $this->previewTick($id, $actor);
        }
        if ($run['status'] !== 'running') {
            return $this->snapshot($run);
        }
        for ($i = 0; $i < self::PREPARE_BATCH; $i++) {
            $claim = $this->claim($id, $actor);
            if (! $claim) {
                break;
            }
            try {
                if (! JarekFrProduct::query()->active()->where('jarek_gearbox_id', $claim['item']->source_id)->exists()) {
                    $result = ['status' => 'skipped', 'code' => 'source_no_longer_active'];
                } elseif ($claim['recovered']) {
                    $inspection = $this->images->inspect($claim['item']->local_url);
                    // An interrupted/fatal GD request must never trap the runner in a retry loop.
                    $result = $inspection['status'] === 'ready' && $inspection['fingerprint'] === $claim['item']->fingerprint
                        ? ['status' => 'already_ready'] : ['status' => 'error', 'code' => 'interrupted_image_attempt'];
                } else {
                    $result = $this->images->prepareUrl($claim['item']->local_url, $claim['item']->fingerprint);
                }
            } catch (Throwable) {
                $result = ['status' => 'error', 'code' => 'processing_exception'];
            }
            $this->finish($id, $actor, $claim, $result);
        }

        return $this->snapshot($this->owned($id, $actor));
    }

    private function previewTick(string $id, int $actor): array
    {
        return DB::transaction(function () use ($id, $actor): array {
            $run = $this->owned($id, $actor, true);
            if ($run['status'] !== 'previewing') {
                return $this->snapshot($run);
            }
            $ids = array_slice($run['candidate_ids'], $run['preview_offset'], self::PREVIEW_BATCH);
            $products = JarekFrProduct::query()->active()->with('gearbox')->whereIn('jarek_gearbox_id', $ids)->get()->keyBy('jarek_gearbox_id');
            $rows = [];
            foreach ($ids as $sourceId) {
                $gearbox = $products->get($sourceId)?->gearbox;
                $urls = $gearbox?->localizedImageUrls() ?? [];
                if ($urls === []) {
                    $rows[] = $this->item($run, $sourceId, 0, $gearbox?->title, null, null, 'skipped', 'skipped', $gearbox ? 'no_local_images' : 'source_no_longer_active');
                }
                foreach ($urls as $url) {
                    $inspection = $this->images->inspect($url);
                    $classification = $inspection['status'];
                    $status = match ($classification) {
                        'missing' => 'pending', 'failed' => 'failed', default => 'skipped'
                    };
                    $rows[] = $this->item($run, $sourceId, (int) basename(parse_url($url, PHP_URL_PATH), '.jpg'), $gearbox->title,
                        $url, $inspection['fingerprint'], $classification, $status, $inspection['code']);
                }
                $run['cursor'] = ['source_id' => $sourceId, 'image_number' => 60];
            }
            if ($rows) {
                DB::table(self::ITEMS)->insertOrIgnore($rows);
            }
            $run['preview_offset'] += count($ids);
            $run['previewed_exposures'] = $run['preview_offset'];
            $this->counts($run);
            if ($run['preview_offset'] >= count($run['candidate_ids'])) {
                $run['status'] = 'ready';
                unset($run['candidate_ids']);
            }
            $this->save($run);

            return $this->snapshot($run);
        });
    }

    private function claim(string $id, int $actor): ?array
    {
        return DB::transaction(function () use ($id, $actor): ?array {
            $run = $this->owned($id, $actor, true);
            if ($run['status'] !== 'running' || ($run['lease_token'] && $run['lease_until'] > now()->timestamp)) {
                return null;
            }
            $item = DB::table(self::ITEMS)->where('run_id', $id)->where('status', 'processing')->orderBy('id')->lockForUpdate()->first()
                ?? DB::table(self::ITEMS)->where('run_id', $id)->where('status', 'pending')->orderBy('id')->lockForUpdate()->first();
            if (! $item) {
                $run['status'] = 'completed';
                $this->counts($run);
                $this->save($run);

                return null;
            }
            $recovered = $item->status === 'processing';
            $token = (string) Str::uuid();
            DB::table(self::ITEMS)->where('id', $item->id)->update(['status' => 'processing', 'updated_at' => now()]);
            $run['lease_token'] = $token;
            $run['lease_until'] = now()->timestamp + self::LEASE_SECONDS;
            $run['current_product'] = ['source_id' => $item->source_id, 'image_number' => $item->image_number, 'name' => $item->source_name];
            $this->save($run);

            return ['item' => $item, 'token' => $token, 'recovered' => $recovered];
        });
    }

    private function finish(string $id, int $actor, array $claim, array $result): void
    {
        DB::transaction(function () use ($id, $actor, $claim, $result): void {
            $run = $this->owned($id, $actor, true);
            if ($run['lease_token'] !== $claim['token']) {
                return;
            }
            $status = match ($result['status']) {
                'prepared' => 'generated',
                'already_ready' => $claim['recovered'] ? 'generated' : 'skipped',
                'skipped' => 'skipped',
                default => 'failed',
            };
            $code = $result['code'] ?? $result['status'];
            DB::table(self::ITEMS)->where('id', $claim['item']->id)->where('status', 'processing')->update(['status' => $status, 'result_code' => $code, 'updated_at' => now()]);
            $event = ['source_id' => $claim['item']->source_id, 'image_number' => $claim['item']->image_number, 'code' => $code, 'at' => now()->toISOString()];
            if ($status === 'failed') {
                $run['last_error'] = $event;
            } else {
                $run['last_success'] = $event;
            }
            $run['cursor'] = ['source_id' => $claim['item']->source_id, 'image_number' => $claim['item']->image_number];
            $run['current_product'] = null;
            $run['lease_token'] = null;
            $run['lease_until'] = 0;
            // Item result and counters commit together. No full-history scan on every image.
            $run['progress']['processed']++;
            $run['progress']['remaining']--;
            $run['progress'][$status]++;
            if ($run['status'] === 'running' && $run['progress']['remaining'] === 0) {
                $run['status'] = 'completed';
            }
            $this->save($run);
        });
    }

    private function counts(array &$run): void
    {
        $byStatus = DB::table(self::ITEMS)->where('run_id', $run['run_id'])->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $byClass = DB::table(self::ITEMS)->where('run_id', $run['run_id'])->selectRaw('classification, COUNT(*) as total')->groupBy('classification')->pluck('total', 'classification');
        $total = (int) $byStatus->sum();
        $remaining = (int) ($byStatus['pending'] ?? 0) + (int) ($byStatus['processing'] ?? 0);
        $run['progress'] = ['total' => $total, 'processed' => $total - $remaining, 'generated' => (int) ($byStatus['generated'] ?? 0),
            'skipped' => (int) ($byStatus['skipped'] ?? 0), 'failed' => (int) ($byStatus['failed'] ?? 0), 'remaining' => $remaining];
        $run['preview'] = ['total_images' => (int) ($byClass['ready'] ?? 0) + (int) ($byClass['missing'] ?? 0) + (int) ($byClass['failed'] ?? 0),
            'ready_count' => (int) ($byClass['ready'] ?? 0), 'missing_count' => (int) ($byClass['missing'] ?? 0),
            'failed_count' => (int) ($byClass['failed'] ?? 0), 'skipped_count' => (int) ($byClass['skipped'] ?? 0)];
    }

    private function item(array $run, int $source, int $index, ?string $name, ?string $url, ?string $fingerprint, string $classification, string $status, ?string $code): array
    {
        return ['run_id' => $run['run_id'], 'source_id' => $source, 'image_number' => $index, 'source_name' => mb_substr((string) $name, 0, 300),
            'local_url' => $url, 'fingerprint' => $fingerprint, 'classification' => $classification, 'status' => $status, 'result_code' => $code,
            'created_at' => now(), 'updated_at' => now()];
    }

    private function owned(string $id, int $actor, bool $lock = false): array
    {
        $query = DB::table(self::RUNS)->where('run_id', $id)->where('actor_id', $actor);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($row, 404);

        return $this->decode($row);
    }

    private function decode(object $row): array
    {
        return array_replace(json_decode($row->checkpoint, true, 512, JSON_THROW_ON_ERROR), ['status' => $row->status, 'phase' => $row->phase]);
    }

    private function save(array &$run): void
    {
        $run['_revision']++;
        DB::table(self::RUNS)->where('run_id', $run['run_id'])->where('actor_id', $run['actor_id'])->update([
            'status' => $run['status'], 'phase' => $run['phase'], 'checkpoint' => json_encode($run, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
    }

    private function snapshot(array $run): array
    {
        $run['failed_samples'] = DB::table(self::ITEMS)->where('run_id', $run['run_id'])->where('status', 'failed')
            ->orderBy('id')->limit(20)->get(['source_id', 'image_number', 'result_code'])->all();
        unset($run['candidate_ids'], $run['actor_id'], $run['lease_token']);

        return $run;
    }

    private function requireSchema(): void
    {
        if (! $this->ready()) {
            throw ValidationException::withMessages(['schema' => 'Brak tabel checkpointów runnera zdjęć. Uruchom migrację tego PR podczas wdrożenia.']);
        }
    }
}
