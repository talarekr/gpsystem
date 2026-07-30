<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceSyncLog;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class EbayConnectionCoverageService
{
    /**
     * Build a read-only, best-effort static inventory. It deliberately does not
     * resolve controllers or services, because doing so can trigger constructors.
     */
    public function report(): array
    {
        $files = $this->ebayRequestFiles();

        return [
            'ok' => true,
            'ebay_enabled' => app(EbayConnectionGate::class)->isEbayEnabled(),
            'guarded_classes' => $this->guardedClasses($files),
            'guarded_endpoints' => $this->guardedEndpoints(),
            'potential_paths_without_explicit_guard' => $this->withoutExplicitGuard($files),
            'global_http_guard' => [
                'enabled' => true,
                'provider' => 'App\\Providers\\AppServiceProvider::boot',
                'covers' => 'All Illuminate HTTP client requests whose destination host contains ebay or carries an eBay API header.',
                'direct_curl_or_guzzle_detected' => collect($files)->contains(fn (array $file): bool => $file['direct_transport']),
            ],
            'last_blocked_actions' => $this->lastBlockedActions(),
            'marketplace_write' => false,
        ];
    }

    private function ebayRequestFiles(): array
    {
        $root = app_path();
        $rows = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') continue;
            $source = file_get_contents($file->getPathname()) ?: '';
            if (! Str::contains(Str::lower($source.$file->getFilename()), 'ebay')) continue;
            if (! preg_match('/Http::|curl_|Guzzle|EbayApiClient|postWithAuthRetry|getWithAuthRetry/', $source)) continue;
            $relative = Str::after($file->getPathname(), base_path().DIRECTORY_SEPARATOR);
            if ($relative === 'app/Services/Marketplace/EbayConnectionCoverageService.php') continue;
            preg_match('/(?:final\s+)?class\s+([A-Za-z0-9_]+)/', $source, $class);
            $rows[] = [
                'class' => $class[1] ?? $relative,
                'file' => $relative,
                'explicit_guard' => Str::contains($source, 'EbayConnectionGate'),
                'covered_by_global_http_guard' => true,
                'direct_transport' => (bool) preg_match('/curl_|Guzzle/', $source),
            ];
        }

        return $rows;
    }

    private function guardedClasses(array $files): array
    {
        return collect($files)->filter(fn (array $file): bool => $file['explicit_guard'] || $file['covered_by_global_http_guard'])
            ->values()->all();
    }

    private function withoutExplicitGuard(array $files): array
    {
        return collect($files)->reject(fn (array $file): bool => $file['explicit_guard'])
            ->map(fn (array $file): array => $file + ['risk' => $file['direct_transport'] ? 'direct_transport_review_required' : 'covered_by_global_http_guard'])
            ->values()->all();
    }

    private function guardedEndpoints(): array
    {
        return collect(Route::getRoutes())->filter(function ($route): bool {
            $uri = Str::lower($route->uri());
            return Str::contains($uri, 'ebay') && (Str::startsWith($uri, 'admin/tools/') || Str::startsWith($uri, 'tools/'));
        })->map(fn ($route): array => [
            'methods' => $route->methods(),
            'uri' => '/'.$route->uri(),
            'action' => $route->getActionName(),
            'coverage' => 'global_http_guard',
        ])->values()->all();
    }

    private function lastBlockedActions(): array
    {
        if (! Schema::hasTable('marketplace_sync_logs')) return [];

        return MarketplaceSyncLog::query()
            ->where('marketplace', 'ebay')
            ->where('action', 'ebay_action_blocked_connection_disabled')
            ->latest('id')->limit(20)->get(['id', 'action', 'message', 'payload', 'created_at'])
            ->map(fn (MarketplaceSyncLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'blocked_action' => data_get($log->payload, 'blocked_action'),
                'marketplace_write' => false,
                'message' => $log->message,
                'created_at' => $log->created_at?->toISOString(),
            ])->all();
    }
}
