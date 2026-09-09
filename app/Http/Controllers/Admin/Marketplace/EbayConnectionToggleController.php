<?php

namespace App\Http\Controllers\Admin\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceSyncLog;
use App\Services\Marketplace\EbayConnectionGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class EbayConnectionToggleController extends Controller
{
    public function show(EbayConnectionGate $gate): View
    {
        return view('admin.marketplace.ebay-connection-toggle', ['status' => $gate->status()]);
    }

    public function update(Request $request, EbayConnectionGate $gate): RedirectResponse
    {
        $enabled = $request->boolean('enabled');
        $expected = $enabled ? 'ENABLE_EBAY_WRITE_CONNECTION' : 'DISABLE_EBAY_WRITE_CONNECTION';
        abort_unless(hash_equals($expected, (string) $request->input('confirm')), 403, 'Exact confirmation token is required.');

        $account = $gate->account();
        abort_unless($account !== null, 422, 'The ebay_de marketplace account is not configured.');
        $before = $gate->writeEnabled($account);

        DB::transaction(function () use ($account, $enabled, $before, $request): void {
            $locked = $account->newQuery()->lockForUpdate()->findOrFail($account->getKey());
            $settings = is_array($locked->api_settings) ? $locked->api_settings : [];
            $settings[EbayConnectionGate::SETTING_KEY] = $enabled;
            $locked->forceFill(['api_settings' => $settings])->save();

            MarketplaceSyncLog::query()->create([
                'marketplace' => 'ebay',
                'action' => 'ebay_write_connection_toggle',
                'status' => 'success',
                'message' => 'Global eBay write connection setting changed locally; no eBay request was performed.',
                'payload' => ['before' => $before, 'after' => $enabled, 'user_id' => $request->user()?->getAuthIdentifier(), 'marketplace_write' => false, 'no_ebay_request_performed' => true],
                'created_at' => now(),
            ]);
        });

        return redirect()->route('admin.tools.marketplace.ebay-connection-toggle')->with('status', 'Ustawienie zapisane lokalnie. Nie wykonano żadnego requestu do eBay.');
    }
}
