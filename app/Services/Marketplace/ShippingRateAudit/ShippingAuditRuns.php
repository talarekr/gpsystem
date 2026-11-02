<?php

namespace App\Services\Marketplace\ShippingRateAudit;

use App\Services\Marketplace\AllegroShippingCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Only these two technical tables may be mutated; business reads use the guarded audit. */
final class ShippingAuditRuns
{
    public function __construct(private ShippingRateAuditService $audit) {}

    public function start(int $owner): array
    {
        return ReadOnlyDatabase::run(function () use ($owner): array {
            $id = (string) Str::uuid();
            $checkpoint = ['run_id' => $id, 'max_part_id' => (int) DB::table('parts')->max('id'),
                'cursor' => 0, 'processed_count' => 0, 'completed_count' => 0, 'total' => DB::table('parts')->count(),
                'phase' => 'scan', 'scan_complete' => false, 'retry_generation' => 0, 'current_part' => null, 'last_success' => null,
                'last_error' => null, 'summary' => array_fill_keys(ShippingRateAuditService::COUNTERS, 0),
                'started_at' => now()->toIso8601String(), 'breakdown' => [], 'catalog' => [], 'local_options' => []];
            DB::table('allegro_shipping_audit_runs')->insert(['id' => $id, 'owner_id' => $owner, 'state' => 'paused',
                'checkpoint' => json_encode($checkpoint, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

            return $this->status($owner, $id, true);
        }, true);
    }

    public function status(int $owner, ?string $id = null, bool $written = false): array
    {
        return ReadOnlyDatabase::run(function () use ($owner, $id, $written): array {
            $query = DB::table('allegro_shipping_audit_runs')->where('owner_id', $owner);
            $run = $id ? $query->where('id', $id)->first() : $query->orderByDesc('created_at')->orderByDesc('id')->first();
            if (! $run && $id) {
                abort(404);
            }
            if (! $run) {
                return $this->flags($written) + ['run' => null];
            }
            $c = json_decode($run->checkpoint, true, 512, JSON_THROW_ON_ERROR);
            $this->diagnostics($run->id, $c);
            $c['state'] = $run->state;
            $c['busy'] = $this->liveLease($run);
            $c['lease_token_present'] = $run->lease_token !== null;
            $c['lease_fingerprint'] = $run->lease_token ? substr(hash('sha256', $run->lease_token), 0, 12) : null;
            $c['lease_expires_in'] = $c['busy'] ? max(0, (int) ceil(now()->diffInSeconds(Carbon::parse($run->lease_until), false))) : 0;
            $c['stale_lease'] = $run->lease_token !== null && ! $c['busy'];
            $c['lease_owner'] = $run->owner_id;
            $c['last_heartbeat'] = $run->updated_at;
            $c['heartbeat_source'] = 'checkpoint_updated_at';
            $c['blocked_reason'] = $run->state !== 'running' ? $run->state : ($c['busy'] ? 'waiting_for_live_lease' : null);
            $c['lease_until'] = $run->lease_until;
            $c['wait_seconds'] = isset($c['wait_until']) ? max(0, (int) ceil(now()->diffInSeconds(Carbon::parse($c['wait_until']), false))) : 0;
            if ($c['wait_seconds'] > 0 && ! $c['blocked_reason']) {
                $c['blocked_reason'] = 'provider_cooldown';
            }
            $c['remaining'] = ($c['scan_complete'] ?? false) ? 0 : max(0, $c['total'] - $c['processed_count']);
            $c['coverage_changed'] = ($c['scan_complete'] ?? false) && $c['processed_count'] !== $c['total'];
            $c['provider_read_failed'] = DB::table('allegro_shipping_audit_results')->where('run_id', $run->id)->where('read_failed', true)->count();
            $c['resolved'] = $c['completed_count'];
            $c['next_part_id'] = $c['phase'] === 'retry' ? $this->retryQuery($run->id, $c)->orderBy('part_id')->value('part_id') : DB::table('parts')->where('id', '>', $c['cursor'])->where('id', '<=', $c['max_part_id'])->orderBy('id')->value('id');
            $c['retry_remaining'] = $c['phase'] === 'retry' ? $this->retryQuery($run->id, $c)->count() : 0;
            $c['resume_allowed'] = in_array($run->state, ['running', 'paused', 'stopped'], true);
            $c['next_action'] = match (true) {
                $run->state === 'completed' => 'none',
                $run->state !== 'running' => 'resume',
                $c['busy'] => 'poll_lease',
                $c['wait_seconds'] > 0 => 'poll_cooldown',
                $c['phase'] === 'scan' => 'scan_tick',
                $c['phase'] === 'retry' => 'retry_tick',
                default => 'blocked',
            };

            $c['failed_ids'] = DB::table('allegro_shipping_audit_results')->where('run_id', $run->id)->where('read_failed', true)->orderBy('part_id')->limit(200)->pluck('part_id')->all();
            $c['failed_ids_truncated'] = $c['provider_read_failed'] > 200;
            $c['preview'] = DB::table('allegro_shipping_audit_results')->where('run_id', $run->id)->orderByDesc('part_id')->limit(20)->get(['payload'])->map(function ($r) {
                $row = json_decode($r->payload, true);
                $row['offers_count'] = count($row['offers'] ?? []);
                $row['offers_truncated'] = $row['offers_count'] > 5;
                if (isset($row['offers'])) {
                    $row['offers'] = array_slice($row['offers'], 0, 5);
                }

                return $row;
            })->all();

            if (isset($c['pending_read'])) {
                $c['pending_read'] = ['part_id' => $c['pending_read']['part_id'], 'saved_gets' => count($c['pending_read']['cache'] ?? [])];
            }
            unset($c['catalog_cache']);
            if (isset($c['retry_history'])) {
                $c['retry_history_total'] = count($c['retry_history']);
                $c['retry_history'] = array_slice($c['retry_history'], -10);
            }

            return $this->flags($written) + ['run' => $c, 'resume_allowed' => $c['resume_allowed'], 'next_action' => $c['next_action'], 'remaining' => $c['remaining'], 'cursor' => $c['cursor'], 'state' => $c['state']];
        });
    }

    public function control(int $owner, string $id, string $action): array
    {
        return ReadOnlyDatabase::run(function () use ($owner, $id, $action): array {
            DB::transaction(function () use ($owner, $id, $action): void {
                $r = $this->locked($owner, $id);
                $c = json_decode($r->checkpoint, true);
                $this->diagnostics($id, $c);
                $extra = [];
                if ($action === 'resume') {
                    $live = $this->liveLease($r);
                    $c['resume_reason'] = $live ? 'waiting_for_live_lease' : 'resumed';
                    if ($r->lease_token && ! $live) {
                        $c['last_abandoned_tick'] = $c['last_tick'] ?? ['reason' => 'legacy_abandoned_tick'];
                        $c['last_abandoned_tick']['recovery_reason'] = 'expired_lease_recovered';
                        $extra = ['lease_token' => null, 'lease_until' => null];
                    }
                }
                $state = match ($action) {
                    'resume', 'retry' => 'running', 'pause' => 'paused', 'stop' => 'stopped'
                };
                if ($action === 'retry') {
                    $this->terminal404s($id, $c);
                    abort_if($this->liveLease($r), 409, 'Batch still running.');
                    abort_unless(($c['scan_complete'] ?? false) && $r->state !== 'running', 409, 'Finish scan before failed-only retry.');
                    $c['retry_history'][] = ['started_at' => now()->toIso8601String(), 'failed_before' => $c['summary']['provider_read_failed']];
                    $c['phase'] = 'retry';
                    $c['retry_generation']++;
                } elseif ($action === 'resume' && $r->state === 'completed') {
                    $state = 'completed';
                }
                $this->save($id, $c, ['state' => $state] + $extra);
            });

            return $this->status($owner, $id, true);
        }, true);
    }

    public function batch(int $owner, string $id): array
    {
        return ReadOnlyDatabase::run(function () use ($owner, $id): array {
            $claim = DB::transaction(function () use ($owner, $id): ?array {
                $r = $this->locked($owner, $id);
                $c = json_decode($r->checkpoint, true);
                $this->diagnostics($id, $c);
                if ($r->state !== 'running' || $this->liveLease($r)) {
                    return null;
                }
                if (isset($c['wait_until']) && $c['wait_until'] > now()->toDateTimeString()) {
                    return null;
                }
                if ($c['phase'] === 'scan') {
                    $partId = DB::table('parts')->where('id', '>', $c['cursor'])->where('id', '<=', $c['max_part_id'])->orderBy('id')->value('id');
                    $due = null;
                } else {
                    $next = $this->retryQuery($id, $c)->orderBy('part_id')->first();
                    $partId = $next?->part_id;
                    $due = $next?->retry_not_before;
                }
                if (! $partId) {
                    if ($c['phase'] === 'scan') {
                        $c['scan_complete'] = true;
                    } elseif (! empty($c['retry_history'])) {
                        $index = array_key_last($c['retry_history']);
                        $c['retry_history'][$index]['finished_at'] = now()->toIso8601String();
                        $c['retry_history'][$index]['failed_after'] = $c['summary']['provider_read_failed'];
                    }
                    $this->save($id, $c, ['state' => 'completed', 'lease_token' => null, 'lease_until' => null]);

                    return null;
                }
                if ($due && $due > now()->toDateTimeString()) {
                    $c['wait_until'] = $due;
                    $this->save($id, $c);

                    return null;
                }
                unset($c['wait_until']);
                if ($r->lease_token) {
                    $c['last_abandoned_tick'] = $c['last_tick'] ?? ['reason' => 'legacy_abandoned_tick'];
                    $c['last_abandoned_tick']['recovery_reason'] = 'expired_lease_recovered';
                }
                $token = (string) Str::uuid();
                $c['current_part'] = (int) $partId;
                $c['last_tick'] = ['id' => $token, 'tick_started_at' => now()->toIso8601String(), 'tick_finished_at' => null, 'duration_ms' => null, 'requested_items' => 1, 'completed_items' => 0, 'provider_requests' => 0, 'http_status_breakdown' => [], 'retry_count' => 0, 'metrics_complete' => false, 'reason' => 'tick_started'];
                $cache = (($c['pending_read']['part_id'] ?? null) === (int) $partId) ? ($c['pending_read']['cache'] ?? []) : [];
                foreach (($c['catalog_cache'] ?? []) as $key => $entry) {
                    if (($entry['read_at'] ?? 0) > now()->timestamp - 300) {
                        $cache[$key] = $entry;
                    }
                }
                $oldResult = DB::table('allegro_shipping_audit_results')->where('run_id', $id)->where('part_id', $partId)->first();
                if ($oldResult) {
                    foreach ((json_decode($oldResult->payload, true)['offers'] ?? []) as $offer) {
                        if (($offer['http_status'] ?? null) === 404 && isset($offer['marketplace_account_id'],$offer['allegro_offer_id'])) {
                            $key = $offer['marketplace_account_id'].'|/sale/product-offers/'.rawurlencode($offer['allegro_offer_id']);
                            $cache[$key] = ['terminal' => true, 'attempts' => 1, 'read_at' => now()->timestamp, 'result' => ['ok' => false, 'http_status' => 404, 'reason' => 'endpoint_error', 'attempts' => 1]];
                        }
                    }
                }
                $this->save($id, $c, ['lease_token' => $token, 'lease_until' => now()->addSeconds(15)]);

                return ['token' => $token, 'part_id' => (int) $partId, 'read_state' => ['cache' => $cache]];
            });
            if (! $claim) {
                return $this->status($owner, $id, true);
            }
            $started = microtime(true);
            try {
                $report = $this->audit->readTick($claim['part_id'], $claim['read_state']);
                $row = $report['rows'][0] ?? ['part_id' => $claim['part_id'], 'reason_code' => 'part_missing', 'action' => 'BLOCK_MISSING_MAPPING', 'provider_read_unavailable' => false, 'active_offer_count_complete' => false, 'offers' => []];
            } catch (Throwable $exception) {
                DB::transaction(function () use ($owner, $id, $claim, $exception, $started): void {
                    $r = $this->locked($owner, $id);
                    if ($r->lease_token !== $claim['token']) {
                        return;
                    }
                    $c = json_decode($r->checkpoint, true);
                    $c['last_error'] = 'backend_exception';
                    $c['last_backend_error'] = ['class' => $exception::class, 'reason' => 'backend_exception'];
                    $this->finishTick($c, $this->audit->tickMetrics(), $started, 'backend_exception', 0);
                    $this->save($id, $c, ['state' => 'paused', 'lease_token' => null, 'lease_until' => null]);
                });

                return $this->status($owner, $id, true);
            }
            DB::transaction(function () use ($owner, $id, $claim, $row, $report, $started): void {
                $r = $this->locked($owner, $id);
                if ($r->lease_token !== $claim['token']) {
                    return;
                } // stale worker cannot overwrite a newer checkpoint
                $c = json_decode($r->checkpoint, true);
                foreach (($report['tick_state']['cache'] ?? []) as $key => $entry) {
                    if (str_ends_with($key, '|/sale/shipping-rates') && ($entry['terminal'] ?? false) && ($entry['result']['ok'] ?? false) && AllegroShippingCatalog::parse($entry['result']['body']['shippingRates'] ?? null) !== null) {
                        $c['catalog_cache'][$key] = $entry;
                    }
                }
                if ($report['tick_deferred'] ?? false) {
                    $c['pending_read'] = ['part_id' => $claim['part_id'], 'cache' => $report['tick_state']['cache']];
                    $deadlines = collect($report['tick_state']['cache'])->pluck('due')->filter()->sort()->values();
                    if ($deadlines->isNotEmpty()) {
                        $c['wait_until'] = $deadlines->last();
                    }
                    $this->finishTick($c, $report['tick_metrics'], $started, $deadlines->isNotEmpty() ? 'provider_cooldown' : 'read_continuation', 0);
                    $this->save($id, $c, ['lease_token' => null, 'lease_until' => null]);

                    return;
                }
                unset($c['pending_read']);
                $this->finishTick($c, $report['tick_metrics'] ?? [], $started, 'product_completed', 1);
                $old = DB::table('allegro_shipping_audit_results')->where('run_id', $id)->where('part_id', $claim['part_id'])->first();
                $before = $old ? $this->counters(json_decode($old->payload, true)) : array_fill_keys(ShippingRateAuditService::COUNTERS, 0);
                $after = $this->counters($row);
                if ($old) {
                    $this->diagnosticDelta($c, json_decode($old->payload, true), -1);
                }
                $this->diagnosticDelta($c, $row, 1);
                foreach ([[$old ? json_decode($old->payload, true) : [], -1], [$row, 1]] as [$item, $delta]) {
                    if (($item['active_offer_count'] ?? 0) !== 1 || ! ($item['active_offer_count_complete'] ?? false) || ! isset($item['shipping_rate_name'])) {
                        continue;
                    }
                    $key = $item['marketplace_account_id'].':'.$item['shipping_rate_id'].':'.$item['shipping_rate_name'];
                    $c['breakdown'][$key] ??= ['account' => $item['allegro_account_code'], 'shipping_rate_id' => $item['shipping_rate_id'], 'shipping_rate_name' => $item['shipping_rate_name'], 'products_count' => 0];
                    $c['breakdown'][$key]['products_count'] += $delta;
                }
                foreach ($after as $key => $value) {
                    $c['summary'][$key] += $value - $before[$key];
                }
                $failed = (bool) ($row['provider_read_unavailable'] ?? false);
                $deadlines = collect($row['offers'] ?? [])->pluck('retry_not_before')->push($row['catalog_diagnostics']['retry_not_before'] ?? null)->filter()->sort()->values();
                if ($deadlines->isNotEmpty()) {
                    $c['wait_until'] = $deadlines->last();
                }
                DB::table('allegro_shipping_audit_results')->updateOrInsert(['run_id' => $id, 'part_id' => $claim['part_id']],
                    ['payload' => json_encode($row, JSON_THROW_ON_ERROR), 'read_failed' => $failed, 'retry_generation' => $c['retry_generation'], 'retry_not_before' => $deadlines->last()]);
                $c['processed_count'] += (int) ($old === null);
                $c['completed_count'] += (int) (! $failed) - (int) ($old !== null && ! $old->read_failed);
                if ($c['phase'] === 'scan') {
                    $c['cursor'] = $claim['part_id'];
                }
                $c['catalog'] = $report['catalog'] ?? [];
                $c['local_options'] = $report['local_options'] ?? [];
                $c['last_error'] = $failed ? ['reason' => $row['reason_code'] ?? 'provider_read_failed', 'safe_reasons' => collect($row['offers'] ?? [])->pluck('reason_code')->push($row['catalog_diagnostics']['reason'] ?? null)->filter()->unique()->values()->all()] : null;
                if (! $failed) {
                    $c['last_success'] = now()->toIso8601String();
                }
                $c['current_part'] = null;
                $this->save($id, $c, ['lease_token' => null, 'lease_until' => null]);
            });

            return $this->status($owner, $id, true);
        }, true);
    }

    public function results(int $owner, string $id): iterable
    {
        $this->status($owner, $id); // ownership check
        foreach (DB::table('allegro_shipping_audit_results')->where('run_id', $id)->lazyById(200) as $r) {
            yield json_decode($r->payload, true, 512, JSON_THROW_ON_ERROR);
        }
    }

    private function liveLease(object $run): bool
    {
        if (! $run->lease_token || ! $run->lease_until) {
            return false;
        }
        try {
            return Carbon::parse($run->lease_until)->isFuture();
        } catch (Throwable) {
            return false;
        }
    }

    private function finishTick(array &$c, array $metrics, float $started, string $reason, int $completed): void
    {
        $c['last_tick'] = array_replace($c['last_tick'] ?? [], $metrics, ['tick_finished_at' => now()->toIso8601String(), 'duration_ms' => (int) round((microtime(true) - $started) * 1000), 'completed_items' => $completed, 'metrics_complete' => true, 'reason' => $reason]);
        $c['tick_totals'] ??= ['ticks' => 0, 'provider_requests' => 0, 'retry_count' => 0, 'http_status_breakdown' => []];
        $c['tick_totals']['ticks']++;
        foreach (['provider_requests', 'retry_count'] as $k) {
            $c['tick_totals'][$k] += $metrics[$k] ?? 0;
        }
        foreach (($metrics['http_status_breakdown'] ?? []) as $k => $n) {
            $c['tick_totals']['http_status_breakdown'][$k] = ($c['tick_totals']['http_status_breakdown'][$k] ?? 0) + $n;
        }
    }

    private function reason(array $row): string
    {
        $reads = array_merge($row['offers'] ?? [], isset($row['catalog_diagnostics']) ? [$row['catalog_diagnostics']] : []);
        foreach ($reads as $r) {
            if ($r['read_ok'] ?? $r['ok'] ?? false) {
                continue;
            }
            $status = $r['http_status'] ?? null;
            if ($status === 404 && isset($r['allegro_offer_id'])) {
                return 'offer_404';
            }
            if (in_array($status, [401, 403, 429], true)) {
                return [401 => 'unauthorized_401', 403 => 'forbidden_403', 429 => 'provider_429'][$status];
            }
            if (in_array($status, [500, 502, 503, 504], true)) {
                return 'provider_'.$status;
            }
            if (isset($r['transport_reason'])) {
                return $r['transport_reason'];
            }
            if (($r['reason_code'] ?? $r['reason'] ?? null) === 'invalid_response') {
                return 'invalid_response';
            }
        }

        return 'other';
    }

    private function diagnosticDelta(array &$c, array $row, int $delta): void
    {
        $reason = $this->reason($row);
        if ($row['provider_read_unavailable'] ?? false) {
            $c['provider_read_failed_by_reason'][$reason] = ($c['provider_read_failed_by_reason'][$reason] ?? 0) + $delta;
        }
        if (collect($row['offers'] ?? [])->contains('http_status', 404)) {
            $c['provider_offer_not_found_count'] = ($c['provider_offer_not_found_count'] ?? 0) + $delta;
        }
    }

    private function diagnostics(string $id, array &$c): void
    {
        if (isset($c['provider_read_failed_by_reason'])) {
            return;
        }
        $c['provider_read_failed_by_reason'] = array_fill_keys(['offer_404', 'unauthorized_401', 'forbidden_403', 'provider_429', 'provider_500', 'provider_502', 'provider_503', 'provider_504', 'timeout', 'connection_error', 'invalid_response', 'other'], 0);
        $c['provider_offer_not_found_count'] = 0;
        foreach (DB::table('allegro_shipping_audit_results')->where('run_id', $id)->where('read_failed', true)->lazyById(200) as $r) {
            $this->diagnosticDelta($c, json_decode($r->payload, true), 1);
        }
    }

    private function terminal404s(string $id, array &$c): void
    {
        foreach (DB::table('allegro_shipping_audit_results')->where('run_id', $id)->where('read_failed', true)->lazyById(200) as $r) {
            $row = json_decode($r->payload, true);
            if (($row['action'] ?? null) !== 'BLOCK_PROVIDER_READ') {
                continue;
            }
            $unread = collect($row['offers'] ?? [])->filter(fn ($o) => ! ($o['read_ok'] ?? false));
            if ($unread->isEmpty() || $unread->contains(fn ($o) => ($o['http_status'] ?? null) !== 404) || ($row['active_offer_count'] ?? 0) > 0 || isset($row['catalog_diagnostics']) && ! ($row['catalog_diagnostics']['ok'] ?? false)) {
                continue;
            }
            $before = $this->counters($row);
            $this->diagnosticDelta($c, $row, -1);
            foreach ($row['offers'] as &$o) {
                if (($o['http_status'] ?? null) === 404) {
                    $o['read_ok'] = true;
                    $o['reason_code'] = 'provider_offer_not_found';
                    $o['provider_offer_status'] = 'NOT_FOUND';
                }
            }
            unset($o);
            $row['provider_read_unavailable'] = false;
            $row['active_offer_count_complete'] = true;
            $row['reason_code'] = 'provider_offer_not_found';
            $row['action'] = 'NO_ACTIVE_OFFER';
            $after = $this->counters($row);
            foreach ($after as $k => $v) {
                $c['summary'][$k] += $v - $before[$k];
            }
            $this->diagnosticDelta($c, $row, 1);
            $c['completed_count']++;
            DB::table('allegro_shipping_audit_results')->where('id', $r->id)->update(['payload' => json_encode($row, JSON_THROW_ON_ERROR), 'read_failed' => false]);
        }
    }

    public function clientEvent(int $owner, string $id, array $event): array
    {
        return ReadOnlyDatabase::run(function () use ($owner, $id, $event) {
            DB::transaction(function () use ($owner, $id, $event) {
                $r = $this->locked($owner, $id);
                $c = json_decode($r->checkpoint, true);
                $c['last_client_event'] = $event + ['observed_at' => now()->toIso8601String()];
                $this->save($id, $c);
            });

            return $this->status($owner, $id, true);
        }, true);
    }

    private function retryQuery(string $id, array $c)
    {
        return DB::table('allegro_shipping_audit_results')->where('run_id', $id)->where('read_failed', true)->where('retry_generation', '<', $c['retry_generation']);
    }

    private function locked(int $owner, string $id): object
    {
        $r = DB::table('allegro_shipping_audit_runs')->where('id', $id)->where('owner_id', $owner)->lockForUpdate()->first();
        abort_unless($r, 404);

        return $r;
    }

    private function save(string $id, array $checkpoint, array $extra = []): void
    {
        DB::table('allegro_shipping_audit_runs')->where('id', $id)->update($extra + ['checkpoint' => json_encode($checkpoint, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
    }

    private function flags(bool $written): array
    {
        return ['database_writes' => $written, 'technical_report_writes' => $written, 'business_writes' => false,
            'allegro_writes' => false, 'product_writes' => false, 'marketplace_writes' => false, 'oauth_refresh' => false];
    }

    private function counters(array $row): array
    {
        $s = array_fill_keys(ShippingRateAuditService::COUNTERS, 0);
        $s['total_parts'] = 1;
        $n = $row['active_offer_count'] ?? 0;
        if ($n > 1) {
            $s['parts_with_multiple_active_offers'] = 1;
        } elseif ($row['active_offer_count_complete'] ?? false) {
            $s[$n === 1 ? 'parts_with_one_active_offer' : 'parts_without_active_offer'] = 1;
        }
        $s['empty_kurier_allegro'] = (int) (trim((string) ($row['current_allegro_shipping_rate_name'] ?? '')) === '');
        foreach (['already_correct' => 'NO_CHANGE', 'proposed_updates' => 'SET_EMPTY_FIELD', 'current_value_conflicts' => 'BLOCK_CONFLICT', 'missing_mapping' => 'BLOCK_MISSING_MAPPING'] as $k => $v) {
            $s[$k] = (int) (($row['action'] ?? null) === $v);
        }
        $s['provider_read_failed'] = (int) ($row['provider_read_unavailable'] ?? false);
        $s['unknown_shipping_rate'] = (int) (($row['reason_code'] ?? null) === 'unknown_shipping_rate');

        return $s;
    }
}
