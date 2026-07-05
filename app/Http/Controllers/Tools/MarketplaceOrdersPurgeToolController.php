<?php

namespace App\Http\Controllers\Tools;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\MarketplaceSyncLog;
use App\Services\Marketplace\PurgeMarketplaceOrdersService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Throwable;

class MarketplaceOrdersPurgeToolController extends Controller
{
    public function __invoke(Request $request, PurgeMarketplaceOrdersService $service)
    {
        $this->authorizeOwnerAdmin($request);

        if ($request->isMethod('get') && ! $request->has('mode')) {
            return response()->view('tools.marketplace-orders-purge', [
                'result' => null,
                'selectedMarketplaces' => PurgeMarketplaceOrdersService::DEFAULT_MARKETPLACES,
                'onlyTestImport' => false,
                'confirm' => '',
            ]);
        }

        $apply = $request->input('mode') === 'apply' || $request->boolean('apply');
        $marketplaces = (array) $request->input('marketplaces', PurgeMarketplaceOrdersService::DEFAULT_MARKETPLACES);
        $onlyTestImport = $request->boolean('only_test_import');

        if ($apply && ! hash_equals('purge-marketplace-orders', (string) $request->input('confirm', ''))) {
            $result = ['ok' => false, 'dry_run' => true, 'message' => 'Apply requires exact confirmation: purge-marketplace-orders. No data changed.'];
            return $this->respond($request, $result, 422, $marketplaces, $onlyTestImport, (string) $request->input('confirm', ''));
        }

        try {
            $result = $service->run($marketplaces, $apply, $onlyTestImport);
            $this->audit($request, $apply ? 'apply' : 'dry_run', 'success', $result);
            return $this->respond($request, $result, 200, $marketplaces, $onlyTestImport, (string) $request->input('confirm', ''));
        } catch (Throwable $exception) {
            $result = ['ok' => false, 'dry_run' => ! $apply, 'message' => $exception->getMessage()];
            $this->audit($request, $apply ? 'apply' : 'dry_run', 'error', $result);
            return $this->respond($request, $result, 422, $marketplaces, $onlyTestImport, (string) $request->input('confirm', ''));
        }
    }

    private function respond(Request $request, array $result, int $status, array $marketplaces, bool $onlyTestImport, string $confirm)
    {
        if ($request->expectsJson()) return response()->json($result, $status);

        return response()->view('tools.marketplace-orders-purge', [
            'result' => $result,
            'selectedMarketplaces' => $marketplaces,
            'onlyTestImport' => $onlyTestImport,
            'confirm' => $confirm,
        ], $status);
    }

    private function authorizeOwnerAdmin(Request $request): void
    {
        abort_unless($request->user()?->canAccessPanel(filament()->getPanel('admin')), 403);
        abort_unless($request->user()?->hasAnyRole([UserRole::OwnerAdmin->value]), 403);
    }

    private function audit(Request $request, string $mode, string $status, array $payload): void
    {
        if (! Schema::hasTable('marketplace_sync_logs')) return;
        MarketplaceSyncLog::query()->create([
            'marketplace' => 'marketplace',
            'action' => 'marketplace_orders_purge_tool_'.$mode,
            'status' => $status,
            'message' => 'Admin tools marketplace orders purge '.$mode.' by user '.$request->user()?->id,
            'payload' => ['user_id' => $request->user()?->id, 'user_email' => $request->user()?->email, 'result' => $payload],
            'created_at' => now(),
        ]);
    }
}
