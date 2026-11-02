<?php

namespace App\Services\Marketplace;

use App\Services\Marketplace\ShippingRateAudit\AllegroShippingReadClient;
use App\Services\Marketplace\ShippingRateAudit\ReadOnlyDatabase;
use Illuminate\Support\Facades\DB;

/** Account-scoped, provider-owned catalog. Never writes Parts, accounts, listings or sync logs. */
final class AllegroShippingCatalog
{
    public function accountId(): ?int
    {
        $id = DB::table('marketplace_accounts')->where('code', 'allegro_main')->where('marketplace', 'allegro')->value('id');

        return $id === null ? null : (int) $id;
    }

    public function synced(?int $accountId): bool
    {
        return $accountId !== null && DB::table('allegro_shipping_catalog_syncs')->where('marketplace_account_id', $accountId)->whereNotNull('synced_at')->exists();
    }

    public function rates(int $accountId): array
    {
        return DB::table('allegro_shipping_rates')->where('marketplace_account_id', $accountId)->where('active', true)
            ->orderBy('shipping_rate_name')->get()->keyBy('shipping_rate_id')->all();
    }

    public function options(?string $current = null): array
    {
        return $this->optionsForAccount($this->accountId(), $current);
    }

    public function optionsForAccount(?int $accountId, ?string $current = null): array
    {
        $options = $this->synced($accountId)
            ? collect($this->rates($accountId))->pluck('shipping_rate_name', 'shipping_rate_name')->all()
            : AllegroSalesSettingsResolver::SHIPPING_RATE_OPTIONS;
        // Preserve a historic selection when editing; it does not become an active publishing option.
        if ($current !== null && trim($current) !== '' && ! array_key_exists($current, $options)) {
            $options[$current] = $current.' (historyczny / nieaktywny)';
        }

        return $options;
    }

    public static function parse(mixed $rows): ?array
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            return null;
        }
        $index = [];
        foreach ($rows as $r) {
            if (! is_array($r) || ! is_string($r['id'] ?? null) || trim($r['id']) === '' || strlen($r['id']) > 255
                || ! is_string($r['name'] ?? null) || trim($r['name']) === '' || mb_strlen($r['name']) > 255 || isset($index[$r['id']])) {
                return null;
            }
            $index[$r['id']] = $r['name'];
        }

        return $index;
    }

    public function synchronize(): array
    {
        $started = now()->format('Y-m-d H:i:s.u');
        $account = DB::table('marketplace_accounts')->where('code', 'allegro_main')->where('marketplace', 'allegro')->first();
        $read = app(AllegroShippingReadClient::class)->get($account, '/sale/shipping-rates');
        $rates = ($read['ok'] ?? false) ? self::parse($read['body']['shippingRates'] ?? null) : null;
        $flags = ['database_writes' => false, 'catalog_writes' => false, 'business_writes' => false, 'product_writes' => false, 'marketplace_writes' => false, 'allegro_writes' => false, 'oauth_refresh' => false];
        if ($rates === null) {
            return $flags + ['ok' => false, 'reason_code' => $read['reason'] ?? 'invalid_response', 'diagnostics' => array_diff_key($read, ['body' => true])];
        }

        return ReadOnlyDatabase::catalogWrites(function () use ($account, $rates, $started, $flags): array {
            return DB::transaction(function () use ($account, $rates, $started, $flags): array {
                DB::table('allegro_shipping_catalog_syncs')->insertOrIgnore(['marketplace_account_id' => $account->id]);
                $state = DB::table('allegro_shipping_catalog_syncs')->where('marketplace_account_id', $account->id)->lockForUpdate()->first();
                if ($state->requested_at !== null && $state->requested_at > $started) {
                    return $flags + ['ok' => false, 'reason_code' => 'sync_superseded'];
                }
                $at = now()->format('Y-m-d H:i:s.u');
                DB::table('allegro_shipping_rates')->where('marketplace_account_id', $account->id)->update(['active' => false, 'synced_at' => $at]);
                foreach ($rates as $id => $name) {
                    DB::table('allegro_shipping_rates')->updateOrInsert(['marketplace_account_id' => $account->id, 'shipping_rate_id' => $id],
                        ['shipping_rate_name' => $name, 'active' => true, 'last_seen_at' => $at, 'synced_at' => $at]);
                }
                DB::table('allegro_shipping_catalog_syncs')->where('marketplace_account_id', $account->id)->update(['requested_at' => $started, 'synced_at' => $at, 'rates_count' => count($rates)]);

                return array_replace($flags, ['database_writes' => true, 'catalog_writes' => true]) + ['ok' => true, 'marketplace_account_id' => $account->id, 'rates_count' => count($rates), 'synced_at' => $at];
            });
        });
    }
}
