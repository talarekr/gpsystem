<?php

namespace App\Services\Storefront;

use App\Jobs\RunFrenchCatalogTranslationBatch;
use App\Models\Part;
use App\Models\PartCategory;
use App\Services\Marketplace\GoogleTranslateService;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * A shared-cache checkpoint, a short mutation lock and an independent worker lease.
 * Provider calls run only in queued batches; UI controls merge into the checkpoint after each
 * record. No transaction or mutation lock is held while contacting the provider.
 */
class FrenchCatalogTranslationAdminRunner
{
    public const KEY = 'storefront:fr:admin:run';

    public const CONFIRM = 'TRANSLATE-ALL-GPSWISS-FR-CATALOG';

    public const MUTATION_LOCK = 'storefront:fr:admin:mutation';

    public const PROCESSING_LOCK = 'storefront:fr:admin:processing';

    private const BATCH_SIZE = 100;

    private const SOFT_SECONDS = 45;

    public function __construct(
        private FrenchCatalogTranslationService $translations,
        private FrenchCatalogTranslationPreviewService $preview,
    ) {}

    public function start(int $actorId, string $dryRunId, string $confirm): array
    {
        if ($confirm !== self::CONFIRM) {
            return $this->error('invalid_confirmation', 'Wpisz dokładny token potwierdzenia.');
        }
        if ($error = $this->guard()) {
            return $error;
        }

        try {
            return $this->mutate(function () use ($actorId, $dryRunId): array {
                $existing = Cache::get(self::KEY);
                if ($existing && in_array($existing['status'], ['running', 'paused', 'stopped_on_error'], true)) {
                    return $this->error('active_run', 'Istnieje aktywne zadanie. Wznów je lub zatrzymaj.');
                }
                $lease = Cache::lock(self::PROCESSING_LOCK, 600);
                if (! $lease->get()) {
                    return $this->error('busy', 'Poprzedni worker kończy bieżący rekord. Spróbuj ponownie po chwili.');
                }
                try {
                    $report = $this->preview->saved($actorId);
                    if (! $report || ! hash_equals((string) ($report['dry_run_id'] ?? ''), $dryRunId)) {
                        return $this->error('dry_run_required', 'Najpierw wykonaj nowy dry-run dla swojego konta.');
                    }
                    if ($report['consumed'] ?? true) {
                        return $this->error('dry_run_consumed', 'Ten dry-run został już wykorzystany. Wykonaj nowy.');
                    }
                    try {
                        $created = Carbon::parse($report['created_at']);
                        $fresh = $created->betweenIncluded(now()->subHour(), now());
                        if ($existing && in_array($existing['status'], ['completed', 'stopped', 'failed'], true)) {
                            $fresh = $fresh && $created->greaterThanOrEqualTo(Carbon::parse($existing['updated_at']));
                        }
                    } catch (Throwable) {
                        $fresh = false;
                    }
                    if (! $fresh) {
                        return $this->error('dry_run_expired', 'Dry-run jest nieaktualny. Wykonaj nowy.');
                    }
                    // These checks and consumption share the global lock with every start/control.
                    if ($error = $this->guard()) {
                        return $error;
                    }
                    $run = $this->idle();
                    $run['run_id'] = (string) Str::uuid();
                    $run['status'] = 'running';
                    $run['started_at'] = $run['updated_at'] = now()->toISOString();
                    $run['total'] = $run['remaining'] = (int) $report['total'];
                    $run['_max_ids'] = ['parts' => (int) $report['products']['max_id'], 'categories' => (int) $report['categories']['max_id']];
                    $run['_totals'] = ['parts' => (int) $report['products']['total'], 'categories' => (int) $report['categories']['total']];
                    $run['_cursor'] = ['parts' => 0, 'categories' => 0];
                    $run['_processed'] = ['parts' => 0, 'categories' => 0];
                    $run['_pending'] = null;
                    $report['consumed'] = true;
                    Cache::forever(FrenchCatalogTranslationPreviewService::KEY.$actorId, $report);
                    $this->save($run);
                    if (! $this->enqueueLocked($run, 0)) {
                        return $this->error('queue_unavailable', 'Nie udało się wysłać zadania do kolejki. Wykonaj nowy dry-run.');
                    }

                    return ['ok' => true] + $this->publicRun($run);
                } finally {
                    $lease->release();
                }
            });
        } catch (LockTimeoutException) {
            return $this->error('busy', 'Inna operacja właśnie zmienia zadanie. Spróbuj ponownie.');
        }
    }

    /** Reading progress never dispatches work and never touches the translation provider. */
    public function status(): array
    {
        return $this->publicRun(Cache::get(self::KEY) ?? $this->idle());
    }

    public function control(string $runId, string $action): array
    {
        try {
            return $this->mutate(function () use ($runId, $action): array {
                $run = Cache::get(self::KEY);
                if (! $run || ! hash_equals($run['run_id'], $runId)) {
                    return $this->error('control_conflict', 'To zadanie nie jest już aktualne. Odśwież status.');
                }
                if ($action === 'pause' && $run['status'] === 'running') {
                    $run['status'] = 'paused';
                    $run['next_batch'] = null;
                } elseif ($action === 'stop' && in_array($run['status'], ['running', 'paused', 'stopped_on_error', 'failed'], true)) {
                    $run['status'] = 'stopped';
                    $run['next_batch'] = null;
                } elseif ($action === 'resume' && in_array($run['status'], ['running', 'paused', 'stopped_on_error', 'failed'], true)) {
                    if ($run['status'] === 'running' && $run['next_batch'] !== null
                        && Carbon::parse($run['next_batch'])->greaterThan(now()->subSeconds(660))) {
                        return ['ok' => true] + $this->publicRun($run);
                    }
                    $run['status'] = 'running';
                    $this->save($run);
                    $delay = $this->retryDelay($run['_pending']);
                    // Resume also recovers a crashed worker using the same run/cursor.
                    if (! $this->enqueueLocked($run, $delay)) {
                        return $this->error('queue_unavailable', 'Nie udało się wysłać zadania do kolejki. Spróbuj wznowić ponownie.');
                    }

                    return ['ok' => true] + $this->publicRun($run);
                } else {
                    return $this->error('control_conflict', 'Ta operacja nie jest dostępna w obecnym stanie zadania.');
                }
                $this->save($run);

                return ['ok' => true] + $this->publicRun($run);
            });
        } catch (LockTimeoutException) {
            return $this->error('busy', 'Inna operacja właśnie zmienia zadanie. Spróbuj ponownie.');
        }
    }

    /** At most 100 records or 45 seconds; retry backoff uses delayed jobs, never sleeps. */
    public function runBatch(string $runId): void
    {
        $lease = Cache::lock(self::PROCESSING_LOCK, 600);
        if (! $lease->get()) {
            $this->enqueue($runId, 30);

            return;
        }
        $delay = null;
        try {
            $this->mutate(function () use ($runId): void {
                $run = $this->running($runId);
                if ($run) {
                    $run['next_batch'] = null;
                    $this->save($run);
                }
            });
            $started = microtime(true);
            for ($count = 0; $count < self::BATCH_SIZE && microtime(true) - $started < self::SOFT_SECONDS; $count++) {
                $run = $this->running($runId);
                if (! $run) {
                    return;
                }
                if ($error = $this->guard()) {
                    $this->stopOnError($runId, $error['reason']);

                    return;
                }
                if ($run['_pending'] && $run['_pending']['in_flight']) {
                    if (! $this->recover($runId, $run['_pending'])) {
                        $delay = 30;

                        return;
                    }

                    continue;
                }
                if (! $run['_pending']) {
                    $record = $this->nextRecord($runId, $run);
                    if (! $record) {
                        return;
                    }
                    $pending = [
                        'type' => $record instanceof Part ? 'parts' : 'categories',
                        'sku' => $record instanceof Part ? $record->sku : null,
                        'id' => (int) $record->id, 'retry_attempt' => 0, 'retry_at' => null,
                        'source_hash' => $record->translationSourceHash(), 'in_flight' => false,
                        'chars_translated' => 0, 'estimated_characters' => array_sum(array_map(fn ($text) => mb_strlen(trim($text)), $this->translations->sourceFields($record))),
                    ];
                    $this->mutate(function () use ($runId, $pending): void {
                        if ($run = $this->running($runId)) {
                            $run['_pending'] = $pending;
                            $run['current_type'] = $pending['type'];
                            $run['current_id'] = $pending['id'];
                            $this->save($run);
                        }
                    });
                    $run = $this->running($runId);
                    if (! $run) {
                        return;
                    }
                }
                $pending = $run['_pending'];
                if (($delay = $this->retryDelay($pending)) > 0) {
                    return;
                }
                $delay = null;
                $record = $this->record($pending['type'], $pending['id']);
                $translation = $record?->translationForLocale('fr');
                $options = FrenchCatalogTranslationPreviewService::OPTIONS;
                if ($pending['retry_attempt'] > 0) {
                    // Permission to retry belongs to this run's exact failed attempt only.
                    if (! $record || $translation?->status !== 'failed'
                        || $translation->attempts !== $pending['expected_attempts']
                        || ! $translation->matchesSourceHash($pending['source_hash'])
                        || $translation->error_code !== $pending['retry_reason']
                        || ! hash_equals($pending['source_hash'], $record->translationSourceHash())) {
                        $this->finishRecord($runId, $pending, $this->result('skipped', 'translation_changed'));

                        continue;
                    }
                    $options['include_failed'] = true;
                }
                $pending['baseline_attempts'] = (int) ($translation?->attempts ?? 0);
                $pending['in_flight'] = true;
                $claimed = $this->mutate(function () use ($runId, $pending): bool {
                    if (! ($run = $this->running($runId))) {
                        return false;
                    }
                    $run['_pending'] = $pending;
                    $this->save($run);

                    return true;
                });
                if (! $claimed) {
                    return;
                }
                // translate() makes one attempt and owns the existing per-record lock.
                $result = $this->translations->translate($pending['type'], $pending['id'], $options);
                if ($result['reason'] === 'busy') {
                    $this->mutate(function () use ($runId): void {
                        $run = Cache::get(self::KEY);
                        if ($run && $run['run_id'] === $runId && $run['_pending']) {
                            $run['_pending']['in_flight'] = false;
                            $this->save($run);
                        }
                    });
                    $delay = 30;

                    return;
                }
                $retry = $this->finishRecord($runId, $pending, $result);
                if ($retry !== null) {
                    $delay = $retry;

                    return;
                }
            }
            $delay = 0;
        } finally {
            $lease->release();
            if ($delay !== null) {
                $this->enqueue($runId, $delay);
            }
        }
    }

    public function failed(string $runId): void
    {
        $this->mutate(function () use ($runId): void {
            if ($run = $this->running($runId)) {
                $run['status'] = 'failed';
                $run['next_batch'] = null;
                $run['last_error'] = $this->event('worker_failed', $run['current_type'], $run['current_id']);
                $this->save($run);
            }
        });
    }

    private function nextRecord(string $runId, array $run): Part|PartCategory|null
    {
        foreach (['parts' => Part::class, 'categories' => PartCategory::class] as $type => $model) {
            $record = $model::query()->with(['translations' => fn ($q) => $q->where('locale', 'fr')])
                ->where('id', '>', $run['_cursor'][$type])->where('id', '<=', $run['_max_ids'][$type])->orderBy('id')->first();
            if ($record) {
                return $record;
            }
            $this->mutate(function () use ($runId, $type): void {
                if ($state = $this->running($runId)) {
                    // Deleted snapshot records count as skipped, preserving total/remaining.
                    $missing = max(0, $state['_totals'][$type] - $state['_processed'][$type]);
                    $state['processed'] += $missing;
                    $state['skipped'] += $missing;
                    $state['_processed'][$type] += $missing;
                    $state['_cursor'][$type] = $state['_max_ids'][$type];
                    $this->save($state);
                }
            });
        }
        $this->mutate(function () use ($runId): void {
            if ($state = $this->running($runId)) {
                $state['status'] = 'completed';
                $state['next_batch'] = null;
                $this->save($state);
            }
        });

        return null;
    }

    /** Recover a completed database write before advancing its cache cursor. */
    private function recover(string $runId, array $pending): bool
    {
        $lock = Cache::lock('storefront:fr:'.$pending['type'].':'.$pending['id'], 600);
        if (! $lock->get()) {
            return false;
        }
        try {
            $record = $this->record($pending['type'], $pending['id']);
            $translation = $record?->translationForLocale('fr');
            $attempted = $translation && $translation->attempts > $pending['baseline_attempts'];
            if ($attempted && $translation->isReady() && $translation->matchesSourceHash($pending['source_hash'])) {
                $result = $this->result('translated', null, $pending['estimated_characters']);
            } elseif ($attempted && in_array($translation->status, ['failed', 'needs_update'], true)) {
                $code = FrenchCatalogTranslationPreviewService::safeError($translation->error_code)['error_code'];
                $result = $this->result('failed', $code);
                $result['retryable'] = $code === 'google_http_429' || preg_match('/^google_http_5[0-9]{2}$/', $code) === 1;
            } elseif ($attempted && $translation->status === 'queued') {
                // A request may already have been charged. Resume skips this uncertain record.
                $changed = $record->translations()->where('locale', 'fr')->where('status', 'queued')
                    ->where('attempts', $translation->attempts)->where('source_hash', $translation->source_hash)
                    ->update(['status' => 'failed', 'error_code' => 'interrupted_attempt',
                        'error_message' => 'The worker stopped before confirming the provider result.']);
                $result = $changed ? $this->result('failed', 'interrupted_attempt') : $this->result('skipped', 'translation_changed');
            } else {
                // No claim was persisted, so no provider call was possible in this attempt.
                $this->mutate(function () use ($runId): void {
                    if ($run = $this->running($runId)) {
                        $run['_pending']['in_flight'] = false;
                        $this->save($run);
                    }
                });

                return true;
            }
            $this->finishRecord($runId, $pending, $result);

            return true;
        } finally {
            $lock->release();
        }
    }

    /** Merge counters into the latest state so an in-flight call cannot undo Pause/Stop. */
    private function finishRecord(string $runId, array $pending, array $result): ?int
    {
        return $this->mutate(function () use ($runId, $pending, $result): ?int {
            $run = Cache::get(self::KEY);
            if (! $run || $run['run_id'] !== $runId || ! $run['_pending']
                || $run['_pending']['id'] !== $pending['id'] || $run['_pending']['type'] !== $pending['type']) {
                return null;
            }
            $pending['chars_translated'] += (int) $result['chars_translated'];
            $run['chars_translated'] += (int) $result['chars_translated'];
            if ($result['retryable'] && $pending['retry_attempt'] < count(FrenchCatalogTranslationService::BACKOFF)) {
                $delay = FrenchCatalogTranslationService::BACKOFF[$pending['retry_attempt']];
                $pending['retry_attempt']++;
                $pending['retry_at'] = now()->addSeconds($delay)->toIso8601String();
                $pending['expected_attempts'] = $pending['baseline_attempts'] + 1;
                $pending['retry_reason'] = $result['reason'];
                $pending['in_flight'] = false;
                $run['_pending'] = $pending;
                $run['last_error'] = $this->event($result['reason'], $pending['type'], $pending['id'], $pending['sku'] ?? null);
                $this->save($run);

                return $delay;
            }
            $status = in_array($result['status'], ['translated', 'skipped', 'failed'], true) ? $result['status'] : 'failed';
            $run['processed']++;
            $run[$status]++;
            $run['_processed'][$pending['type']]++;
            $run['_cursor'][$pending['type']] = $pending['id'];
            $run['_pending'] = null;
            if ($status === 'translated') {
                $run['last_success'] = $this->event('translated', $pending['type'], $pending['id'], $pending['sku'] ?? null);
            } elseif ($status === 'failed') {
                $event = $this->event($result['reason'], $pending['type'], $pending['id'], $pending['sku'] ?? null);
                $run['last_error'] = $event;
                $run['failed_examples'] = array_slice([...$run['failed_examples'], $event], -20);
                // Exhausted/provider/uncertain errors halt costs; Resume starts at the next ID.
                if ($run['status'] === 'running') {
                    $run['status'] = 'stopped_on_error';
                    $run['next_batch'] = null;
                }
            }
            $this->save($run);

            return null;
        });
    }

    private function record(string $type, int $id): Part|PartCategory|null
    {
        $model = $type === 'parts' ? Part::class : PartCategory::class;

        return $model::query()->with(['translations' => fn ($q) => $q->where('locale', 'fr')])->find($id);
    }

    private function enqueue(string $runId, int $delay): void
    {
        $this->mutate(function () use ($runId, $delay): void {
            if ($run = $this->running($runId)) {
                $this->enqueueLocked($run, $delay);
            }
        });
    }

    private function enqueueLocked(array &$run, int $delay): bool
    {
        $run['next_batch'] = now()->addSeconds($delay)->toIso8601String();
        $this->save($run);
        try {
            Bus::dispatch((new RunFrenchCatalogTranslationBatch($run['run_id']))->delay($delay));

            return true;
        } catch (Throwable) {
            $run['status'] = 'failed';
            $run['next_batch'] = null;
            $run['last_error'] = $this->event('queue_unavailable');
            $this->save($run);

            return false;
        }
    }

    private function stopOnError(string $runId, string $code): void
    {
        $this->mutate(function () use ($runId, $code): void {
            if ($run = $this->running($runId)) {
                $run['status'] = 'stopped_on_error';
                $run['next_batch'] = null;
                $run['last_error'] = $this->event($code, $run['current_type'], $run['current_id']);
                $this->save($run);
            }
        });
    }

    private function guard(): ?array
    {
        if (! $this->translations->enabled()) {
            return $this->error('translations_disabled', 'Tłumaczenia FR są wyłączone. Wymagane GPSWISS_FR_TRANSLATIONS_ENABLED=true.');
        }
        if (! $this->preview->schemaReady()) {
            return $this->error('schema_missing', 'Brak tabel tłumaczeń. Uruchom migracje Etapu 2A.');
        }
        if (! in_array(config('queue.connections.storefront-translations.driver'), ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
            return $this->error('queue_unavailable', 'Wymagana jest osobna asynchroniczna kolejka storefront-translations.');
        }
        if (! app(GoogleTranslateService::class)->readiness(false)['ok']) {
            return $this->error('provider_not_ready', 'Google Translate nie jest gotowy. Sprawdź konfigurację providera.');
        }

        return null;
    }

    private function retryDelay(?array $pending): int
    {
        return $pending && ($pending['retry_at'] ?? null)
            ? max(0, (int) ceil(now()->diffInSeconds(Carbon::parse($pending['retry_at']), false))) : 0;
    }

    private function running(string $runId): ?array
    {
        $run = Cache::get(self::KEY);

        return $run && $run['run_id'] === $runId && $run['status'] === 'running' ? $run : null;
    }

    private function mutate(callable $callback): mixed
    {
        return Cache::lock(self::MUTATION_LOCK, 10)->block(3, $callback);
    }

    private function save(array &$run): void
    {
        $run['updated_at'] = now()->toISOString();
        $run['remaining'] = max(0, $run['total'] - $run['processed']);
        Cache::forever(self::KEY, $run);
    }

    private function publicRun(array $run): array
    {
        return array_intersect_key($run, $this->idle());
    }

    private function idle(): array
    {
        return ['run_id' => null, 'status' => 'idle', 'started_at' => null, 'updated_at' => null,
            'total' => 0, 'processed' => 0, 'translated' => 0, 'skipped' => 0, 'failed' => 0,
            'remaining' => 0, 'chars_translated' => 0, 'current_type' => null, 'current_id' => null,
            'last_success' => null, 'last_error' => null, 'failed_examples' => [], 'next_batch' => null,
            'active_timer' => false, 'batch_size' => self::BATCH_SIZE];
    }

    private function error(string $reason, string $message): array
    {
        return ['ok' => false, 'reason' => $reason, 'error' => $message];
    }

    private function event(?string $code, ?string $type = null, ?int $id = null, ?string $sku = null): array
    {
        $messages = ['translated' => 'Tłumaczenie zapisane.', 'worker_failed' => 'Worker przerwał zadanie. Możesz bezpiecznie wznowić.',
            'queue_unavailable' => 'Kolejka jest niedostępna. Sprawdź worker i połączenie.',
            'provider_not_ready' => 'Google Translate nie jest gotowy. Sprawdź konfigurację providera.',
            'schema_missing' => 'Brak tabel tłumaczeń. Uruchom migracje Etapu 2A.'];
        if (isset($messages[$code ?? ''])) {
            $message = $messages[$code];
        } else {
            $safe = FrenchCatalogTranslationPreviewService::safeError($code);
            $code = $safe['error_code'];
            $message = $safe['error_message'];
        }

        $event = ['type' => $type, 'id' => $id, 'code' => $code, 'message' => $message,
            'error_code' => $code, 'error_message' => $message, 'at' => now()->toIso8601String()];
        if ($type === 'parts') {
            $event['part_id'] = $id;
            $event['sku'] = $sku;
        } elseif ($type === 'categories') {
            $event['category_id'] = $id;
        }

        return $event;
    }

    private function result(string $status, ?string $reason = null, int $characters = 0): array
    {
        return ['status' => $status, 'reason' => $reason, 'chars_translated' => $characters, 'retryable' => false];
    }
}
