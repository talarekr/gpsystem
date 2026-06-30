<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Services\Marketplace\Api\EbayApiClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class EbayDescriptionAuditService
{
    public function __construct(private readonly EbayDescriptionTemplateRenderer $renderer) {}

    /** @return array<string,mixed> */
    public function run(string $channel = 'ebay_de', int $limit = 20, int $offset = 0, ?int $partId = null, bool $apply = false, bool $confirmed = false, bool $checkApi = false, bool $patchAssetsOnly = false, bool $fetchLiveDescription = false): array
    {
        abort_unless(in_array($channel, ['ebay_de', 'ebay_fr', 'ebay'], true), 422, 'Supported eBay channel values: ebay_de, ebay_fr, ebay.');
        $channel = $channel === 'ebay' ? 'ebay_de' : $channel;
        $query = MarketplaceListing::query()->with(['part', 'account:id,code,marketplace,api_enabled,api_base_url,api_mode,api_credentials,api_settings'])->where('marketplace', $channel);
        if ($partId !== null) $query->where('part_id', $partId);

        $all = (clone $query)->get();
        $rows = (clone $query)->orderBy('id')->offset(max(0, $offset))->limit(max(1, min(100, $limit)))->get();
        $writeEnabled = $this->descriptionReviseWriteEnabled();
        $reviseEnabled = $writeEnabled;
        $applyBlockedReason = $apply && $confirmed && ! $writeEnabled ? 'marketplace_write_disabled' : null;
        $applied = 0;
        $results = $rows->map(fn (MarketplaceListing $listing): array => $this->auditListing($listing, $channel, $apply, $confirmed, $checkApi, $patchAssetsOnly, $fetchLiveDescription, $writeEnabled))->values()->all();

        $applied = collect($results)->where('apply_executed', true)->count();

        return [
            'mode' => $apply && $confirmed ? 'apply' : 'dry_run',
            'dry_run' => ! ($apply && $confirmed),
            'marketplace_write' => $writeEnabled && $applied > 0,
            'write_enabled' => $writeEnabled,
            'publish' => false,
            'revise' => $writeEnabled && $applied > 0,
            'revise_enabled' => $reviseEnabled,
            'apply_requested' => $apply,
            'apply_confirmed' => $confirmed,
            'apply_executed' => $applied > 0,
            'applied' => $applied,
            'apply_blocked_reason' => $applyBlockedReason,
            'relist' => false,
            'end' => false,
            'stock_order_price_sync' => false,
            'summary' => [
                'total_scanned' => count($results),
                'total_matching_local_listings' => $all->count(),
                'active_needs_description_revise' => collect($results)->where('needs_description_revise', true)->where('skip_ended_listing', false)->count(),
                'ended_skipped' => collect($results)->where('skip_ended_listing', true)->count(),
                'would_revise_description' => collect($results)->where('action', 'would_revise_description')->count(),
                'would_patch_asset_src_only' => collect($results)->where('action', 'would_patch_asset_src_only')->count(),
                'blocked' => collect($results)->where('action', 'blocked')->count(),
                'applied' => $applied,
                'write_enabled' => $writeEnabled,
                'revise_enabled' => $reviseEnabled,
                'apply_blocked_reason' => $applyBlockedReason,
            ],
            'results' => $results,
            'warnings' => [$writeEnabled ? 'Description-only eBay revise is enabled for confirmed apply requests. No publish, relist, end, stock, price, title, photos, policies, category, item specifics or order sync is performed.' : 'Read-only/dry-run audit. No eBay write, publish, revise, relist, end, stock, price or order sync is performed while marketplace write is disabled. apply=1 requires confirm=revise-ebay-description and GPS_EXTERNAL_API_WRITES_ENABLED=true plus GPS_EBAY_DESCRIPTION_REVISE_ENABLED=true. patch_assets_only=1 never falls back to a generated full description.'],
        ];
    }

    /** @return array<string,mixed> */
    public function auditListing(MarketplaceListing $listing, string $channel = 'ebay_de', bool $apply = false, bool $confirmed = false, bool $checkApi = false, bool $patchAssetsOnly = false, bool $fetchLiveDescription = false, bool $writeEnabled = false): array
    {
        $raw = $listing->raw_payload ?: [];
        $localHtml = $this->localDescriptionHtml($raw);
        $currentHtml = $listing->part ? $this->renderer->render($channel, $listing->part, []) : '';
        $live = ($checkApi || $fetchLiveDescription) ? $this->readOnlyLiveDescription($listing, $channel, $fetchLiveDescription) : ['checked' => false, 'description_html' => null, 'live_description_source' => 'not_available'];
        $liveHtml = (string) ($live['description_html'] ?? '');
        $status = $this->finalStatus($listing, $raw, $live);
        $skipEnded = $this->shouldSkipEndedListing($status, $live);
        $statusDiagnostics = $this->statusDiagnostics($listing, $raw, $live, $status, $skipEnded);

        if ($patchAssetsOnly) {
            return $this->auditListingAssetSrcOnly($listing, $channel, $localHtml, $liveHtml, $live, $raw, $status, $skipEnded, $apply, $confirmed, $fetchLiveDescription, $writeEnabled);
        }

        $current = $this->htmlDiagnostics($currentHtml, true);
        $comparisonHtml = trim($liveHtml) !== '' ? $liveHtml : $localHtml;
        $liveDiag = $this->htmlDiagnostics($comparisonHtml, true);
        $missingCurrent = array_values(array_diff($current['asset_urls_found'], $liveDiag['asset_urls_found']));
        $stale = array_values(array_filter($liveDiag['asset_urls_found'], fn (string $url): bool => ! $this->isCurrentAssetUrl($url)));
        $needs = ! $skipEnded && ($stale !== [] || $missingCurrent !== [] || $liveDiag['has_bad_asset_src']);
        $payload = ['listingDescription' => $currentHtml];

        return [
            'local_part_id' => $listing->part_id,
            'marketplace_listing_id' => $listing->id,
            'marketplace' => $listing->marketplace,
            'channel' => $channel,
            'marketplace_write' => false,
            'publish' => false,
            'revise' => false,
            'relist' => false,
            'end' => false,
            'stock_order_price_sync' => false,
            'write_enabled' => $writeEnabled,
            'revise_enabled' => $writeEnabled,
            'apply_blocked_reason' => $apply && $confirmed && ! $writeEnabled ? 'marketplace_write_disabled' : null,
            'blocker' => $apply && $confirmed && ! $writeEnabled ? 'marketplace_write_disabled' : null,
            'local_status' => $listing->status,
            'api_listing_status' => $live['api_listing_status'] ?? null,
            'final_listing_status' => $status,
            'skip_ended_listing' => $skipEnded,
            'status_blocking_reason' => $statusDiagnostics['status_blocking_reason'],
            'status_source' => $statusDiagnostics['status_source'],
            'trading_get_item_confirms_item_exists' => $statusDiagnostics['trading_get_item_confirms_item_exists'],
            'marketplace_listing_id_external' => $listing->external_listing_id,
            'external_offer_id' => $listing->external_offer_id,
            'item_id' => $this->itemId($listing, $raw),
            'local_description_length' => mb_strlen($localHtml),
            'live_description_length' => mb_strlen($liveHtml),
            'live_description_source' => $live['live_description_source'] ?? 'not_available',
            'live_description_can_confirm_assets' => trim($liveHtml) !== '',
            'current_generated_description_length' => mb_strlen($currentHtml),
            'description_changed' => trim($comparisonHtml) !== trim($currentHtml),
            'local_description_diagnostics' => $this->htmlDiagnostics($localHtml, false),
            'current_template_asset_urls' => $current['asset_urls_found'],
            'live_listing_asset_urls' => $liveDiag['asset_urls_found'],
            'live_description_diagnostics' => $liveDiag,
            'live_read_only_api' => Arr::except($live, ['description_html']),
            'missing_current_assets' => $skipEnded ? [] : $missingCurrent,
            'stale_asset_urls' => $stale,
            'old_asset_urls' => $stale,
            'new_asset_urls' => $current['asset_urls_found'],
            'needs_description_revise' => $needs,
            'action' => $skipEnded ? 'skip_ended_listing' : ($needs ? 'would_revise_description' : 'ok'),
            'confidence' => $needs ? 'high' : null,
            'apply_requested' => $apply,
            'apply_confirmed' => $confirmed,
            'apply_executed' => $this->shouldExecuteApply($apply, $confirmed, $writeEnabled, $needs, $skipEnded) ? (bool) (($this->executeDescriptionRevise($listing, $channel, $payload)['ok'] ?? false)) : false,
            'revise_payload_safe' => $needs || $apply ? $payload : null,
            'revise_payload_forbidden_keys_present' => array_values(array_intersect(array_keys($payload), ['price','pricingSummary','availableQuantity','quantity','stock','availability','listingPolicies','fulfillmentPolicyId','paymentPolicyId','returnPolicyId','merchantLocationKey','images','product'])),
        ];
    }


    /** @return array<string,mixed> */
    private function auditListingAssetSrcOnly(MarketplaceListing $listing, string $channel, string $localHtml, string $liveHtml, array $live, array $raw, string $status, bool $skipEnded, bool $apply, bool $confirmed, bool $fetchLiveDescription = false, bool $writeEnabled = false): array
    {
        $sourceHtml = trim($liveHtml) !== '' ? $liveHtml : $localHtml;
        $base = [
            'local_part_id' => $listing->part_id,
            'marketplace_listing_id' => $listing->id,
            'marketplace' => $listing->marketplace,
            'channel' => $channel,
            'patch_assets_only' => true,
            'marketplace_write' => false,
            'publish' => false,
            'revise' => false,
            'relist' => false,
            'end' => false,
            'stock_order_price_sync' => false,
            'write_enabled' => $writeEnabled,
            'revise_enabled' => $writeEnabled,
            'apply_blocked_reason' => $apply && $confirmed && ! $writeEnabled ? 'marketplace_write_disabled' : null,
            'blocker' => $apply && $confirmed && ! $writeEnabled ? 'marketplace_write_disabled' : null,
            'local_status' => $listing->status,
            'api_listing_status' => $live['api_listing_status'] ?? null,
            'final_listing_status' => $status,
            'skip_ended_listing' => $skipEnded,
            'status_blocking_reason' => $this->statusDiagnostics($listing, $raw, $live, $status, $skipEnded)['status_blocking_reason'],
            'status_source' => $this->statusDiagnostics($listing, $raw, $live, $status, $skipEnded)['status_source'],
            'trading_get_item_confirms_item_exists' => $this->statusDiagnostics($listing, $raw, $live, $status, $skipEnded)['trading_get_item_confirms_item_exists'],
            'marketplace_listing_id_external' => $listing->external_listing_id,
            'external_offer_id' => $listing->external_offer_id,
            'item_id' => $this->itemId($listing, $raw),
            'original_description_length' => mb_strlen($sourceHtml),
            'local_description_length' => mb_strlen($localHtml),
            'live_description_length' => mb_strlen($liveHtml),
            'live_description_source' => $live['live_description_source'] ?? 'not_available',
            'live_description_can_confirm_assets' => trim($liveHtml) !== '',
            'live_listing_asset_urls' => $this->imgSrcs($liveHtml),
            'stale_asset_urls' => array_values(array_filter($this->imgSrcs($liveHtml), fn (string $url): bool => ! $this->isCurrentAssetUrl($url))),
            'live_read_only_api' => Arr::except($live, ['description_html']),
            'apply_requested' => $apply,
            'apply_confirmed' => $confirmed,
            'apply_executed' => false,
        ];

        if ($sourceHtml === '') {
            return $base + [
                'action' => 'blocked',
                'blocker' => $fetchLiveDescription ? 'cannot_fetch_live_description' : 'cannot_patch_assets_only_without_existing_description',
                'needs_description_revise' => false,
                'replacements_count' => 0,
                'asset_src_replacements' => [],
                'old_src' => [],
                'new_src' => [],
                'patched_description_length' => 0,
                'changed_only_img_src' => false,
                'forbidden_changes_detected' => false,
                'revise_payload_safe' => null,
                'revise_payload_forbidden_keys_present' => [],
            ];
        }

        $patch = $this->patchTemplateAssetSrcs($sourceHtml);
        $payload = ['listingDescription' => $patch['html']];

        return $base + [
            'action' => $skipEnded ? 'skip_ended_listing' : ($patch['replacements_count'] > 0 ? 'would_patch_asset_src_only' : 'ok'),
            'needs_description_revise' => ! $skipEnded && $patch['replacements_count'] > 0,
            'confidence' => $patch['replacements_count'] > 0 ? 'high' : null,
            'replacements_count' => $patch['replacements_count'],
            'asset_src_replacements' => $patch['replacements'],
            'old_src' => array_values(array_unique(array_column($patch['replacements'], 'old_src'))),
            'new_src' => array_values(array_unique(array_column($patch['replacements'], 'new_src'))),
            'patched_description_length' => mb_strlen($patch['html']),
            'changed_only_img_src' => $patch['changed_only_img_src'],
            'forbidden_changes_detected' => ! $patch['changed_only_img_src'],
            'description_changed' => $sourceHtml !== $patch['html'],
            'apply_executed' => $this->shouldExecuteApply($apply, $confirmed, $writeEnabled, $patch['replacements_count'] > 0, $skipEnded) ? (bool) (($this->executeDescriptionRevise($listing, $channel, $payload)['ok'] ?? false)) : false,
            'revise_payload_safe' => ($patch['replacements_count'] > 0 || $apply) && ! $skipEnded ? $payload : null,
            'revise_payload_forbidden_keys_present' => array_values(array_intersect(array_keys($payload), ['price','pricingSummary','availableQuantity','quantity','stock','availability','listingPolicies','fulfillmentPolicyId','paymentPolicyId','returnPolicyId','merchantLocationKey','images','product'])),
        ];
    }


    private function descriptionReviseWriteEnabled(): bool
    {
        return (bool) config('marketplace.external_api_writes_enabled', false)
            && (bool) config('marketplace.ebay_description_revise_enabled', false);
    }

    private function shouldExecuteApply(bool $apply, bool $confirmed, bool $writeEnabled, bool $needsRevise, bool $skipEnded): bool
    {
        return $apply && $confirmed && $writeEnabled && $needsRevise && ! $skipEnded;
    }

    /** @param array{listingDescription:string} $payload */
    private function executeDescriptionRevise(MarketplaceListing $listing, string $channel, array $payload): array
    {
        if (array_keys($payload) !== ['listingDescription']) return ['ok' => false, 'error' => 'unsafe_payload_shape'];
        $itemId = $this->itemId($listing, $listing->raw_payload ?: []);
        if ($itemId === null) return ['ok' => false, 'error' => 'missing_item_id'];
        $account = $listing->account ?: MarketplaceAccount::query()->where('code', $channel)->orWhere('marketplace', $channel)->first();
        if (! $account) return ['ok' => false, 'error' => 'marketplace_account_not_found'];
        return (new EbayApiClient($account, $channel))->reviseItemDescriptionOnly($itemId, $payload);
    }

    /** @return array{html:string,replacements_count:int,replacements:array<int,array{old_src:string,new_src:string}>,changed_only_img_src:bool} */
    private function patchTemplateAssetSrcs(string $html): array
    {
        $replacements = [];
        $patched = preg_replace_callback('/(<img\b[^>]*?\bsrc\s*=\s*)(["\']?)([^"\'\s>]*)(\2)([^>]*>)/i', function (array $m) use (&$replacements): string {
            $newSrc = $this->mappedTemplateAssetUrl($m[3]);
            if ($newSrc === null || $newSrc === $m[3]) return $m[0];
            $replacements[] = ['old_src' => $m[3], 'new_src' => $newSrc];
            return $m[1].$m[2].$newSrc.$m[4].$m[5];
        }, $html) ?? $html;

        return [
            'html' => $patched,
            'replacements_count' => count($replacements),
            'replacements' => $replacements,
            'changed_only_img_src' => $this->maskImgSrcValues($patched) === $this->maskImgSrcValues($html),
        ];
    }

    private function maskImgSrcValues(string $html): string
    {
        return preg_replace('/(<img\b[^>]*?\bsrc\s*=\s*)(["\']?)([^"\'\s>]*)(\2)([^>]*>)/i', '$1$2__IMG_SRC__$4$5', $html) ?? $html;
    }

    private function mappedTemplateAssetUrl(string $src): ?string
    {
        $s = strtolower($src);
        $map = [
            'icon-shipping.png' => ['icon-shipping', 'shipping'],
            'icon-returns.png' => ['returns', 'return'],
            'icon-packaging.png' => ['packaging', 'package'],
            'icon-original.png' => ['original', 'oe'],
            'europe-map.png' => ['europe-map', 'map'],
            'dhl-logo.png' => ['dhl'],
            'dpd-logo.png' => ['dpd'],
        ];
        foreach ($map as $file => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($s, $needle)) return 'https://gpswiss.pl/ebay-template/assets/'.$file;
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function htmlDiagnostics(string $html, bool $withHttpChecks): array
    {
        $srcs = $this->imgSrcs($html);
        $checks = [];
        foreach ($srcs as $src) {
            $checks[$src] = $this->srcDiagnostics($src) + ($withHttpChecks ? ['public_http_check' => $this->httpCheck($src)] : []);
        }
        return ['img_src_count' => count($srcs), 'asset_urls_found' => $srcs, 'src_diagnostics' => $checks, 'has_bad_asset_src' => collect($checks)->contains(fn ($d) => ! ($d['is_current_asset'] ?? false) || ($d['is_empty'] ?? false) || ($d['is_relative'] ?? false) || ($d['is_storage'] ?? false) || ($d['is_gpsystem_thecamels'] ?? false))];
    }

    private function imgSrcs(string $html): array
    {
        if ($html === '') return [];
        preg_match_all('/<img\b[^>]*\bsrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $html, $m);
        return array_values(array_unique(array_map('trim', array_filter(array_merge($m[1] ?? [], $m[2] ?? [], $m[3] ?? []), fn ($v) => $v !== null))));
    }

    private function srcDiagnostics(string $src): array
    {
        return ['src' => $src, 'is_current_asset' => $this->isCurrentAssetUrl($src), 'is_old_or_invalid' => ! $this->isCurrentAssetUrl($src), 'is_relative' => ! Str::startsWith($src, ['http://','https://','data:']), 'is_storage' => str_contains($src, '/storage/'), 'is_gpsystem_thecamels' => str_contains($src, 'gpsystem.thecamels.pl'), 'is_empty' => trim($src) === ''];
    }

    private function httpCheck(string $src): array
    {
        if (! Str::startsWith($src, ['http://','https://'])) return ['checked' => false, 'ok_png_200' => false];
        try { $r = Http::timeout(8)->get($src); return ['checked' => true, 'http_status' => $r->status(), 'content_type' => $r->header('Content-Type'), 'ok_png_200' => $r->status() === 200 && str_contains(strtolower((string) $r->header('Content-Type')), 'image/png')]; }
        catch (\Throwable $e) { return ['checked' => true, 'ok_png_200' => false, 'error' => class_basename($e)]; }
    }

    private function isCurrentAssetUrl(string $src): bool { return Str::startsWith($src, 'https://gpswiss.pl/ebay-template/assets/'); }
    private function localDescriptionHtml(array $raw): string { foreach (['description_rendered_html','listingDescription','description.html','request_summary.description_rendered_html','request.listingDescription','offer.listingDescription','meta.description_rendered_html'] as $key) { $v = Arr::get($raw, $key); if (is_string($v) && trim($v) !== '') return $v; } return ''; }
    private function finalStatus(MarketplaceListing $l, array $raw, array $live): string
    {
        $api = (string) ($live['api_listing_status'] ?? '');
        if ($api === 'active') return 'active';
        if ($this->tradingGetItemConfirmsItemExists($live) && in_array($api, ['', 'unknown', 'unavailable', 'not_checked'], true)) return 'active';
        if (in_array($api, ['ended', 'inactive', 'not_found'], true)) return $api === 'not_found' ? 'not_found' : 'ended';
        $s = strtolower((string) ($l->last_api_status ?: $l->status ?: Arr::get($raw, 'status')));
        if (in_array($s, ['active', 'published', 'live'], true)) return 'active';
        if (in_array($s, ['ended', 'inactive', 'not_found'], true)) return 'ended';
        return 'unknown';
    }

    private function shouldSkipEndedListing(string $status, array $live): bool
    {
        if ($this->tradingGetItemConfirmsItemExists($live) && in_array((string) ($live['api_listing_status'] ?? ''), ['', 'unknown', 'unavailable', 'not_checked'], true)) return false;
        return in_array($status, ['ended', 'inactive', 'not_found'], true);
    }

    /** @return array{status_blocking_reason:?string,status_source:string,trading_get_item_confirms_item_exists:bool} */
    private function statusDiagnostics(MarketplaceListing $l, array $raw, array $live, string $status, bool $skipEnded): array
    {
        $api = (string) ($live['api_listing_status'] ?? '');
        $tradingConfirms = $this->tradingGetItemConfirmsItemExists($live);
        $source = match (true) {
            $tradingConfirms && in_array($api, ['', 'unknown', 'unavailable', 'not_checked'], true) => 'browse_status_unresolved_trading_get_item_confirmed_description',
            $api === 'active' => 'browse_api_active',
            in_array($api, ['ended', 'inactive', 'not_found'], true) => 'browse_api_'.$api,
            $api === 'unavailable' => 'browse_api_unavailable_unresolved',
            default => 'local_status_fallback',
        };

        return [
            'status_blocking_reason' => $skipEnded ? 'clear_'.$status.'_status' : null,
            'status_source' => $source,
            'trading_get_item_confirms_item_exists' => $tradingConfirms,
        ];
    }

    private function tradingGetItemConfirmsItemExists(array $live): bool
    {
        return ($live['trading_ack'] ?? null) === 'Success'
            && (int) ($live['description_length'] ?? mb_strlen((string) ($live['description_html'] ?? ''))) > 0
            && filled($live['item_id'] ?? null);
    }
    private function itemId(MarketplaceListing $l, array $raw): ?string { foreach ([$l->external_listing_id, $l->external_offer_id, Schema::hasColumn('marketplace_listings','external_id') ? $l->external_id : null, preg_match('#/itm/(\d+)#', (string) $l->url, $m) ? $m[1] : null, Arr::get($raw,'item_id'), Arr::get($raw,'ebay.item_id')] as $v) if (filled($v) && ctype_digit((string) $v)) return (string) $v; return null; }
    private function readOnlyLiveDescription(MarketplaceListing $listing, string $channel, bool $fetchDescription = false): array
    {
        $itemId = $this->itemId($listing, $listing->raw_payload ?: []); if (! $itemId) return ['checked' => false, 'api_listing_status' => 'not_checked', 'error_message_safe' => 'No numeric item id.'];
        $account = $listing->account ?: MarketplaceAccount::query()->where('code', $channel)->orWhere('marketplace', $channel)->first(); if (! $account) return ['checked' => false, 'api_listing_status' => 'not_checked', 'error_message_safe' => 'Marketplace account not found.'];
        $client = new EbayApiClient($channel, $account);
        if ($fetchDescription) {
            $description = $client->getItemDescriptionByItemId($itemId);
            $status = $client->getListingStatusByItemId($itemId, strtoupper($channel));
            return array_merge($status, $description, ['checked' => true, 'description_html' => $description['description_html'] ?? null]);
        }
        $status = $client->getListingStatusByItemId($itemId, strtoupper($channel));
        return $status + ['checked' => true, 'description_html' => null, 'live_description_source' => 'not_available'];
    }
}
