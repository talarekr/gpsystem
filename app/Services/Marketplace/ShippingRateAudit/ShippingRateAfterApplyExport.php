<?php

namespace App\Services\Marketplace\ShippingRateAudit;

use Illuminate\Support\Facades\DB;

/** Current Part values + frozen provenance. No writes, models, provider or APPLY dependency. */
final class ShippingRateAfterApplyExport
{
    public const RUN_ID = 'e2229ef9-4db3-4c85-ab01-e7931e705e7b';

    public const PLAN_ID = 'fa67d424-d251-4dc6-971e-b4f8ab26d5ba';

    public const FIELDS = ['part_id', 'sku', 'part_number', 'title', 'allegro_offer_id', 'allegro_offer_status',
        'shipping_rate_id_from_audit', 'shipping_rate_name_from_allegro', 'current_allegro_shipping_rate_name',
        'backfill_status', 'original_audit_action', 'original_reason_code', 'applied_by_plan', 'part_exists', 'current_value_matches_audit'];

    private const COUNTERS = ['FILLED_BY_APPLY' => 'filled_by_apply', 'ALREADY_FILLED' => 'already_filled',
        'NOT_FILLED_NO_ACTIVE_OFFER' => 'not_filled_no_active_offer', 'NOT_FILLED_PROVIDER_404' => 'not_filled_provider_404',
        'NOT_FILLED_CONFLICT' => 'not_filled_conflict', 'NOT_FILLED_MISSING_MAPPING' => 'not_filled_missing_mapping', 'NOT_FILLED_OTHER' => 'not_filled_other'];

    public function snapshot(int $owner): array
    {
        return ReadOnlyDatabase::run(fn () => DB::transaction(function () use ($owner): array {
            $run = DB::table('allegro_shipping_audit_runs')->where('id', self::RUN_ID)->where('owner_id', $owner)->first();
            abort_unless($run, 404);
            $checkpoint = json_decode($run->checkpoint, true, 512, JSON_THROW_ON_ERROR);
            abort_unless($run->state === 'completed' && ($checkpoint['scan_complete'] ?? false) === true
                && ($checkpoint['processed_count'] ?? 0) === 7318 && ($checkpoint['total'] ?? 0) === 7318 && $run->lease_token === null, 409, 'completed_run_required');
            $plan = DB::table('allegro_shipping_apply_plans')->where('id', self::PLAN_ID)->where('run_id', self::RUN_ID)->where('owner_id', $owner)->first();
            abort_unless($plan && $plan->report !== null, 409, 'completed_apply_plan_required');
            $report = json_decode($plan->report, true, 512, JSON_THROW_ON_ERROR);
            $ids = $report['applied_ids'] ?? null;
            abort_unless(is_array($ids) && array_is_list($ids) && count($ids) === 4609
                && count(array_unique($ids)) === 4609 && collect($ids)->every(fn ($id) => is_int($id) && $id > 0), 409, 'invalid_applied_ledger');
            $applied = array_fill_keys($ids, true);
            $entries = json_decode($plan->entries, true, 512, JSON_THROW_ON_ERROR);
            abort_unless(count(array_intersect($ids, array_column($entries, 'part_id'))) === 4609, 409, 'ledger_plan_mismatch');
            // Results define the original scope. LEFT JOIN retains even a subsequently deleted Part.
            $records = DB::table('allegro_shipping_audit_results as r')->leftJoin('parts as p', 'p.id', '=', 'r.part_id')
                ->where('r.run_id', self::RUN_ID)->orderBy('r.part_id')
                ->get(['r.part_id', 'r.payload', 'p.id as current_part_id', 'p.sku', 'p.part_number', 'p.name', 'p.allegro_shipping_rate_name']);
            abort_unless($records->count() === 7318 && count(array_intersect($ids, $records->pluck('part_id')->all())) === 4609, 409, 'audit_coverage_mismatch');
            $summary = array_fill_keys(['total', 'filled_total', 'not_filled_total', ...array_values(self::COUNTERS)], 0);
            $rows = [];
            foreach ($records as $record) {
                $audit = json_decode($record->payload, true, 512, JSON_THROW_ON_ERROR);
                $wasApplied = isset($applied[$record->part_id]);
                $status = $this->status($audit, $record, $wasApplied);
                $name = $audit['shipping_rate_name'] ?? null;
                $rows[] = ['part_id' => (int) $record->part_id, 'sku' => $record->sku, 'part_number' => $record->part_number, 'title' => $record->name,
                    'allegro_offer_id' => $audit['allegro_offer_id'] ?? null, 'allegro_offer_status' => $audit['provider_offer_status'] ?? ($status === 'NOT_FILLED_PROVIDER_404' ? 'NOT_FOUND_404' : null),
                    'shipping_rate_id_from_audit' => $audit['shipping_rate_id'] ?? null, 'shipping_rate_name_from_allegro' => $name,
                    'current_allegro_shipping_rate_name' => $record->allegro_shipping_rate_name, 'backfill_status' => $status,
                    'original_audit_action' => $audit['action'] ?? null, 'original_reason_code' => $audit['reason_code'] ?? null,
                    'applied_by_plan' => (int) $wasApplied, 'part_exists' => (int) ($record->current_part_id !== null),
                    'current_value_matches_audit' => (int) ($name !== null && $name === $record->allegro_shipping_rate_name)];
                $summary['total']++;
                $summary[self::COUNTERS[$status]]++;
                $summary[str_starts_with($status, 'NOT_FILLED_') ? 'not_filled_total' : 'filled_total']++;
            }

            return ['run_id' => self::RUN_ID, 'plan_id' => self::PLAN_ID, 'generated_at' => now()->toIso8601String(),
                'summary' => $summary, 'rows' => $rows, 'database_writes' => false, 'product_writes' => false,
                'marketplace_writes' => false, 'allegro_writes' => false, 'provider_calls' => false];
        }));
    }

    private function status(array $audit, object $part, bool $applied): string
    {
        if ($part->current_part_id === null) {
            return 'NOT_FILLED_OTHER';
        }
        $filled = $part->current_part_id !== null && trim((string) $part->allegro_shipping_rate_name) !== '';
        if ($applied) {
            return $filled ? 'FILLED_BY_APPLY' : 'NOT_FILLED_OTHER';
        }
        if (($audit['action'] ?? '') === 'NO_CHANGE' && ($audit['reason_code'] ?? '') === 'already_correct') {
            return $filled ? 'ALREADY_FILLED' : 'NOT_FILLED_OTHER';
        }
        if (($audit['action'] ?? '') === 'BLOCK_PROVIDER_READ') {
            $offers = $audit['offers'] ?? [];
            $failed = array_filter($offers, fn ($o) => ! ($o['read_ok'] ?? false));
            $only404 = $failed !== [] && count(array_filter($failed, fn ($o) => ($o['http_status'] ?? null) === 404 || ($o['reason_code'] ?? '') === 'offer_404')) === count($failed);

            return $only404 || ($audit['reason_code'] ?? '') === 'offer_404' ? 'NOT_FILLED_PROVIDER_404' : 'NOT_FILLED_OTHER';
        }

        return match ($audit['action'] ?? '') {
            'NO_ACTIVE_OFFER' => 'NOT_FILLED_NO_ACTIVE_OFFER',
            'BLOCK_CONFLICT' => 'NOT_FILLED_CONFLICT',
            'BLOCK_MISSING_MAPPING' => 'NOT_FILLED_MISSING_MAPPING',
            default => 'NOT_FILLED_OTHER',
        };
    }

    /** Mitigate spreadsheet formulas while preserving BOM, Unicode, delimiters and multiline values. */
    public static function csvCell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $value) ? "'".$value : $value;
    }
}
