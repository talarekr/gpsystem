<?php

namespace App\Services\Storefront;

use App\Models\FrenchProductTranslationRequest;
use App\Models\Part;
use App\Services\Marketplace\GoogleTranslateService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Explicitly approved, bounded browser batches; never called by Part save or public traffic. */
class FrenchNewProductsTranslationRunner
{
    public const CONFIRM = 'TRANSLATE-NEW-CHANGED-GPSWISS-FR';

    public const MUTATION_LOCK = 'storefront:fr:new-products:mutation';

    public const PROCESSING_LOCK = 'storefront:fr:new-products:processing';

    public function __construct(private FrenchCatalogTranslationService $translations, private FrenchProductTranslationRequests $requests, private FrenchCatalogTranslationRunStore $store) {}

    private function runs(): FrenchCatalogTranslationRunStore
    {
        return $this->store->forScope('new_products');
    }

    public function ready(): bool
    {
        return $this->requests->ready() && $this->runs()->ready();
    }

    public function status(): array
    {
        return $this->ready() ? ($this->runs()->latest() ?? $this->idle()) : $this->idle();
    }

    public function overview(): array
    {
        return ['schema_ready' => $this->ready(), 'enabled' => $this->translations->enabled(),
            'provider_ready' => (bool) app(GoogleTranslateService::class)->readiness(false)['ok'],
            'summary' => $this->ready() ? $this->inspect(false)['summary'] : [], 'status' => $this->status()];
    }

    public function dryRun(int $actor, bool $includeFailed = false): array
    {
        $this->schema();
        $report = $this->inspect($includeFailed) + ['dry_run_id' => (string) Str::uuid(), 'created_at' => now()->toISOString(), 'include_failed' => $includeFailed];
        Cache::put('storefront:fr:new-products:preview:'.$actor, $report, now()->addHour());

        return $report;
    }

    public function start(int $actor, string $id, string $confirm): array
    {
        if ($confirm !== self::CONFIRM) {
            $this->block('confirm', 'Wpisz dokładny token nowych/zmienionych produktów.');
        }
        $this->guard();

        return $this->mutate(function () use ($actor, $id): array {
            $run = $this->status();
            if (in_array($run['status'], ['running', 'paused', 'stopped_on_error'], true)) {
                if (($run['dry_run_id'] ?? '') === $id && ($run['actor_id'] ?? null) === $actor) {
                    return $run;
                }
                $this->block('run_id', 'Istnieje aktywny run. Wznów lub zatrzymaj go.');
            }
            $preview = Cache::get('storefront:fr:new-products:preview:'.$actor);
            if (! $preview || ($preview['dry_run_id'] ?? '') !== $id || ($preview['consumed'] ?? false)) {
                $this->block('dry_run_id', 'Wymagany nowy własny dry-run.');
            }
            $fresh = $this->inspect($preview['include_failed']);
            if (! hash_equals($preview['snapshot_hash'], $fresh['snapshot_hash'])) {
                $this->block('dry_run_id', 'Zakres/source hash zmienił się. Sprawdź nowy dry-run.');
            }
            if ($fresh['products'] === []) {
                $this->block('dry_run_id', 'Brak produktów kwalifikujących się do tej partii.');
            }
            $run = $this->idle() + ['actor_id' => $actor, 'dry_run_id' => $id, 'include_failed' => $preview['include_failed'],
                'manifest' => $fresh['products'], 'estimated_characters' => $fresh['summary']['selected_characters'], 'index' => 0, 'in_flight' => null];
            $run['run_id'] = (string) Str::uuid();
            $run['status'] = 'running';
            $run['total'] = count($fresh['products']);
            $run['started_at'] = now()->toISOString();
            $this->save($run);
            $preview['consumed'] = true;
            Cache::put('storefront:fr:new-products:preview:'.$actor, $preview, now()->addHour());

            return $run;
        });
    }

    public function tick(string $id): array
    {
        $this->schema();
        $lease = Cache::lock(self::PROCESSING_LOCK, 600);
        if (! $lease->get()) {
            return $this->status() + ['busy' => true];
        }
        try {
            $run = $this->mutate(function () use ($id): array {
                $run = $this->matching($id);
                if ($run['status'] !== 'running') {
                    return $run;
                }
                if ($run['in_flight']) {
                    // An uncertain provider attempt is never repeated automatically after process/cache loss.
                    $item = $run['in_flight'];
                    $part = Part::with('translations')->find($item['part_id']);
                    $translation = $part?->translationForLocale('fr');
                    $ready = $translation?->isReady() && $translation->matchesSourceHash($item['source_hash']);
                    $this->finish($run, $item, ['status' => $ready ? 'translated' : 'failed',
                        'reason' => $ready ? null : 'interrupted_attempt', 'chars_translated' => $item['characters']]);

                    return $run;
                }
                $this->guard();
                $item = $run['manifest'][$run['index']] ?? null;
                if (! $item) {
                    $run['status'] = 'completed';
                    $this->save($run);

                    return $run;
                }
                $part = Part::query()->storefrontVisible()->with('translations')->find($item['part_id']);
                if (! $part || ! hash_equals($item['source_hash'], $part->translationSourceHash())) {
                    $this->finish($run, $item, ['status' => 'skipped', 'reason' => 'source_changed_or_not_visible', 'chars_translated' => 0]);

                    return $run;
                }
                $translation = $part->translationForLocale('fr');
                if ($translation?->status === 'queued') {
                    $run['status'] = 'stopped_on_error';
                    $run['last_error'] = ['part_id' => $part->id, 'code' => 'queued_requires_original_runner'];
                    $this->save($run);

                    return $run;
                }
                $run['in_flight'] = $item;
                $this->save($run); // Durable claim before the provider; no DB/cache mutation lock across HTTP.

                return $run;
            });
            if ($run['status'] !== 'running' || ! $run['in_flight']) {
                return $run;
            }
            $item = $run['in_flight'];
            try {
                $result = $this->translations->translate('parts', $item['part_id'], ['include_needs_update' => true,
                    'include_failed' => $run['include_failed'], 'expected_source_hash' => $item['source_hash']]);
            } catch (Throwable) {
                $result = ['status' => 'failed', 'reason' => 'unexpected_provider_attempt_failure', 'chars_translated' => $item['characters']];
            }

            return $this->mutate(function () use ($id, $item, $result): array {
                $run = $this->matching($id);
                if (($run['in_flight']['part_id'] ?? null) !== $item['part_id']) {
                    return $run;
                }
                if (($result['reason'] ?? '') === 'busy') {
                    $run['in_flight'] = null;
                    $this->save($run);

                    return $run + ['busy' => true];
                }
                $this->finish($run, $item, $result);

                return $run;
            });
        } finally {
            $lease->release();
        }
    }

    public function control(string $id, string $action): array
    {
        if (! in_array($action, ['pause', 'resume', 'stop'], true)) {
            $this->block('action', 'Nieznana akcja.');
        }

        return $this->mutate(function () use ($id, $action): array {
            $run = $this->matching($id);
            if (in_array($run['status'], ['completed', 'stopped'], true)) {
                $this->block('run_id', 'Run jest zakończony; wykonaj nowy dry-run.');
            }
            if ($action === 'resume') {
                $this->guard();
            }
            $run['status'] = match ($action) {
                'pause' => 'paused', 'stop' => 'stopped', default => 'running'
            };
            $this->save($run);

            return $run;
        });
    }

    private function inspect(bool $includeFailed): array
    {
        $maxRecords = max(1, min(100, (int) config('storefront-translations.new_products_max_records', 100)));
        $maxCharacters = max(1, min(50000, (int) config('storefront-translations.new_products_max_characters', 50000)));
        $summary = array_fill_keys(['tracked_count', 'visible_count', 'waiting_visibility_count', 'eligible_count', 'reviewed_protected_count',
            'current_count', 'failed_count', 'queued_count', 'empty_source_count', 'over_budget_count', 'selected_count', 'selected_characters', 'deferred_count'], 0);
        $summary['tracked_count'] = FrenchProductTranslationRequest::count();
        $rows = [];
        $blocked = [];
        FrenchProductTranslationRequest::query()->whereHas('part', fn ($q) => $q->storefrontVisible())
            ->with('part.translations')->orderBy('id')->chunkById(100, function ($requests) use (&$summary, &$rows, &$blocked, $includeFailed, $maxRecords, $maxCharacters): void {
                foreach ($requests as $request) {
                    $summary['visible_count']++;
                    $part = $request->part;
                    $hash = $part->translationSourceHash();
                    $translation = $part->translationForLocale('fr');
                    $note = function (string $reason) use (&$blocked, $part): void {
                        if (count($blocked) < 20) {
                            $blocked[] = ['part_id' => $part->id, 'sku' => $part->sku, 'name' => $part->name, 'reason' => $reason];
                        }
                    };
                    if ($translation?->isReady() && $translation->matchesSourceHash($hash)) {
                        $summary['current_count']++;

                        continue;
                    }
                    if ($translation?->status === 'reviewed') {
                        $summary['reviewed_protected_count']++;
                        $note('reviewed_protected');

                        continue;
                    }
                    if ($translation?->status === 'queued') {
                        $summary['queued_count']++;
                        $note('queued_requires_original_runner');

                        continue;
                    }
                    if ($request->status === 'failed' || $translation?->status === 'failed') {
                        $summary['failed_count']++;
                        if (! $includeFailed) {
                            $note($request->error_code ?? $translation?->error_code ?? 'failed_requires_explicit_retry');

                            continue;
                        }
                    }
                    $fields = $this->translations->sourceFields($part);
                    $characters = array_sum(array_map(fn ($text) => mb_strlen($text), $fields));
                    if ($characters === 0) {
                        $summary['empty_source_count']++;
                        $note('empty_source');

                        continue;
                    }
                    if ($characters > $maxCharacters) {
                        $summary['over_budget_count']++;
                        $note('over_budget');

                        continue;
                    }
                    $summary['eligible_count']++;
                    if (count($rows) >= $maxRecords || $maxCharacters < $summary['selected_characters'] + $characters) {
                        continue;
                    }
                    $summary['selected_characters'] += $characters;
                    $rows[] = ['part_id' => $part->id, 'source_hash' => $hash, 'characters' => $characters, 'sku' => $part->sku, 'name' => $part->name,
                        'translation_status' => $translation?->status ?? 'missing', 'request_status' => $request->status,
                        'last_error_code' => $request->error_code ?? $translation?->error_code];
                }
            });
        $summary['waiting_visibility_count'] = $summary['tracked_count'] - $summary['visible_count'];
        $summary['selected_count'] = count($rows);
        $summary['deferred_count'] = $summary['eligible_count'] - count($rows);

        return ['summary' => $summary, 'products' => $rows, 'blocked_products' => $blocked, 'max_records' => $maxRecords, 'max_characters' => $maxCharacters,
            'snapshot_hash' => hash('sha256', json_encode([$rows, $includeFailed, $maxRecords, $maxCharacters], JSON_THROW_ON_ERROR))];
    }

    private function finish(array &$run, array $item, array $result): void
    {
        $this->requests->finish($item['part_id'], $item['source_hash'], $result);
        $run['processed']++;
        $run[$result['status'] === 'translated' ? 'translated' : ($result['status'] === 'failed' ? 'failed' : 'skipped')]++;
        $run['chars_translated'] += (int) ($result['chars_translated'] ?? 0);
        $run['index']++;
        $run['in_flight'] = null;
        $run['last_result'] = ['part_id' => $item['part_id'], 'status' => $result['status'], 'code' => $result['reason'] ?? null];
        if ($result['status'] === 'failed') {
            $run['last_error'] = $run['last_result'];
            if (! in_array($run['status'], ['paused', 'stopped'], true)) {
                $run['status'] = 'stopped_on_error';
            }
        } elseif ($run['processed'] === $run['total'] && $run['status'] === 'running') {
            $run['status'] = 'completed';
        }
        $this->save($run);
    }

    private function idle(): array
    {
        return ['run_id' => null, 'status' => 'idle', 'total' => 0, 'processed' => 0, 'translated' => 0, 'skipped' => 0,
            'failed' => 0, 'remaining' => 0, 'chars_translated' => 0, 'last_error' => null, 'runner_mode' => 'browser'];
    }

    private function save(array &$run): void
    {
        $run['updated_at'] = now()->toISOString();
        $run['remaining'] = max(0, $run['total'] - $run['processed']);
        $this->runs()->save($run);
    }

    private function matching(string $id): array
    {
        $run = $this->status();
        if (($run['run_id'] ?? '') !== $id) {
            $this->block('run_id', 'Nieaktualny run_id.');
        }

        return $run;
    }

    private function guard(): void
    {
        $this->schema();
        if (! $this->translations->enabled() || ! app(GoogleTranslateService::class)->readiness(false)['ok']) {
            $this->block('provider', 'Tłumaczenia/Google nie są gotowe; zapis produktu i fallback nadal działają.');
        }
    }

    private function schema(): void
    {
        if (! $this->ready()) {
            $this->block('schema', 'Wymagana migracja zgłoszeń tłumaczeń produktów i trwałego runnera.');
        }
    }

    private function mutate(callable $callback): mixed
    {
        return Cache::lock(self::MUTATION_LOCK, 10)->block(3, $callback);
    }

    private function block(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
