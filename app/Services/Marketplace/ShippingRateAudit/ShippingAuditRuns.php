<?php

namespace App\Services\Marketplace\ShippingRateAudit;

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
            $c['state'] = $run->state;
            $c['busy'] = $run->lease_token !== null && $run->lease_until > now()->toDateTimeString();
            $c['lease_until'] = $run->lease_until;
            $c['wait_seconds'] = isset($c['wait_until']) ? max(0, (int) now()->diffInSeconds(Carbon::parse($c['wait_until']), false)) : 0;
            $c['remaining'] = ($c['scan_complete'] ?? false) ? 0 : max(0, $c['total'] - $c['processed_count']);
            $c['coverage_changed'] = ($c['scan_complete'] ?? false) && $c['processed_count'] !== $c['total'];
            $c['provider_read_failed'] = DB::table('allegro_shipping_audit_results')->where('run_id', $run->id)->where('read_failed', true)->count();
            $c['resolved'] = $c['completed_count'];
            $c['next_part_id'] = $c['phase'] === 'retry' ? $this->retryQuery($run->id, $c)->orderBy('part_id')->value('part_id') : DB::table('parts')->where('id', '>', $c['cursor'])->where('id', '<=', $c['max_part_id'])->orderBy('id')->value('id');
            $c['retry_remaining'] = $c['phase'] === 'retry' ? $this->retryQuery($run->id, $c)->count() : 0;
            $c['failed_ids'] = DB::table('allegro_shipping_audit_results')->where('run_id', $run->id)->where('read_failed', true)->orderBy('part_id')->pluck('part_id')->all();
            $c['preview'] = DB::table('allegro_shipping_audit_results')->where('run_id', $run->id)->orderByDesc('part_id')->limit(20)->get(['payload'])->map(fn ($r) => json_decode($r->payload, true))->all();

            return $this->flags($written) + ['run' => $c];
        });
    }

    public function control(int $owner, string $id, string $action): array
    {
        return ReadOnlyDatabase::run(function () use ($owner, $id, $action): array {
            DB::transaction(function () use ($owner, $id, $action): void {
                $r = $this->locked($owner, $id);
                $c = json_decode($r->checkpoint, true);
                $state = match ($action) {
                    'resume', 'retry' => 'running', 'pause' => 'paused', 'stop' => 'stopped'
                };
                if ($action === 'retry') {
                    abort_if($r->lease_token && $r->lease_until > now()->toDateTimeString(), 409, 'Batch still running.');
                    abort_unless(($c['scan_complete'] ?? false) && $r->state !== 'running', 409, 'Finish scan before failed-only retry.');
                    $c['retry_history'][] = ['started_at' => now()->toIso8601String(), 'failed_before' => $c['summary']['provider_read_failed']];
                    $c['phase'] = 'retry';
                    $c['retry_generation']++;
                } elseif ($action === 'resume' && $r->state === 'completed') {
                    $state = 'completed';
                }
                $this->save($id, $c, ['state' => $state]);
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
                if ($r->state !== 'running' || ($r->lease_token && $r->lease_until > now()->toDateTimeString())) {
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
                $token = (string) Str::uuid();
                $c['current_part'] = (int) $partId;
                $this->save($id, $c, ['lease_token' => $token, 'lease_until' => now()->addMinutes(3)]);

                return ['token' => $token, 'part_id' => (int) $partId];
            });
            if (! $claim) {
                return $this->status($owner, $id, true);
            }
            try {
                $report = $this->audit->readPart($claim['part_id']);
                $row = $report['rows'][0] ?? ['part_id' => $claim['part_id'], 'reason_code' => 'part_missing', 'action' => 'BLOCK_MISSING_MAPPING', 'provider_read_unavailable' => false, 'active_offer_count_complete' => false, 'offers' => []];
            } catch (Throwable) {
                DB::transaction(function () use ($owner, $id, $claim): void {
                    $r = $this->locked($owner, $id);
                    if ($r->lease_token !== $claim['token']) {
                        return;
                    }
                    $c = json_decode($r->checkpoint, true);
                    $c['last_error'] = 'batch_failed';
                    $this->save($id, $c, ['state' => 'paused', 'lease_token' => null, 'lease_until' => null]);
                });

                return $this->status($owner, $id, true);
            }
            DB::transaction(function () use ($owner, $id, $claim, $row, $report): void {
                $r = $this->locked($owner, $id);
                if ($r->lease_token !== $claim['token']) {
                    return;
                } // stale worker cannot overwrite a newer checkpoint
                $c = json_decode($r->checkpoint, true);
                $old = DB::table('allegro_shipping_audit_results')->where('run_id', $id)->where('part_id', $claim['part_id'])->first();
                $before = $old ? $this->counters(json_decode($old->payload, true)) : array_fill_keys(ShippingRateAuditService::COUNTERS, 0);
                $after = $this->counters($row);
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
