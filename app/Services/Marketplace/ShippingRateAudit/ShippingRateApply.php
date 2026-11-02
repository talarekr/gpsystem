<?php

namespace App\Services\Marketplace\ShippingRateAudit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Frozen local evidence only. No API client, Eloquent model or marketplace writer. */
final class ShippingRateApply
{
    public const RUN_ID = 'e2229ef9-4db3-4c85-ab01-e7931e705e7b';

    public const EXPECTED_COUNT = 4609;

    public const CONFIRM = 'APPLY-ALLEGRO-SHIPPING-RATE-BACKFILL';

    public function prepare(int $owner, string $runId): array
    {
        return ReadOnlyDatabase::applyWrites(fn () => DB::transaction(function () use ($owner, $runId): array {
            $source = $this->source($owner, $runId);
            $existing = DB::table('allegro_shipping_apply_plans')->where('run_id', $runId)->where('owner_id', $owner)->first();
            // Never replace a prior plan/ledger: even a later manual clearing must not reapply it.
            if ($existing) {
                if (! hash_equals($existing->source_digest, $source['source_digest'])) {
                    return $this->changed($source, 'source_changed');
                }

                return $this->view($existing, $source);
            }
            $id = (string) Str::uuid();
            $entries = json_encode($source['entries'], JSON_THROW_ON_ERROR);
            $digest = $this->digest($id, $owner, $source['source_digest'], $entries);
            $plan = (object) ['id' => $id, 'run_id' => $runId, 'owner_id' => $owner,
                'source_digest' => $source['source_digest'], 'plan_digest' => $digest,
                'eligible_count' => count($source['entries']), 'entries' => $entries, 'report' => null, 'created_at' => now()];
            DB::table('allegro_shipping_apply_plans')->insert((array) $plan);

            return $this->view($plan, $source);
        }));
    }

    public function apply(int $owner, string $runId, string $planId, string $digest, string $confirm): array
    {
        abort_unless(hash_equals(self::CONFIRM, $confirm), 422, 'confirmation_required');
        $plannedIds = [];
        try {
            return ReadOnlyDatabase::applyWrites(function () use ($owner, $runId, $planId, $digest, &$plannedIds): array {
                return DB::transaction(function () use ($owner, $runId, $planId, $digest, &$plannedIds): array {
                    $source = $this->source($owner, $runId); // locks run and catalog synchronization before plan/Part locks
                    $plan = DB::table('allegro_shipping_apply_plans')->where('id', $planId)->where('run_id', $runId)->where('owner_id', $owner)->lockForUpdate()->first();
                    abort_unless($plan, 404);
                    abort_unless(hash_equals($plan->plan_digest, $digest)
                        && hash_equals($digest, $this->digest($plan->id, $owner, $plan->source_digest, $plan->entries)), 409, 'plan_digest_mismatch');
                    if (! hash_equals($plan->source_digest, $source['source_digest'])) {
                        return $this->changed($source, 'source_changed');
                    }
                    if ((int) $plan->eligible_count !== self::EXPECTED_COUNT || count($source['entries']) !== self::EXPECTED_COUNT) {
                        return $this->changed($source, 'eligible_count_mismatch');
                    }
                    abort_unless($plan->entries === json_encode($source['entries'], JSON_THROW_ON_ERROR), 409, 'plan_entries_changed');
                    $plannedIds = array_column($source['entries'], 'part_id');
                    $previous = json_decode($plan->report ?? '{}', true, 512, JSON_THROW_ON_ERROR);
                    $applied = array_fill_keys($previous['applied_ids'] ?? [], true);
                    $report = ['planned' => self::EXPECTED_COUNT, 'updated' => 0, 'already_applied' => 0,
                        'skipped_concurrent_change' => 0, 'blocked' => $source['total'] - self::EXPECTED_COUNT, 'failed' => 0, 'examples' => ['blocked' => array_slice(array_merge(...array_column($source['excluded'], 'examples')), 0, 20)]];
                    foreach ($source['entries'] as $entry) {
                        $id = $entry['part_id'];
                        if (isset($applied[$id])) {
                            $outcome = 'already_applied';
                        } else {
                            $part = DB::table('parts')->where('id', $id)->lockForUpdate()->first(['allegro_shipping_rate_name']);
                            if (! $part || ! $this->blank($part->allegro_shipping_rate_name)) {
                                $outcome = 'skipped_concurrent_change';
                            } else {
                                // Compare-and-set the exact observed blank, one column only; no updated_at or observers.
                                $query = DB::table('parts')->where('id', $id);
                                $part->allegro_shipping_rate_name === null
                                    ? $query->whereNull('allegro_shipping_rate_name')
                                    : $query->where('allegro_shipping_rate_name', $part->allegro_shipping_rate_name);
                                $changed = $query->update(['allegro_shipping_rate_name' => $entry['name']]);
                                $outcome = $changed === 1 ? 'updated' : 'skipped_concurrent_change';
                                if ($changed === 1) {
                                    $applied[$id] = true;
                                }
                            }
                        }
                        $report[$outcome]++;
                        if (count($report['examples'][$outcome] ?? []) < 20) {
                            $report['examples'][$outcome][] = $id;
                        }
                    }
                    $report['applied_ids'] = array_keys($applied);
                    DB::table('allegro_shipping_apply_plans')->where('id', $planId)->update(['report' => json_encode($report, JSON_THROW_ON_ERROR)]);
                    unset($report['applied_ids']);

                    return $this->flags(true) + ['ok' => true, 'run_id' => $runId, 'plan_id' => $planId, 'plan_digest' => $digest, 'report' => $report];
                });
            });
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (Throwable) {
            // The whole transaction (including ledger) rolls back. Never return raw SQL/exception text.
            return $this->flags(false) + ['ok' => false, 'reason_code' => 'apply_transaction_failed', 'run_id' => $runId,
                'report' => ['planned' => self::EXPECTED_COUNT, 'updated' => 0, 'already_applied' => 0,
                    'skipped_concurrent_change' => 0, 'blocked' => 7318 - self::EXPECTED_COUNT, 'failed' => self::EXPECTED_COUNT, 'examples' => ['failed' => array_slice($plannedIds, 0, 20)]]];
        }
    }

    private function source(int $owner, string $runId): array
    {
        abort_unless($runId === self::RUN_ID, 422, 'run_not_allowed');
        $run = DB::table('allegro_shipping_audit_runs')->where('id', $runId)->where('owner_id', $owner)->lockForUpdate()->first();
        abort_unless($run, 404);
        $c = json_decode($run->checkpoint, true, 512, JSON_THROW_ON_ERROR);
        abort_unless($run->state === 'completed' && ($c['scan_complete'] ?? false) === true
            && ($c['phase'] ?? null) === 'scan' && ($c['processed_count'] ?? null) === 7318 && ($c['total'] ?? null) === 7318
            && $run->lease_token === null, 409, 'completed_scan_required');
        $account = DB::table('marketplace_accounts')->where('id', 2)->where('marketplace', 'allegro')->where('code', 'allegro_main')->first(['id', 'code']);
        abort_unless($account, 409, 'account_mismatch');
        $sync = DB::table('allegro_shipping_catalog_syncs')->where('marketplace_account_id', 2)->lockForUpdate()->first();
        abort_unless($sync && $sync->synced_at, 409, 'catalog_not_synced');
        $rates = DB::table('allegro_shipping_rates')->where('marketplace_account_id', 2)->orderBy('shipping_rate_id')->get(['shipping_rate_id', 'shipping_rate_name', 'active', 'synced_at'])->keyBy('shipping_rate_id');
        $hash = hash_init('sha256');
        hash_update($hash, json_encode([$runId, $owner, $run->checkpoint, $sync, $rates], JSON_THROW_ON_ERROR));
        $entries = [];
        $excluded = [];
        $total = 0;
        foreach (DB::table('allegro_shipping_audit_results')->where('run_id', $runId)->orderBy('part_id')->cursor() as $record) {
            $total++;
            hash_update($hash, json_encode([(int) $record->part_id, (bool) $record->read_failed, $record->payload], JSON_THROW_ON_ERROR));
            $row = json_decode($record->payload, true, 512, JSON_THROW_ON_ERROR);
            if ($this->eligible($row, $record, $rates->all())) {
                $entries[] = ['part_id' => (int) $record->part_id, 'shipping_rate_id' => $row['shipping_rate_id'], 'name' => $row['proposed_allegro_shipping_rate_name']];
            } else {
                $reason = $row['action'] ?? 'invalid_result';
                $excluded[$reason]['count'] = ($excluded[$reason]['count'] ?? 0) + 1;
                if (count($excluded[$reason]['examples'] ?? []) < 20) {
                    $excluded[$reason]['examples'][] = (int) $record->part_id;
                }
            }
        }
        abort_unless($total === 7318, 409, 'result_coverage_mismatch');

        return ['source_digest' => hash_final($hash), 'entries' => $entries, 'total' => $total, 'summary' => $c['summary'], 'excluded' => $excluded];
    }

    private function eligible(array $r, object $record, array $rates): bool
    {
        $name = $r['proposed_allegro_shipping_rate_name'] ?? null;
        $rate = $rates[$r['shipping_rate_id'] ?? ''] ?? null;
        $active = array_values(array_filter($r['offers'] ?? [], static fn ($o) => ($o['provider_offer_status'] ?? null) === 'ACTIVE'));
        $offer = $active[0] ?? [];

        return ! $record->read_failed && ($r['part_id'] ?? null) === (int) $record->part_id
            && ($r['action'] ?? null) === 'SET_EMPTY_FIELD' && ($r['reason_code'] ?? null) === 'exact_mapping'
            && ($r['active_offer_count'] ?? null) === 1 && ($r['active_offer_count_complete'] ?? false) === true
            && ($r['provider_read_unavailable'] ?? true) === false && array_key_exists('current_allegro_shipping_rate_name', $r) && $this->blank($r['current_allegro_shipping_rate_name'] ?? null)
            && ($r['marketplace_account_id'] ?? null) === 2 && ($r['allegro_account_code'] ?? null) === 'allegro_main'
            && ($r['provider_offer_status'] ?? null) === 'ACTIVE' && count($active) === 1
            && ($offer['read_ok'] ?? false) === true && ($offer['http_status'] ?? null) === 200
            && ($offer['marketplace_account_id'] ?? null) === 2 && ($offer['allegro_account_code'] ?? null) === 'allegro_main'
            && is_string($offer['allegro_offer_id'] ?? null) && trim($offer['allegro_offer_id']) !== ''
            && $offer['allegro_offer_id'] === ($r['allegro_offer_id'] ?? null)
            && ($r['catalog_diagnostics']['ok'] ?? false) === true && ($r['catalog_diagnostics']['http_status'] ?? null) === 200
            && is_string($r['shipping_rate_id'] ?? null) && trim($r['shipping_rate_id']) !== ''
            && is_string($name) && trim($name) !== '' && mb_strlen($name) <= 255
            && $name === ($r['shipping_rate_name'] ?? null) && $rate && (bool) $rate->active && $rate->synced_at !== null && $rate->shipping_rate_name === $name;
    }

    private function view(object $plan, array $source): array
    {
        $eligible = (int) $plan->eligible_count;
        $report = json_decode($plan->report ?? 'null', true);
        if ($report) {
            unset($report['applied_ids']);
        }

        return $this->flags(false) + ['ok' => true, 'run_id' => $plan->run_id, 'plan_id' => $plan->id,
            'eligible_count' => $eligible, 'expected_count' => self::EXPECTED_COUNT, 'count_difference' => $eligible - self::EXPECTED_COUNT,
            'apply_allowed' => $eligible === self::EXPECTED_COUNT, 'plan_digest' => $plan->plan_digest,
            'summary' => $source['summary'], 'excluded' => $source['excluded'], 'blocked' => $source['total'] - $eligible, 'last_report' => $report];
    }

    private function changed(array $source, string $reason): array
    {
        return $this->flags(false) + ['ok' => false, 'reason_code' => $reason, 'run_id' => self::RUN_ID,
            'apply_allowed' => false, 'eligible_count' => count($source['entries']), 'expected_count' => self::EXPECTED_COUNT,
            'count_difference' => count($source['entries']) - self::EXPECTED_COUNT];
    }

    private function digest(string $id, int $owner, string $source, string $entries): string
    {
        return hash_hmac('sha256', json_encode([$id, $owner, self::RUN_ID, self::EXPECTED_COUNT, $source, $entries], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function flags(bool $applied): array
    {
        return ['database_writes' => true, 'product_field_writes' => $applied, 'product_writes' => $applied,
            'allegro_writes' => false, 'marketplace_writes' => false, 'provider_calls' => false];
    }
}
