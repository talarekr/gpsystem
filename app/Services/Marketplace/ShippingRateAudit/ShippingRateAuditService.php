<?php

namespace App\Services\Marketplace\ShippingRateAudit;

use App\Services\Marketplace\AllegroSalesSettingsResolver;
use Illuminate\Support\Facades\DB;

final class ShippingRateAuditService
{
    public const WRITES = ['database_writes' => false, 'allegro_writes' => false, 'marketplace_writes' => false, 'product_writes' => false];

    public const COUNTERS = ['total_parts', 'parts_with_one_active_offer', 'parts_without_active_offer', 'parts_with_multiple_active_offers', 'empty_kurier_allegro', 'already_correct', 'proposed_updates', 'current_value_conflicts', 'missing_mapping', 'unknown_shipping_rate', 'provider_read_failed'];

    public function __construct(private AllegroShippingReadClient $client) {}

    public function run(int $after = 0, ?int $through = null, bool $catalogOnly = false): array
    {
        return ReadOnlyDatabase::run(fn () => $this->read($after, $through, $catalogOnly));
    }

    public function readPart(int $partId): array
    {
        return $this->client->withinBudget(25, fn () => $this->run($partId - 1, $partId));
    }

    private function read(int $after, ?int $through, bool $catalogOnly): array
    {
        $through ??= (int) DB::table('parts')->max('id');
        $accounts = DB::table('marketplace_accounts')->where('marketplace', 'allegro')->orderBy('id')->get()->keyBy('id');
        $catalog = [];
        $indexes = [];
        foreach ($accounts as $account) {
            $result = $this->client->get($account, '/sale/shipping-rates');
            $rates = $result['body']['shippingRates'] ?? null;
            $ok = ($result['ok'] ?? false) && is_array($rates) && array_is_list($rates);
            $index = [];
            if ($ok) {
                foreach ($rates as $rate) {
                    if (! is_array($rate) || ! is_string($rate['id'] ?? null) || $rate['id'] === '' || ! is_string($rate['name'] ?? null) || isset($index[$rate['id']])) {
                        $ok = false;
                        break;
                    }
                    $index[$rate['id']] = $rate['name'];
                }
            }
            if (! $ok && ($result['ok'] ?? false)) {
                $result['ok'] = false;
                $result['reason'] = 'invalid_response';
            }
            $indexes[$account->id] = ['ok' => $ok, 'rates' => $ok ? $index : [], 'reason' => 'provider_read_failed', 'diagnostics' => array_diff_key($result, ['body' => true])];
            $catalog[] = ['marketplace_account_id' => $account->id, 'allegro_account_code' => $account->code,
                'ok' => $ok, 'reason_code' => $ok ? null : ($result['reason'] ?? 'invalid_response'),
                'provider_read_unavailable' => ! $ok,
                'diagnostics' => array_diff_key($result, ['body' => true]),
                'http_status' => $result['http_status'] ?? null,
                'shipping_rates' => $ok ? array_map(fn ($id, $name) => ['shipping_rate_id' => (string) $id, 'shipping_rate_name' => $name, 'local_option' => array_key_exists($name, AllegroSalesSettingsResolver::SHIPPING_RATE_OPTIONS) ? $name : null], array_keys($index), array_values($index)) : []];
        }
        $rows = [];
        $summary = array_fill_keys(self::COUNTERS, 0);
        $breakdown = [];
        // Query Builder avoids Eloquent retrieval observers and all Part hooks.
        $parts = $catalogOnly ? collect() : DB::table('parts')->where('id', '>', $after)->where('id', '<=', $through)->orderBy('id')->limit(5)->get(['id', 'sku', 'part_number', 'allegro_shipping_rate_name']);
        $listings = DB::table('marketplace_listings')->whereIn('part_id', $parts->pluck('id'))
            ->where(function ($query) use ($accounts) {
                $query->whereIn('marketplace', ['allegro', 'allegro_main'])
                    ->orWhereIn('marketplace', $accounts->pluck('code'))
                    ->orWhereIn('marketplace_account_id', $accounts->keys());
            })->orderBy('id')->get()->groupBy('part_id');
        $offerCache = [];
        foreach ($parts as $part) {
            $row = ['part_id' => $part->id, 'sku' => $part->sku, 'part_number' => $part->part_number,
                'allegro_offer_id' => null, 'marketplace_account_id' => null, 'allegro_account_code' => null,
                'provider_offer_status' => null, 'shipping_rate_id' => null, 'shipping_rate_name' => null,
                'current_allegro_shipping_rate_name' => $part->allegro_shipping_rate_name,
                'proposed_allegro_shipping_rate_name' => null, 'reason_code' => null, 'action' => null,
                'provider_read_unavailable' => false, 'offers' => []];
            $active = [];
            $errors = [];
            $sharedOffer = false;
            $partListings = $listings->get($part->id, collect());
            foreach ($partListings as $listing) {
                $account = $accounts->get($listing->marketplace_account_id);
                $offerId = trim((string) $listing->external_offer_id);
                if ($partListings->count() === 1) {
                    $row['allegro_offer_id'] = $offerId ?: null;
                    $row['marketplace_account_id'] = $listing->marketplace_account_id;
                    $row['allegro_account_code'] = $account?->code;
                }
                if (! $account || ! in_array($listing->marketplace, ['allegro', $account->code], true)) {
                    $row['offers'][] = ['listing_id' => $listing->id, 'allegro_offer_id' => $offerId ?: null, 'marketplace_account_id' => $listing->marketplace_account_id, 'allegro_account_code' => $account?->code, 'marketplace' => $listing->marketplace, 'provider_offer_status' => null, 'read_ok' => false, 'reason_code' => 'account_unavailable'];
                    $errors[] = 'account_unavailable';

                    continue;
                }
                if ($offerId === '') {
                    $row['offers'][] = ['listing_id' => $listing->id, 'allegro_offer_id' => null, 'marketplace_account_id' => $account->id, 'allegro_account_code' => $account->code, 'marketplace' => $listing->marketplace, 'provider_offer_status' => null, 'read_ok' => false, 'reason_code' => 'missing_mapping'];
                    $errors[] = 'missing_mapping';

                    continue;
                }
                // Aliased marketplace rows can bypass the original (marketplace, offer_id) unique index.
                $sharedOffer = $sharedOffer || DB::table('marketplace_listings')
                    ->where('marketplace_account_id', $account->id)->where('external_offer_id', $offerId)
                    ->whereNotNull('part_id')->where('part_id', '<>', $part->id)->exists();
                $key = $account->id.':'.$offerId;
                $response = $offerCache[$key] ??= $this->client->get($account, '/sale/product-offers/'.rawurlencode($offerId));
                $body = $response['body'] ?? [];
                $status = $body['publication']['status'] ?? null;
                $valid = ($response['ok'] ?? false) && is_string($status) && in_array($status, ['ACTIVE', 'ENDED', 'INACTIVE', 'ACTIVATING'], true) && (string) ($body['id'] ?? '') === $offerId;
                $detail = ['listing_id' => $listing->id, 'allegro_offer_id' => $offerId, 'marketplace_account_id' => $account->id, 'allegro_account_code' => $account->code, 'marketplace' => $listing->marketplace, 'provider_offer_status' => $valid ? $status : null, 'read_ok' => $valid, 'reason_code' => $valid ? null : ($response['reason'] ?? 'invalid_response'), 'http_status' => $response['http_status'] ?? null, 'retry_not_before' => $response['retry_not_before'] ?? null, 'attempts' => $response['attempts'] ?? 1];
                $row['offers'][] = $detail;
                if ($partListings->count() === 1 && $valid) {
                    $row['provider_offer_status'] = $status;
                    $row['shipping_rate_id'] = $body['delivery']['shippingRates']['id'] ?? null;
                }
                if (! $valid) {
                    $errors[] = $response['reason'] ?? 'provider_read_failed';

                    continue;
                }
                if ($status === 'ACTIVE' && ($body['archived'] ?? false) !== true) {
                    $active[] = $detail + ['shipping_rate_id' => $body['delivery']['shippingRates']['id'] ?? null];
                }
            }
            $row['active_offer_count'] = count($active);
            $row['active_offer_count_complete'] = $errors === [];
            if ($sharedOffer || count($active) > 1) {
                $this->classify($row, 'ambiguous_allegro_offer', 'BLOCK_AMBIGUOUS_OFFER');
            } elseif ($errors !== []) {
                $reason = in_array('account_unavailable', $errors, true) ? 'account_unavailable' : (in_array('missing_mapping', $errors, true) ? 'missing_mapping' : 'provider_read_failed');
                $this->classify($row, $reason, $reason === 'missing_mapping' ? 'BLOCK_MISSING_MAPPING' : 'BLOCK_PROVIDER_READ');
            } elseif ($active === []) {
                $this->classify($row, 'no_active_allegro_offer', 'NO_ACTIVE_OFFER');
            } else {
                foreach (['allegro_offer_id', 'marketplace_account_id', 'allegro_account_code', 'provider_offer_status', 'shipping_rate_id'] as $field) {
                    $row[$field] = $active[0][$field];
                }
                $rateId = $row['shipping_rate_id'];
                $rates = $indexes[$row['marketplace_account_id']];
                $row['catalog_diagnostics'] = $rates['diagnostics'];
                if (! $rates['ok']) {
                    $this->classify($row, $rates['reason'], 'BLOCK_PROVIDER_READ');
                } elseif (! is_string($rateId) || $rateId === '') {
                    $this->classify($row, 'missing_shipping_rate', 'BLOCK_MISSING_MAPPING');
                } elseif (! array_key_exists($rateId, $rates['rates'])) {
                    $this->classify($row, 'unknown_shipping_rate', 'BLOCK_MISSING_MAPPING');
                } else {
                    $name = $row['shipping_rate_name'] = $rates['rates'][$rateId];
                    if (! array_key_exists($name, AllegroSalesSettingsResolver::SHIPPING_RATE_OPTIONS)) {
                        $this->classify($row, 'missing_local_option', 'BLOCK_MISSING_MAPPING');
                    } else {
                        $row['proposed_allegro_shipping_rate_name'] = $name;
                        $current = $part->allegro_shipping_rate_name;
                        if ($current === $name) {
                            $this->classify($row, 'already_correct', 'NO_CHANGE');
                        } elseif ($current === null || trim($current) === '') {
                            $this->classify($row, 'exact_mapping', 'SET_EMPTY_FIELD');
                        } else {
                            $this->classify($row, 'current_value_conflict', 'BLOCK_CONFLICT');
                        }
                    }
                    $group = $row['marketplace_account_id'].':'.$rateId;
                    $breakdown[$group] ??= ['marketplace_account_id' => $row['marketplace_account_id'], 'account' => $row['allegro_account_code'], 'shipping_rate_id' => $rateId, 'shipping_rate_name' => $name, 'products_count' => 0];
                    $breakdown[$group]['products_count']++;
                }
            }
            $row['provider_read_unavailable'] = $row['action'] === 'BLOCK_PROVIDER_READ' || collect($row['offers'])->contains(fn ($offer) => ! ($offer['read_ok'] ?? false) && ($offer['reason_code'] ?? null) !== 'missing_mapping');
            $summary['total_parts']++;
            if (count($active) > 1) {
                $summary['parts_with_multiple_active_offers']++;
            } elseif ($row['active_offer_count_complete']) {
                $summary[count($active) === 0 ? 'parts_without_active_offer' : (count($active) === 1 ? 'parts_with_one_active_offer' : 'parts_with_multiple_active_offers')]++;
            }
            $summary['empty_kurier_allegro'] += (int) ($part->allegro_shipping_rate_name === null || trim($part->allegro_shipping_rate_name) === '');
            foreach (['already_correct' => 'NO_CHANGE', 'proposed_updates' => 'SET_EMPTY_FIELD', 'current_value_conflicts' => 'BLOCK_CONFLICT', 'missing_mapping' => 'BLOCK_MISSING_MAPPING'] as $counter => $action) {
                $summary[$counter] += (int) ($row['action'] === $action);
            }
            $summary['provider_read_failed'] += (int) $row['provider_read_unavailable'];
            $summary['unknown_shipping_rate'] += (int) ($row['reason_code'] === 'unknown_shipping_rate');
            $row['observed_at'] = now()->toIso8601String();
            $rows[] = $row;
        }
        $cursor = $parts->last()?->id ?? $after;
        $remaining = ! $catalogOnly && DB::table('parts')->where('id', '>', $cursor)->where('id', '<=', $through)->exists();

        return self::WRITES + ['oauth_refresh' => false, 'observed_at' => now()->toIso8601String(), 'through_id' => $through, 'next_after_id' => $cursor,
            'complete' => ! $remaining, 'bounded_total_parts' => DB::table('parts')->where('id', '<=', $through)->count(),
            'summary' => $summary, 'catalog' => $catalog, 'breakdown' => array_values($breakdown), 'rows' => $rows,
            'local_options' => array_keys(AllegroSalesSettingsResolver::SHIPPING_RATE_OPTIONS)];
    }

    public function diagnoseMainAccount(): array
    {
        return ReadOnlyDatabase::run(function (): array {
            $byId = DB::table('marketplace_accounts')->where('id', 2)->first();
            $byCode = DB::table('marketplace_accounts')->where('code', 'allegro_main')->first();
            $matches = $byId && $byCode && $byId->id === $byCode->id && $byId->marketplace === 'allegro';
            $identity = null;
            $catalog = null;
            if ($matches) {
                $who = $this->client->get($byId, '/me');
                $identity = array_diff_key($who, ['body' => true]);
                $login = $who['body']['login'] ?? null;
                if (($who['ok'] ?? false) && is_string($login) && $login !== '') {
                    $identity['provider_login'] = $login;
                    $identity['matches_maxhendler'] = strcasecmp($login, 'Maxhendler') === 0;
                } elseif ($who['ok'] ?? false) {
                    $identity['ok'] = false;
                    $identity['reason'] = 'invalid_response';
                }
                $result = $this->client->get($byId, '/sale/shipping-rates');
                $catalog = array_diff_key($result, ['body' => true]);
                $rates = $result['body']['shippingRates'] ?? null;
                if (($result['ok'] ?? false) && (! is_array($rates) || ! array_is_list($rates)
                    || collect($rates)->contains(fn ($r) => ! is_array($r) || ! is_string($r['id'] ?? null) || ! is_string($r['name'] ?? null)))) {
                    $catalog['ok'] = false;
                    $catalog['reason'] = 'invalid_response';
                }
                $catalog['shipping_rates_count'] = $catalog['ok'] ? count($rates) : null;
            }

            return self::WRITES + ['oauth_refresh' => false, 'observed_at' => now()->toIso8601String(),
                'expected_account_id' => 2, 'expected_account_code' => 'allegro_main',
                'account_id_exists' => $byId !== null, 'account_code_exists' => $byCode !== null,
                'account_id_code_match' => (bool) $matches,
                'maxhendler_identity_confirmed' => ($identity['ok'] ?? false) && ($identity['matches_maxhendler'] ?? false),
                'reason_code' => $matches ? ($catalog['reason'] ?? null) : ((! $byId || ! $byCode) ? 'account_missing' : 'account_mismatch'),
                'identity' => $identity, 'shipping_rates' => $catalog];
        });
    }

    private function classify(array &$row, string $reason, string $action): void
    {
        $row['reason_code'] = $reason;
        $row['action'] = $action;
    }
}
