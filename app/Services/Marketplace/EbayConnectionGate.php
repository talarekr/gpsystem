<?php
namespace App\Services\Marketplace;
use App\Exceptions\EbayConnectionDisabledException;
use App\Models\MarketplaceSyncLog;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Schema;
class EbayConnectionGate
{
    public const SETTING_KEY = 'marketplace.ebay.enabled';
    public const BLOCKER = 'eBay jest aktualnie wyłączony w ustawieniach integracji. Włącz go w Narzędzia → eBay connection toggle.';
    public function isEbayEnabled(): bool { if (! Schema::hasTable('system_settings')) return true; $value = SystemSetting::query()->find(self::SETTING_KEY)?->value; return $value === null ? true : filter_var($value, FILTER_VALIDATE_BOOL); }
    public function assertEbayEnabledForWrite(string $action): void { $this->assertEnabled($action); }
    public function assertEbayEnabledForSync(string $action): void { $this->assertEnabled($action); }
    public function assertEnabled(string $action): void
    {
        if ($this->isEbayEnabled()) return;
        $this->log('ebay_action_blocked_connection_disabled', ['blocked_action' => $action]);
        throw new EbayConnectionDisabledException($action);
    }
    public function setEnabled(bool $enabled, ?int $userId): SystemSetting
    {
        $old = $this->isEbayEnabled();
        $setting = SystemSetting::query()->updateOrCreate(['key' => self::SETTING_KEY], ['value' => $enabled ? 'true' : 'false', 'updated_by' => $userId]);
        $this->log($enabled ? 'ebay_connection_enabled' : 'ebay_connection_disabled', ['user_id' => $userId, 'old_value' => $old, 'new_value' => $enabled]);
        return $setting;
    }
    private function log(string $action, array $payload): void
    {
        if (! Schema::hasTable('marketplace_sync_logs')) return;
        MarketplaceSyncLog::query()->create(['marketplace' => 'ebay', 'action' => $action, 'status' => 'success', 'message' => $action === 'ebay_action_blocked_connection_disabled' ? self::BLOCKER : 'Zmieniono aplikacyjny przełącznik eBay.', 'payload' => $payload + ['integration' => 'ebay', 'marketplace_write' => false], 'created_at' => now()]);
    }
}
