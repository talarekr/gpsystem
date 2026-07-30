<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Models\SystemSetting;
use App\Services\Marketplace\EbayConnectionGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
class EbayConnectionToggleController extends Controller
{
    public const DISABLED_EFFECTS = ['publish/revise/relist/end/update eBay', 'synchronizacja listingów eBay', 'synchronizacja zamówień eBay', 'zewnętrzne requesty API eBay'];
    public function show(EbayConnectionGate $gate): View { return view('admin.tools.marketplace.ebay-connection-toggle', ['enabled' => $gate->isEbayEnabled(), 'setting' => SystemSetting::query()->with('updatedBy')->find(EbayConnectionGate::SETTING_KEY), 'effects' => self::DISABLED_EFFECTS]); }
    public function update(Request $request, EbayConnectionGate $gate): RedirectResponse
    {
        $enabled = match ((string) $request->input('confirm')) { 'enable-ebay' => true, 'disable-ebay' => false, default => null };
        if ($enabled === null) return back()->with('error', 'Nieprawidłowe potwierdzenie. Status eBay nie został zmieniony.');
        $gate->setEnabled($enabled, $request->user()?->id);
        return back()->with('success', $enabled ? 'eBay został włączony.' : 'eBay został bezpiecznie wyłączony.');
    }
    public function status(EbayConnectionGate $gate): JsonResponse
    {
        $accounts = MarketplaceAccount::query()->where(fn ($query) => $query->where('marketplace', 'like', 'ebay%')->orWhere('code', 'like', 'ebay%'))->get();
        return response()->json(['ok' => true, 'ebay_enabled' => $gate->isEbayEnabled(), 'source' => 'database/system_settings', 'env_config_present' => $accounts->contains(fn ($a) => filled($a->api_base_url)), 'tokens_present' => $accounts->contains(fn ($a) => filled(data_get($a->api_credentials, 'access_token')) || filled(data_get($a->api_credentials, 'refresh_token'))), 'marketplace_write' => false, 'disabled_effects' => self::DISABLED_EFFECTS]);
    }
}
