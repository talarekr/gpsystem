<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Services\Marketplace\Api\MarketplaceApiManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OvokoListingUrlBackfillService
{
    public function __construct(
        private readonly MarketplaceApiManager $apiManager,
        private readonly OvokoPartIdExtractor $extractor,
        private readonly ApiIntegrationLogger $logger,
    ) {}



    /** @return array{mode:string,summary:array<string,int>,results:array<int,array<string,mixed>>,warnings:array<int,string>,limit_requested:int,limit_applied:int,offset_requested:int,offset_applied:int} */
    public function runBrowserBackfillForPartIds(array $partIds, bool $apply = false, bool $force = false, bool $missingOnly = true, int $limit = 100, bool $includeInactive = false, bool $debug = false, bool $reattach = false): array
    {
        $partIds = array_values(array_unique(array_filter(array_map('intval', $partIds), fn (int $id): bool => $id > 0)));
        $aggregate = [
            'mode' => $apply ? 'apply' : 'dry_run',
            'summary' => [],
            'results' => [],
            'warnings' => [],
            'limit_requested' => count($partIds),
            'limit_applied' => count($partIds),
            'offset_requested' => 0,
            'offset_applied' => 0,
            'debug' => $debug ? ['part_ids' => []] : null,
        ];

        foreach ($partIds as $partId) {
            $result = $this->runBrowserBackfill($apply, $force, $missingOnly, $limit, 0, $partId, $includeInactive, $debug, $reattach);
            foreach ($result['summary'] as $key => $value) {
                $aggregate['summary'][$key] = ($aggregate['summary'][$key] ?? 0) + (int) $value;
            }
            $aggregate['warnings'] = array_values(array_unique(array_merge($aggregate['warnings'], $result['warnings'])));
            if ($debug) {
                $aggregate['debug']['part_ids'][$partId] = $result['debug'];
            }

            $part = Part::query()->select(['id', 'legacy_payload', 'source_system', 'external_id'])->find($partId);
            if ($result['results'] === []) {
                $source = $part ? $this->ovokoIdFromPart($part) : ['id' => null, 'source' => null, 'path' => null];
                $aggregate['summary']['missing_part'] = ($aggregate['summary']['missing_part'] ?? 0) + ($part ? 0 : 1);
                $aggregate['summary']['skipped'] = ($aggregate['summary']['skipped'] ?? 0) + 1;
                $aggregate['results'][] = [
                    'part_id' => $partId,
                    'part_exists' => $part !== null,
                    'legacy_ovoko_id' => $source['id'],
                    'ovoko_id' => $source['id'],
                    'existing_marketplace_listing' => null,
                    'existing_ovoko_listing' => null,
                    'marketplace_listing_id' => null,
                    'action' => $part ? 'skipped' : 'part_not_found',
                    'reattach_allowed' => null,
                    'reattach_reason' => null,
                    'errors' => $part ? ['no_eligible_ovoko_listing_or_legacy_ovoko_id'] : ['part_not_found'],
                ];
                continue;
            }

            foreach ($result['results'] as $row) {
                $listing = isset($row['marketplace_listing_id']) ? MarketplaceListing::query()->find($row['marketplace_listing_id']) : null;
                $source = $part ? $this->ovokoIdFromPart($part) : ['id' => $row['ovoko_id'] ?? null];
                $row['part_exists'] = $part !== null;
                $row['legacy_ovoko_id'] = $source['id'] ?? ($row['ovoko_id'] ?? null);
                $row['existing_marketplace_listing'] = $listing ? $this->listingDiagnosticRow($listing) : ($row['existing_ovoko_listing'] ?? null);
                $row['reattach_allowed'] = $row['reattach_allowed'] ?? null;
                $row['reattach_reason'] = $row['reattach_reason'] ?? null;
                $aggregate['results'][] = $row;
            }
        }

        $aggregate['summary']['requested_parts'] = count($partIds);
        $aggregate['summary']['results_count'] = count($aggregate['results']);

        return $aggregate;
    }

    /** @return array{mode:string,summary:array<string,int>,results:array<int,array<string,mixed>>,warnings:array<int,string>,limit_requested:int,limit_applied:int,offset_requested:int,offset_applied:int} */
    public function runBrowserBackfill(bool $apply = false, bool $force = false, bool $missingOnly = true, int $limit = 100, int $offset = 0, ?int $partId = null, bool $includeInactive = false, bool $debug = false, bool $reattach = false): array
    {
        if (! Schema::hasTable('marketplace_listings')) {
            throw new \RuntimeException('Required table marketplace_listings does not exist.');
        }

        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $endedStatuses = ['ended', 'inactive', 'deleted', 'archived', 'not_found', 'NOT_FOUND_IN_ACTIVE_API'];

        $summary = [
            'scanned' => 0,
            'matched' => 0,
            'would_update_url' => 0,
            'updated_url' => 0,
            'would_update_price' => 0,
            'updated_price' => 0,
            'skipped' => 0,
            'errors' => 0,
            'would_create_listing' => 0,
            'created_listing' => 0,
            'would_update_existing_listing' => 0,
            'updated_existing_listing' => 0,
            'already_mapped' => 0,
            'skipped_existing_complete' => 0,
            'conflict_existing_ovoko_listing' => 0,
            'would_reattach_listing' => 0,
            'reattached_listing' => 0,
            'restored_listing' => 0,
        ];
        $results = [];

        $query = MarketplaceListing::query()
            ->with('part:id,name,sku,part_number,oem_number,ovoko_price,currency,status,quantity,legacy_payload')
            ->where('marketplace', 'like', '%ovoko%')
            ->whereNotNull('part_id')
            ->where(fn ($query) => $query
                ->whereRaw("NULLIF(TRIM(COALESCE(external_offer_id, '')), '') IS NOT NULL")
                ->orWhereRaw("NULLIF(TRIM(COALESCE(external_listing_id, '')), '') IS NOT NULL")
            )
            ->orderBy('marketplace_listings.id')
            ->offset($offset)
            ->limit($limit);


        if (! $includeInactive) {
            $query->where(fn ($query) => $query->whereNull('status')->orWhereNotIn('status', $endedStatuses))
                ->where(fn ($query) => $query->whereNull('last_api_status')->orWhereNotIn('last_api_status', $endedStatuses))
                ->whereHas('part', fn ($query) => $query
                    ->whereIn('status', ['ready', 'published'])
                    ->where(fn ($quantity) => $quantity->whereNull('quantity')->orWhere('quantity', '>', 0))
                );
        } else {
            $query->whereHas('part');
        }

        if ($partId !== null) {
            $query->where('part_id', $partId);
        }

        if ($missingOnly && ! $force) {
            $query->where(fn ($query) => $query
                ->whereNull('url')
                ->orWhere('url', '')
                ->orWhereHas('part', fn ($part) => $part->whereNull('ovoko_price'))
            );
        }

        $seenPartIds = [];

        foreach ($query->get() as $listing) {
            $summary['scanned']++;
            if ($listing->part_id !== null) {
                $seenPartIds[] = (int) $listing->part_id;
            }
            $oldUrl = $this->blankNull($listing->url);
            $oldPrice = $listing->part?->ovoko_price;
            $newPrice = is_numeric($listing->price) ? (float) $listing->price : null;
            $ovokoId = $this->existingOvokoId($listing);
            $newUrl = $this->generatedShopUrlFromOvokoPartId($ovokoId, $listing);
            $actions = [];
            $errors = [];

            $canUpdateUrl = $newUrl !== null && ($force || $oldUrl === null);
            $canUpdatePrice = $newPrice !== null && ($force || ! is_numeric($oldPrice));

            if ($canUpdateUrl) {
                $summary['would_update_url']++;
                $actions[] = $apply ? 'update_url' : 'would_update_url';
            }
            if ($canUpdatePrice) {
                $summary['would_update_price']++;
                $actions[] = $apply ? 'update_price' : 'would_update_price';
            }
            if ($newUrl === null) {
                $errors[] = 'missing_or_invalid_ovoko_id';
            }
            if ($oldUrl !== null && ! $force) {
                $actions[] = 'skip_url_exists';
            }
            if (is_numeric($oldPrice) && ! $force) {
                $actions[] = 'skip_price_exists';
            }

            if ($canUpdateUrl || $canUpdatePrice) {
                $summary['matched']++;
            } else {
                $summary['skipped']++;
                $actions[] = 'skipped';
            }

            if ($apply && ($canUpdateUrl || $canUpdatePrice)) {
                try {
                    if ($canUpdateUrl) {
                        $listing->url = $newUrl;
                        $listing->save();
                        $summary['updated_url']++;
                        $this->logGeneratedUrl($listing, (string) $ovokoId, $newUrl);
                    }
                    if ($canUpdatePrice && $listing->part) {
                        $listing->part->forceFill(['ovoko_price' => $newPrice, 'currency' => $listing->part->currency ?: ($listing->currency ?: 'PLN')])->save();
                        $summary['updated_price']++;
                    }
                } catch (\Throwable $exception) {
                    $summary['errors']++;
                    $errors[] = $exception->getMessage();
                }
            }

            $results[] = [
                'part_id' => $listing->part_id,
                'marketplace_listing_id' => $listing->id,
                'ovoko_id' => $ovokoId,
                'old_url' => $oldUrl,
                'new_url' => $newUrl,
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
                'action' => implode(',', array_values(array_unique($actions))),
                'errors' => $errors,
            ];
        }


        foreach ($this->legacyPartCandidates($partId, $limit, $offset, $includeInactive, $seenPartIds) as $part) {
            $summary['scanned']++;
            $source = $this->ovokoIdFromPart($part);
            $ovokoId = $source['id'];
            $newUrl = $this->generatedShopUrlFromOvokoPartId($ovokoId);
            $actions = [];
            $errors = [];
            $reattachChanges = [];
            $appliedListing = null;

            if ($ovokoId === null || $newUrl === null) {
                $summary['skipped']++;
                $errors[] = 'missing_or_invalid_legacy_ovoko_id';
                $actions[] = 'skipped';
            } else {
                $existingMatch = $this->findExistingOvokoListingByLegacyId($ovokoId);
                if ($existingMatch !== null && (int) $existingMatch->part_id === (int) $part->id) {
                    $existingUrl = $this->blankNull($existingMatch->url);
                    $existingPrice = $existingMatch->price;
                    $existingComplete = $existingUrl !== null && is_numeric($existingPrice);

                    $summary['matched']++;

                    if ($existingComplete && ! $force) {
                        $summary['already_mapped']++;
                        $summary['skipped_existing_complete']++;
                        $actions[] = 'already_mapped';
                        $actions[] = 'skip_existing_complete';
                    } else {
                        $summary['would_update_existing_listing']++;
                        $actions[] = $apply ? 'update_existing_listing' : 'would_update_existing_listing';

                        if ($apply) {
                            try {
                                $legacyResult = $this->createOrUpdateLegacyOvokoListing($part, $ovokoId, $newUrl, $reattach);
                                $listing = $legacyResult['listing'];
                                $appliedListing = $listing;
                                $reattachChanges = $legacyResult['changes'];
                                if ($legacyResult['action'] === 'updated_existing_ovoko_listing') {
                                    $summary['updated_existing_listing']++;
                                } elseif ($legacyResult['action'] === 'restored_existing_ovoko_listing') {
                                    $summary['restored_listing']++;
                                }
                                $actions[] = $legacyResult['action'];
                                $this->logGeneratedUrl($listing, $ovokoId, $newUrl);
                            } catch (\Throwable $exception) {
                                $summary['errors']++;
                                $errors[] = $exception->getMessage();
                            }
                        }
                    }
                } elseif ($existingMatch !== null && ! $reattach) {
                    $summary['conflict_existing_ovoko_listing']++;
                    $summary['skipped']++;
                    $actions[] = 'conflict_existing_ovoko_listing';
                } else {
                    $reattachBlocked = false;
                    if ($existingMatch !== null && $reattach) {
                        $reattachDecision = $this->canReattachExistingOvokoListing($existingMatch, $part, $ovokoId);
                        $reattachChanges['part_id'] = ['from' => $existingMatch->part_id, 'to' => $part->id, 'reason' => $reattachDecision['reason']];
                        $actions[] = $reattachDecision['allowed'] ? ($apply ? 'reattach_existing_ovoko_listing' : 'would_reattach_existing_ovoko_listing') : 'reattach_not_allowed';
                        $reattachBlocked = ! $reattachDecision['allowed'];
                        if (! $reattachBlocked) {
                            $summary['would_reattach_listing']++;
                        }
                    }

                    if ($reattachBlocked) {
                        $summary['conflict_existing_ovoko_listing']++;
                        $summary['skipped']++;
                        $actions[] = 'conflict_existing_ovoko_listing';
                    } else {
                        $summary['matched']++;
                        if ($existingMatch === null) {
                            $summary['would_create_listing']++;
                            $actions[] = $apply ? 'create_listing_from_legacy_ovoko_id' : 'would_create_listing_from_legacy_ovoko_id';
                        }

                        if ($apply) {
                            try {
                                $legacyResult = $this->createOrUpdateLegacyOvokoListing($part, $ovokoId, $newUrl, $reattach);
                                $listing = $legacyResult['listing'];
                                $appliedListing = $listing;
                                $reattachChanges = $legacyResult['changes'];
                                if ($legacyResult['action'] === 'created_listing_from_legacy_ovoko_id') {
                                    $summary['created_listing']++;
                                } elseif ($legacyResult['action'] === 'reattached_existing_ovoko_listing') {
                                    $summary['reattached_listing']++;
                                } elseif ($legacyResult['action'] === 'restored_existing_ovoko_listing') {
                                    $summary['restored_listing']++;
                                }
                                $actions[] = $legacyResult['action'];
                                $this->logGeneratedUrl($listing, $ovokoId, $newUrl);
                            } catch (\Throwable $exception) {
                                $summary['errors']++;
                                $errors[] = $exception->getMessage();
                                if ($exception->getMessage() === 'conflict_existing_ovoko_listing') {
                                    $summary['conflict_existing_ovoko_listing']++;
                                }
                            }
                        }
                    }
                }
            }

            $existingMatch = $ovokoId !== null ? $this->findExistingOvokoListingByLegacyId($ovokoId) : null;

            $results[] = [
                'part_id' => $part->id,
                'marketplace_listing_id' => $appliedListing?->id ?? $existingMatch?->id,
                'ovoko_id' => $ovokoId,
                'ovoko_id_source' => $source['source'],
                'ovoko_id_path' => $source['path'],
                'old_url' => null,
                'new_url' => $newUrl,
                'old_price' => $part->ovoko_price,
                'new_price' => $part->ovoko_price,
                'action' => implode(',', array_values(array_unique($actions))),
                'errors' => $errors,
                'existing_ovoko_listing' => $existingMatch ? $this->listingDiagnosticRow($existingMatch) : null,
                'requested_part_id' => $part->id,
                'existing_listing_part_id' => $existingMatch?->part_id,
                'reattach_requested' => $reattach,
                'reattach_allowed' => $existingMatch ? $this->canReattachExistingOvokoListing($existingMatch, $part, $ovokoId)['allowed'] : null,
                'reattach_reason' => $existingMatch ? $this->canReattachExistingOvokoListing($existingMatch, $part, $ovokoId)['reason'] : null,
                'reattach_changes' => $reattachChanges,
            ];
        }

        return [
            'mode' => $apply ? 'apply' : 'dry_run',
            'summary' => $summary,
            'results' => $partId !== null ? $results : array_slice($results, 0, 50),
            'warnings' => [],
            'limit_requested' => $limit,
            'limit_applied' => $limit,
            'offset_requested' => $offset,
            'offset_applied' => $offset,
            'debug' => $debug ? $this->browserBackfillDebug($partId, $force, $missingOnly, $apply, $limit, $offset, $includeInactive, $endedStatuses) : null,
        ];
    }

    /** @return array{mode:string,summary:array<string,int>,results:array<int,array<string,mixed>>,warnings:array<int,string>} */
    public function runLocalGeneratedBulk(bool $apply = false, int $limit = 100, int $offset = 0, bool $onlyMissing = false, bool $includeExistingInvalid = false): array
    {
        if (! Schema::hasTable('marketplace_listings')) {
            throw new \RuntimeException('Required table marketplace_listings does not exist.');
        }

        $limit = max(1, $limit);
        $offset = max(0, $offset);

        $summary = [
            'inspected' => 0,
            'already_has_url' => 0,
            'missing_url_with_valid_ovoko_id' => 0,
            'would_generate' => 0,
            'would_update' => 0,
            'updated' => 0,
            'invalid_ovoko_id' => 0,
            'missing_ovoko_id' => 0,
            'suspicious_existing_url' => 0,
            'image_url_rejected' => 0,
            'skipped' => 0,
        ];
        $results = [];

        $baseQuery = MarketplaceListing::query()
            ->where('marketplace', 'ovoko');
        $totalOvokoListingsCount = (clone $baseQuery)->count();
        $totalOvokoMissingUrlCount = (clone $baseQuery)
            ->where(fn ($query) => $query->whereNull('url')->orWhere('url', ''))
            ->count();

        $query = (clone $baseQuery)
            ->with('part:id,legacy_payload')
            ->orderBy('marketplace_listings.id', 'asc')
            ->offset($offset)
            ->limit($limit);

        $inspectedListingIds = [];

        foreach ($query->get() as $listing) {
            $inspectedListingIds[] = (int) $listing->id;
            $summary['inspected']++;
            $existingUrl = $this->blankNull($listing->url);
            $existingValidation = $existingUrl !== null ? $this->validateShopUrl($existingUrl) : null;
            $ovokoId = $this->existingOvokoId($listing);
            $generatedUrl = $this->generatedShopUrlFromOvokoPartId($ovokoId);
            $action = 'skipped_has_url';
            $reason = 'Listing already has a valid Ovoko/RRR marketplace URL.';

            if ($existingUrl !== null && ($existingValidation['valid'] ?? false) === true) {
                $summary['already_has_url']++;
                $summary['skipped']++;
                if ($onlyMissing) {
                    continue;
                }
            } else {
                if ($existingUrl !== null) {
                    $summary['suspicious_existing_url']++;
                    if (($existingValidation['reason'] ?? null) === 'image_url_not_listing_url') {
                        $summary['image_url_rejected']++;
                    }
                }

                if ($ovokoId === null) {
                    $summary['missing_ovoko_id']++;
                    $summary['skipped']++;
                    $action = 'missing_ovoko_id';
                    $reason = 'No local Ovoko part ID found in listing identifiers or payload.';
                } elseif ($generatedUrl === null) {
                    $summary['invalid_ovoko_id']++;
                    $summary['skipped']++;
                    $action = 'invalid_ovoko_id';
                    $reason = 'Ovoko part ID is not numeric; local parts.id is never used as a fallback.';
                } elseif ($existingUrl !== null && ! $includeExistingInvalid) {
                    $summary['skipped']++;
                    $action = 'rejected_existing_url';
                    $reason = 'Existing URL is suspicious; pass include_existing_invalid=1 to include it in local apply candidates.';
                } else {
                    $summary['missing_url_with_valid_ovoko_id']++;
                    $summary['would_generate']++;
                    $summary['would_update']++;
                    $action = $apply ? 'updated' : 'would_update';
                    $reason = $existingUrl === null ? 'Missing URL and numeric ovoko_part_id is available.' : 'Suspicious existing URL can be replaced from numeric ovoko_part_id.';

                    if ($apply) {
                        $validation = $this->validateShopUrl($generatedUrl);
                        if ($validation['valid'] && preg_match('/^\d+$/', (string) $ovokoId) === 1) {
                            $listing->url = $generatedUrl;
                            $listing->save();
                            $summary['updated']++;
                            $this->logGeneratedUrl($listing, (string) $ovokoId, $generatedUrl);
                        }
                    }
                }
            }

            $results[] = [
                'marketplace_listing_id' => $listing->id,
                'local_part_id' => $listing->part_id,
                'ovoko_part_id' => $ovokoId,
                'existing_url' => $existingUrl,
                'generated_url' => $generatedUrl,
                'action' => $action,
                'reason' => $reason,
            ];
        }

        return [
            'mode' => $apply ? 'apply' : 'dry_run',
            'summary' => $summary,
            'results' => array_slice($results, 0, 20),
            'warnings' => [],
            'limit_requested' => $limit,
            'limit_applied' => $limit,
            'offset_requested' => $offset,
            'offset_applied' => $offset,
            'first_inspected_listing_id' => $inspectedListingIds[0] ?? null,
            'last_inspected_listing_id' => $inspectedListingIds === [] ? null : $inspectedListingIds[array_key_last($inspectedListingIds)],
            'inspected_listing_ids_sample' => array_slice($inspectedListingIds, 0, 10),
            'total_ovoko_listings_count' => $totalOvokoListingsCount,
            'total_ovoko_missing_url_count' => $totalOvokoMissingUrlCount,
            'only_missing_semantics' => 'only_missing=1 does not filter the bulk query; it inspects the requested deterministic id range and only suppresses update candidates/results for listings that already have a valid Ovoko/RRR URL.',
        ];
    }

    /** @return array{mode:string,summary:array<string,int>,results:array<int,array<string,mixed>>,warnings:array<int,string>} */
    public function run(bool $apply = false, bool $force = false, ?int $partId = null, int $limit = 100, ?string $csvPath = null, ?int $listingId = null, int $maxPages = 3): array
    {
        if (! Schema::hasTable('marketplace_listings')) {
            throw new \RuntimeException('Required table marketplace_listings does not exist.');
        }

        $limit = max(1, $limit);
        $warnings = [];
        $csvRows = $csvPath ? $this->loadCsv($csvPath) : [];
        $client = null;

        if (! $csvPath) {
            try {
                $client = $this->apiManager->client('ovoko');
            } catch (\Throwable $exception) {
                $warnings[] = 'Ovoko API client unavailable; rows without local/CSV shop_url will report missing_shop_url: '.$exception->getMessage();
            }
        }

        $query = MarketplaceListing::query()
            ->with('part:id,external_id,legacy_payload,name,sku,part_number,oem_number,ovoko_price,currency,status,quantity')
            ->where('marketplace', 'ovoko')
            ->whereNotNull('part_id')
            ->orderBy('id')
            ->limit($limit);

        if ($partId !== null) {
            $query->where('part_id', $partId);
        }
        if ($listingId !== null) {
            $query->whereKey($listingId);
        }

        $results = [];
        $summary = ['inspected' => 0, 'updated' => 0, 'would_update' => 0, 'skipped' => 0, 'missing_shop_url' => 0, 'ambiguous' => 0, 'missing_part_ovoko_price' => 0, 'would_update_price' => 0, 'updated_price' => 0];

        foreach ($query->get() as $listing) {
            $summary['inspected']++;
            $ovokoId = $this->existingOvokoId($listing);
            $existingUrl = $this->blankNull($listing->url);
            $resolved = null;
            $source = 'skipped';
            $action = 'missing_shop_url';
            $diagnostics = $this->emptyDiagnostics();

            if ($existingUrl !== null && ! $force) {
                $action = 'skipped_has_url';
                $summary['skipped']++;
            } elseif ($ovokoId === null) {
                $action = 'missing_ovoko_id';
                $summary['skipped']++;
            } else {
                [$resolved, $source, $action, $diagnostics] = $this->resolveShopUrl($listing, $ovokoId, $csvRows, $client, $maxPages);

                if ($action === 'would_update') {
                    if ($apply) {
                        $listing->url = $resolved;
                        if ($this->blankNull($listing->external_offer_id) === null && $this->blankNull($ovokoId) !== null) {
                            $listing->external_offer_id = $ovokoId;
                        }
                        $listing->save();
                        if ($source === 'generated_from_ovoko_part_id') {
                            $this->logGeneratedUrl($listing, $ovokoId, $resolved);
                        }
                        $action = 'updated';
                        $summary['updated']++;
                    } else {
                        $summary['would_update']++;
                    }
                } elseif ($action === 'ambiguous') {
                    $summary['ambiguous']++;
                } else {
                    $summary['missing_shop_url']++;
                    if (in_array(($diagnostics['ovoko_read_api_rejection_reason'] ?? null), ['part_detail_not_found_on_known_read_only_endpoints_csv_export_required', 'detail_id_mismatch'], true)) {
                        $warning = 'Ovoko read-only API did not return a matching shop_url by part ID or external_id; backfilling older links requires a CSV export from Ovoko.';
                        if (! in_array($warning, $warnings, true)) $warnings[] = $warning;
                    }
                }
            }

            $priceAction = $this->backfillPartOvokoPrice($listing, $apply);
            if ($priceAction['candidate']) {
                $summary['missing_part_ovoko_price']++;
                $summary['would_update_price']++;
                if ($apply && $priceAction['updated']) {
                    $summary['updated_price']++;
                }
            }

            $results[] = [
                'local_part_id' => $listing->part_id,
                'marketplace_listing_id' => $listing->id,
                'existing_ovoko_id' => $ovokoId ?? '',
                'requested_ovoko_id' => $diagnostics['requested_ovoko_id'],
                'requested_external_id' => $diagnostics['requested_external_id'],
                'lookup_by' => $diagnostics['lookup_by'],
                'existing_url' => $existingUrl ?? '',
                'resolved_shop_url' => $resolved,
                'source' => $source,
                'action' => $action,
                'rejected_local_url' => $diagnostics['rejected_local_url'],
                'rejected_local_url_reason' => $diagnostics['rejected_local_url_reason'],
                'accepted_shop_url_host' => $diagnostics['accepted_shop_url_host'],
                'ovoko_read_api_attempted' => $diagnostics['ovoko_read_api_attempted'],
                'ovoko_read_api_endpoint' => $diagnostics['ovoko_read_api_endpoint'],
                'ovoko_read_api_status' => $diagnostics['ovoko_read_api_status'],
                'ovoko_read_api_response_keys' => $diagnostics['ovoko_read_api_response_keys'],
                'ovoko_read_api_shop_url_found' => $diagnostics['ovoko_read_api_shop_url_found'],
                'ovoko_read_api_rejection_reason' => $diagnostics['ovoko_read_api_rejection_reason'],
                'ovoko_read_api_request_fields' => $diagnostics['ovoko_read_api_request_fields'],
                'returned_candidates_count' => $diagnostics['returned_candidates_count'],
                'matched_candidate_index' => $diagnostics['matched_candidate_index'],
                'matched_candidate_id' => $diagnostics['matched_candidate_id'],
                'matched_candidate_external_id' => $diagnostics['matched_candidate_external_id'],
                'matched_candidate_shop_url' => $diagnostics['matched_candidate_shop_url'],
                'mismatch_sample_ids' => $diagnostics['mismatch_sample_ids'],
                'returned_pagination' => $diagnostics['returned_pagination'],
                'returned_pagination_count' => $diagnostics['returned_pagination_count'],
                'ovoko_read_api_attempts' => $diagnostics['ovoko_read_api_attempts'],
                'resolution_attempts' => $diagnostics['resolution_attempts'],
                'generated_rule' => $diagnostics['generated_rule'],
                'price_backfill_action' => $priceAction['action'],
                'listing_price' => $listing->price,
                'part_ovoko_price' => $listing->part?->ovoko_price,
            ];
        }

        return ['mode' => $apply ? 'apply' : 'dry_run', 'summary' => $summary, 'results' => $results, 'warnings' => $warnings];
    }

    private function browserBackfillDebug(?int $partId, bool $force, bool $missingOnly, bool $apply, int $limit, int $offset, bool $includeInactive, array $endedStatuses): array
    {
        $debug = [
            'parsed' => compact('partId', 'force', 'missingOnly', 'apply', 'limit', 'offset', 'includeInactive'),
            'global' => [],
            'part_exists' => null,
            'part' => null,
            'marketplace_listings_for_part' => [],
            'listing_filter_diagnostics' => [],
            'panel_status_source' => 'PartMarketplaceStatusResolver reads the part->marketplaceListings relation, filters marketplace ovoko, and takes external_offer_id/external_listing_id from marketplace_listings.',
            'legacy_ovoko_id_sources' => [],
            'existing_ovoko_listings_by_legacy_id' => [],
        ];

        $debug['global'] = [
            'marketplace_listings_count' => DB::table('marketplace_listings')->count(),
            'marketplace_like_ovoko_count' => DB::table('marketplace_listings')->where('marketplace', 'like', '%ovoko%')->count(),
            'marketplace_values_like_ovoko' => DB::table('marketplace_listings')->select('marketplace', DB::raw('COUNT(*) as count'))->where('marketplace', 'like', '%ovoko%')->groupBy('marketplace')->orderBy('marketplace')->limit(50)->get()->map(fn ($r) => (array) $r)->all(),
            'with_external_offer_id_count' => DB::table('marketplace_listings')->whereRaw("NULLIF(TRIM(COALESCE(external_offer_id, '')), '') IS NOT NULL")->count(),
            'with_external_listing_id_count' => DB::table('marketplace_listings')->whereRaw("NULLIF(TRIM(COALESCE(external_listing_id, '')), '') IS NOT NULL")->count(),
            'empty_url_count' => DB::table('marketplace_listings')->where(fn ($q) => $q->whereNull('url')->orWhere('url', ''))->count(),
            'with_price_count' => DB::table('marketplace_listings')->whereNotNull('price')->count(),
            'ovoko_empty_url_count' => DB::table('marketplace_listings')->where('marketplace', 'like', '%ovoko%')->where(fn ($q) => $q->whereNull('url')->orWhere('url', ''))->count(),
            'ovoko_empty_parts_ovoko_price_count' => DB::table('marketplace_listings')->join('parts', 'parts.id', '=', 'marketplace_listings.part_id')->where('marketplace_listings.marketplace', 'like', '%ovoko%')->whereNull('parts.ovoko_price')->count(),
        ];

        if ($partId === null) return $debug;

        $part = Part::query()->select(['id', 'source_system', 'external_id', 'legacy_payload', 'name', 'sku', 'part_number', 'oem_number', 'ovoko_price', 'currency', 'status', 'quantity', 'is_visible_storefront', 'needs_listing'])->find($partId);
        $debug['part_exists'] = $part !== null;
        $debug['part'] = $part?->toArray();
        $debug['legacy_ovoko_id_sources'] = $part ? $this->partOvokoIdSources($part) : [];
        $legacyOvokoId = $part ? ($this->ovokoIdFromPart($part)['id'] ?? null) : null;
        if ($legacyOvokoId !== null) {
            $debug['existing_ovoko_listings_by_legacy_id'] = $this->findExistingOvokoListingsByLegacyId($legacyOvokoId)
                ->map(fn (MarketplaceListing $listing): array => $this->listingDiagnosticRow($listing))
                ->all();
        }

        $listings = MarketplaceListing::query()->with('part:id,status,quantity,ovoko_price')->where('part_id', $partId)->orderBy('id')->get();
        $debug['marketplace_listings_for_part'] = $listings->map(fn (MarketplaceListing $listing): array => collect($listing->toArray())->only([
            'id', 'marketplace', 'part_id', 'external_offer_id', 'external_listing_id', 'price', 'currency', 'status', 'sync_status', 'match_status', 'url', 'created_at', 'updated_at',
        ])->all())->all();

        foreach ($listings as $listing) {
            $ovokoId = $this->existingOvokoId($listing);
            $oldUrl = $this->blankNull($listing->url);
            $newPrice = is_numeric($listing->price) ? (float) $listing->price : null;
            $rejections = [];
            if (! str_contains(strtolower((string) $listing->marketplace), 'ovoko')) $rejections[] = 'marketplace';
            if ($ovokoId === null) $rejections[] = 'missing_external_offer_id_or_external_listing_id';
            if (! $includeInactive && (in_array($listing->status, $endedStatuses, true) || in_array($listing->last_api_status, $endedStatuses, true) || ! in_array($listing->part?->status, ['ready', 'published'], true) || ($listing->part?->quantity !== null && (int) $listing->part->quantity <= 0))) $rejections[] = 'status_sync_match_or_part_activity';
            if ($missingOnly && ! $force && $oldUrl !== null && is_numeric($listing->part?->ovoko_price)) $rejections[] = 'missing_only';
            if ($newPrice === null) $rejections[] = 'missing_price_for_price_backfill';
            if (! $listing->part) $rejections[] = 'missing_related_part';
            $debug['listing_filter_diagnostics'][] = [
                'id' => $listing->id,
                'ovoko_id_seen_by_backfill' => $ovokoId,
                'generated_url' => $this->generatedShopUrlFromOvokoPartId($ovokoId, $listing),
                'qualifies_for_scan' => ! in_array('marketplace', $rejections, true) && ! in_array('missing_external_offer_id_or_external_listing_id', $rejections, true) && ($includeInactive || ! in_array('status_sync_match_or_part_activity', $rejections, true)) && ! in_array('missing_only', $rejections, true) && ! in_array('missing_related_part', $rejections, true),
                'filter_rejections' => $rejections,
            ];
        }

        if ($listings->isEmpty()) {
            $debug['listing_filter_diagnostics'][] = ['part_id' => $partId, 'qualifies_for_scan' => false, 'filter_rejections' => ['no_marketplace_listings_for_part']];
        }

        return $debug;
    }


    /** @return array<int, Part> */
    private function legacyPartCandidates(?int $partId, int $limit, int $offset, bool $includeInactive, array $excludePartIds = []): array
    {
        $query = Part::query()->select(['id', 'source_system', 'external_id', 'legacy_payload', 'name', 'sku', 'part_number', 'ovoko_price', 'currency', 'status', 'quantity'])
            ->whereNotIn('id', array_values(array_unique($excludePartIds)));

        if ($partId !== null) {
            $query->whereKey($partId);
        } else {
            $query->offset($offset)->limit($limit);
        }

        if (! $includeInactive) {
            $query->whereIn('status', ['ready', 'published'])
                ->where(fn ($q) => $q->whereNull('quantity')->orWhere('quantity', '>', 0));
        }

        return $query->orderBy('id')->get()->filter(fn (Part $part): bool => ($this->ovokoIdFromPart($part)['id'] ?? null) !== null)->values()->all();
    }

    /** @return array{id:?string,source:?string,path:?string,all:array<int,array<string,mixed>>} */
    public function ovokoIdFromPart(Part $part): array
    {
        $sources = $this->partOvokoIdSources($part);
        foreach ($sources as $source) {
            if (($source['valid_numeric'] ?? false) === true) {
                return ['id' => (string) $source['value'], 'source' => (string) $source['source'], 'path' => $source['path'] ?? null, 'all' => $sources];
            }
        }
        return ['id' => null, 'source' => null, 'path' => null, 'all' => $sources];
    }

    /** @return array<int,array<string,mixed>> */
    public function partOvokoIdSources(Part $part): array
    {
        $sources = [];
        if (Schema::hasColumn('parts', 'source_system') && Schema::hasColumn('parts', 'external_id') && strtolower((string) $part->source_system) === 'ovoko') {
            $sources[] = $this->sourceRow('parts.external_id', null, $part->external_id);
        }
        if (Schema::hasColumn('parts', 'legacy_payload')) {
            $match = $this->extractor->extractWithPath($part->legacy_payload ?? null);
            $sources[] = $this->sourceRow('parts.legacy_payload', $match['path'] ?? null, $match['id'] ?? null);
        }
        return $sources;
    }

    private function sourceRow(string $source, ?string $path, mixed $value): array
    {
        $value = $this->blankNull($value);
        return ['source' => $source, 'path' => $path, 'value' => $value, 'valid_numeric' => $value !== null && preg_match('/^\d+$/', $value) === 1, 'generated_url' => $this->generatedShopUrlFromOvokoPartId($value)];
    }

    /** @return array{listing:MarketplaceListing,action:string,changes:array<string,mixed>} */
    private function createOrUpdateLegacyOvokoListing(Part $part, string $ovokoId, string $url, bool $reattach = false): array
    {
        $account = MarketplaceAccount::query()->firstOrCreate(
            ['code' => 'ovoko_main'],
            ['marketplace' => 'ovoko', 'name' => 'Ovoko main', 'status' => 'active']
        );

        $listing = $this->findExistingOvokoListingByLegacyId($ovokoId);
        $action = 'created_listing_from_legacy_ovoko_id';
        $changes = [];

        if ($listing !== null) {
            if ((int) $listing->part_id !== (int) $part->id) {
                $reattachDecision = $this->canReattachExistingOvokoListing($listing, $part, $ovokoId);
                if (! $reattach || ! $reattachDecision['allowed']) {
                    throw new \RuntimeException('conflict_existing_ovoko_listing');
                }

                $changes['part_id'] = ['from' => $listing->part_id, 'to' => $part->id, 'reason' => $reattachDecision['reason']];
                $listing->part_id = $part->id;
                $action = 'reattached_existing_ovoko_listing';
            } else {
                $action = 'updated_existing_ovoko_listing';
            }
        } else {
            $listing = MarketplaceListing::query()->firstOrNew(['marketplace' => 'ovoko', 'part_id' => $part->id]);
        }

        if (Schema::hasColumn('marketplace_listings', 'deleted_at') && $listing->getAttribute('deleted_at') !== null) {
            $changes['deleted_at'] = ['from' => $listing->getAttribute('deleted_at'), 'to' => null];
            $listing->setAttribute('deleted_at', null);
            $action = $action === 'reattached_existing_ovoko_listing' ? $action : 'restored_existing_ovoko_listing';
        }

        $raw = is_array($listing->raw_payload) ? $listing->raw_payload : [];
        $raw['legacy_ovoko_id_backfill'] = ['source' => 'parts_legacy_ovoko_id', 'ovoko_part_id' => $ovokoId, 'url' => $url, 'mapped_at' => now()->toISOString()];
        $listing->forceFill([
            'marketplace_account_id' => $listing->marketplace_account_id ?: $account->id,
            'external_offer_id' => $ovokoId,
            'external_listing_id' => $ovokoId,
            'sku' => $part->sku,
            'title' => $part->name,
            'price' => is_numeric($part->ovoko_price) ? (float) $part->ovoko_price : null,
            'quantity' => is_numeric($part->quantity) ? (int) $part->quantity : null,
            'currency' => $part->currency ?: 'PLN',
            'status' => 'imported',
            'sync_status' => 'mapped',
            'match_status' => 'confirmed',
            'match_confidence' => 100,
            'match_reason' => 'legacy_ovoko_id_backfill',
            'url' => $url,
            'raw_payload' => $raw,
            'last_error' => null,
            'last_synced_at' => now(),
        ])->save();

        return ['listing' => $listing, 'action' => $action, 'changes' => $changes];
    }

    private function findExistingOvokoListingByLegacyId(string $ovokoId): ?MarketplaceListing
    {
        return $this->findExistingOvokoListingsByLegacyId($ovokoId)->first();
    }

    private function findExistingOvokoListingsByLegacyId(string $ovokoId): \Illuminate\Support\Collection
    {
        $urlNeedle = '%hgf'.$ovokoId.'%';
        $query = MarketplaceListing::query();
        if (Schema::hasColumn('marketplace_listings', 'deleted_at') && in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive(MarketplaceListing::class), true)) {
            $query->withTrashed();
        }

        return $query
            ->where('marketplace', 'like', '%ovoko%')
            ->where(fn ($query) => $query
                ->where('external_offer_id', $ovokoId)
                ->orWhere('external_listing_id', $ovokoId)
                ->orWhere('url', 'like', $urlNeedle)
            )
            ->orderByRaw('CASE WHEN external_offer_id = ? THEN 0 WHEN external_listing_id = ? THEN 1 ELSE 2 END', [$ovokoId, $ovokoId])
            ->orderBy('id')
            ->get();
    }

    /** @return array{allowed:bool,reason:string} */
    private function canReattachExistingOvokoListing(MarketplaceListing $listing, Part $targetPart, string $ovokoId): array
    {
        if ($listing->part_id === null) {
            return ['allowed' => true, 'reason' => 'existing_listing_has_no_part_id'];
        }

        $targetOvokoId = $this->ovokoIdFromPart($targetPart)['id'] ?? null;
        if ($targetOvokoId === $ovokoId) {
            return ['allowed' => true, 'reason' => 'target_part_has_matching_legacy_ovoko_id'];
        }

        return ['allowed' => false, 'reason' => 'existing_listing_has_different_part_and_target_part_legacy_ovoko_id_does_not_match'];
    }

    private function listingDiagnosticRow(MarketplaceListing $listing): array
    {
        return collect($listing->toArray())->only([
            'id', 'part_id', 'status', 'sync_status', 'match_status', 'url', 'price', 'title', 'created_at', 'updated_at',
        ])->put('listing_id', $listing->id)->all();
    }

    private function existingOvokoId(MarketplaceListing $listing): ?string
    {
        foreach (['external_offer_id', 'external_listing_id', 'external_inventory_id', 'tracking_id'] as $column) {
            if (Schema::hasColumn('marketplace_listings', $column)) {
                $value = $this->blankNull($listing->{$column} ?? null);
                if ($value !== null) return $value;
            }
        }

        $fromRawPayload = $this->extractor->extract($listing->raw_payload ?? null);
        if ($fromRawPayload !== null) return $fromRawPayload;

        return $this->extractor->extract($listing->part?->legacy_payload ?? null);
    }

    private function resolveShopUrl(MarketplaceListing $listing, string $ovokoId, array $csvRows, mixed $client, int $maxPages): array
    {
        $diagnostics = $this->emptyDiagnostics();
        $externalId = $this->blankNull($listing->part?->external_id ?? null) ?? 'gps-part-'.(string) $listing->part_id;
        $diagnostics['requested_ovoko_id'] = $ovokoId;
        $diagnostics['requested_external_id'] = $externalId;
        $diagnostics['lookup_by'] = $externalId !== null ? 'both' : 'ovoko_id';

        $local = $this->firstUrl($listing->raw_payload ?? []);
        if ($local !== null) {
            $diagnostics['resolution_attempts'][] = 'local';
            $validation = $this->validateShopUrl($local);
            if ($validation['valid']) {
                $diagnostics['accepted_shop_url_host'] = $validation['host'];
                return [$local, 'local', 'would_update', $diagnostics];
            }

            $diagnostics['rejected_local_url'] = $local;
            $diagnostics['rejected_local_url_reason'] = $validation['reason'];
        }

        if ($client !== null && (method_exists($client, 'fetchPartRawByLookup') || method_exists($client, 'fetchPartRawById'))) {
            $diagnostics['resolution_attempts'][] = 'ovoko_read_api';
            $diagnostics['ovoko_read_api_attempted'] = true;

            try {
                $result = method_exists($client, 'fetchPartRawByLookup')
                    ? $client->fetchPartRawByLookup($ovokoId, $externalId, $maxPages)
                    : $client->fetchPartRawById($ovokoId, $maxPages);
                $diagnostics['ovoko_read_api_endpoint'] = $result['endpoint_used'] ?? data_get($result, 'attempts.0.endpoint');
                $diagnostics['ovoko_read_api_status'] = $result['api_status_code'] ?? $result['http_status'] ?? data_get($result, 'attempts.0.api_status_code') ?? data_get($result, 'attempts.0.http_status');
                $diagnostics['ovoko_read_api_response_keys'] = $result['response_top_level_keys'] ?? data_get($result, 'attempts.0.top_level_keys') ?? [];
                $diagnostics['ovoko_read_api_request_fields'] = $result['request_fields'] ?? data_get($result, 'attempts.0.request_fields') ?? [];
                $diagnostics['ovoko_read_api_attempts'] = $result['attempts'] ?? [];
                foreach (['returned_candidates_count','matched_candidate_index','matched_candidate_id','matched_candidate_external_id','matched_candidate_shop_url','mismatch_sample_ids','returned_pagination','returned_pagination_count'] as $key) {
                    $diagnostics[$key] = $result[$key] ?? $diagnostics[$key];
                }

                $url = $this->firstUrl($result['raw'] ?? []) ?? $this->blankNull($result['normalized']['url'] ?? null);
                $diagnostics['ovoko_read_api_shop_url_found'] = $url !== null;

                if (($result['api_ok'] ?? false) && $url !== null) {
                    $validation = $this->validateShopUrl($url);
                    if ($validation['valid']) {
                        $diagnostics['accepted_shop_url_host'] = $validation['host'];
                        return [$url, 'ovoko_read_api', 'would_update', $diagnostics];
                    }
                    $diagnostics['ovoko_read_api_rejection_reason'] = $validation['reason'];
                } elseif (! ($result['api_ok'] ?? false)) {
                    $diagnostics['ovoko_read_api_rejection_reason'] = $result['error'] ?? 'api_not_ok';
                }
            } catch (\Throwable $exception) {
                $diagnostics['ovoko_read_api_rejection_reason'] = $exception->getMessage();
            }
        }

        if ($csvRows !== []) {
            $diagnostics['resolution_attempts'][] = 'csv';
            $match = $this->matchCsv($csvRows, $listing, $ovokoId);
            if (($match['ambiguous'] ?? false) === true) return [null, 'csv', 'ambiguous', $diagnostics];
            if (($match['shop_url'] ?? null) !== null) {
                $validation = $this->validateShopUrl($match['shop_url']);
                if ($validation['valid']) {
                    $diagnostics['accepted_shop_url_host'] = $validation['host'];
                    return [$match['shop_url'], 'csv', 'would_update', $diagnostics];
                }

                return [null, 'csv', 'missing_shop_url', $diagnostics];
            }
        }

        $generatedUrl = $this->generatedShopUrlFromOvokoPartId($ovokoId, $listing);
        if ($generatedUrl !== null) {
            $diagnostics['resolution_attempts'][] = 'generated_from_ovoko_part_id';
            $diagnostics['generated_rule'] = 'https://ovoko.pl/czesci-samochodowe/hgf{ovoko_part_id}';
            $diagnostics['accepted_shop_url_host'] = 'ovoko.pl';
            return [$generatedUrl, 'generated_from_ovoko_part_id', 'would_update', $diagnostics];
        }

        return [null, $diagnostics['rejected_local_url'] !== null ? 'skipped/local_invalid' : ($diagnostics['ovoko_read_api_attempted'] ? 'ovoko_read_api' : ($csvRows !== [] ? 'csv' : 'skipped')), 'missing_shop_url', $diagnostics];
    }

    private function emptyDiagnostics(): array
    {
        return [
            'rejected_local_url' => null,
            'rejected_local_url_reason' => null,
            'accepted_shop_url_host' => null,
            'ovoko_read_api_attempted' => false,
            'ovoko_read_api_endpoint' => null,
            'ovoko_read_api_status' => null,
            'ovoko_read_api_response_keys' => [],
            'ovoko_read_api_shop_url_found' => false,
            'ovoko_read_api_rejection_reason' => null,
            'ovoko_read_api_request_fields' => [],
            'ovoko_read_api_attempts' => [],
            'resolution_attempts' => [],
            'requested_ovoko_id' => null,
            'requested_external_id' => null,
            'lookup_by' => null,
            'returned_candidates_count' => 0,
            'matched_candidate_index' => null,
            'matched_candidate_id' => null,
            'matched_candidate_external_id' => null,
            'matched_candidate_shop_url' => null,
            'mismatch_sample_ids' => [],
            'returned_pagination' => null,
            'returned_pagination_count' => null,
            'generated_rule' => null,
        ];
    }

    private function loadCsv(string $path): array
    {
        if (! is_readable($path)) {
            throw new \RuntimeException('CSV file is not readable: '.$path);
        }
        $handle = fopen($path, 'rb');
        $headers = fgetcsv($handle) ?: [];
        $headers = array_map(fn ($h) => trim((string) $h), $headers);
        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            $row = array_combine($headers, array_pad($data, count($headers), null));
            if (is_array($row)) $rows[] = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $row);
        }
        fclose($handle);
        return $rows;
    }

    private function matchCsv(array $rows, MarketplaceListing $listing, string $ovokoId): array
    {
        $externalId = $this->blankNull($listing->part?->external_id ?? null);
        foreach ([
            fn ($r) => $externalId !== null && $this->blankNull($r['external_id'] ?? null) === $externalId,
            fn ($r) => $this->blankNull($r['local_part_id'] ?? null) === (string) $listing->part_id && $this->blankNull($r['ovoko_part_id'] ?? null) === $ovokoId,
            fn ($r) => $this->blankNull($r['ovoko_part_id'] ?? null) === $ovokoId,
            fn ($r) => $this->blankNull($r['local_part_id'] ?? null) === (string) $listing->part_id,
        ] as $matcher) {
            $matches = array_values(array_filter($rows, $matcher));
            if (count($matches) > 1) return ['ambiguous' => true];
            if (count($matches) === 1) return ['shop_url' => $this->blankNull($matches[0]['shop_url'] ?? null)];
        }
        return [];
    }

    private function logGeneratedUrl(MarketplaceListing $listing, string $ovokoId, ?string $generatedUrl): void
    {
        if ($generatedUrl === null) {
            return;
        }

        $this->logger->success('ovoko', 'ovoko_listing_url_generated', 'Ovoko listing URL generated from ovoko_part_id fallback rule.', [
            'marketplace_listing_id' => $listing->id,
            'part_id' => $listing->part_id,
            'external_id' => $ovokoId,
            'request' => [
                'marketplace_listing_id' => $listing->id,
                'local_part_id' => $listing->part_id,
                'ovoko_part_id' => $ovokoId,
            ],
            'response' => [
                'generated_url' => $generatedUrl,
                'source' => 'generated_from_ovoko_part_id',
                'ovoko_part_id' => $ovokoId,
                'ovoko_listing_url' => $generatedUrl,
                'ovoko_listing_url_source' => 'generated_from_ovoko_part_id',
                'generated_rule' => 'https://ovoko.pl/czesci-samochodowe/hgf{ovoko_part_id}',
            ],
        ]);
    }

    private function generatedShopUrlFromOvokoPartId(?string $ovokoId, ?MarketplaceListing $listing = null): ?string
    {
        $ovokoId = $this->blankNull($ovokoId);
        if ($ovokoId === null || preg_match('/^\d+$/', $ovokoId) !== 1) {
            return null;
        }

        return 'https://ovoko.pl/czesci-samochodowe/hgf'.$ovokoId;
    }

    /** @return array{candidate:bool,updated:bool,action:string} */
    private function backfillPartOvokoPrice(MarketplaceListing $listing, bool $apply): array
    {
        $part = $listing->part;
        if (! $part || is_numeric($part->ovoko_price) || ! is_numeric($listing->price)) {
            return ['candidate' => false, 'updated' => false, 'action' => 'skipped'];
        }

        if ($apply) {
            $part->forceFill(['ovoko_price' => (float) $listing->price, 'currency' => $part->currency ?: ($listing->currency ?: 'PLN')])->save();
            return ['candidate' => true, 'updated' => true, 'action' => 'updated'];
        }

        return ['candidate' => true, 'updated' => false, 'action' => 'would_update'];
    }

    private function ovokoSlug(?MarketplaceListing $listing): string
    {
        $part = $listing?->part;
        $base = trim((string) (($part?->oem_number ?: $part?->part_number ?: $part?->sku ?: '').' '.($part?->name ?: $listing?->title ?: '')));
        $slug = str($base)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', '-')->trim('-')->toString();

        return $slug !== '' ? $slug : 'czesc';
    }

    private function validateShopUrl(string $url): array
    {
        $parts = parse_url($url);
        $host = isset($parts['host']) ? strtolower($parts['host']) : null;
        $path = $parts['path'] ?? '';

        if (! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || $host === null) {
            return ['valid' => false, 'reason' => 'invalid_url', 'host' => $host];
        }

        if ($host === 'gpswiss.pl' && str_starts_with($path, '/storage/parts/photos/')) {
            return ['valid' => false, 'reason' => 'image_url_not_listing_url', 'host' => $host];
        }

        if (preg_match('/\.(?:jpe?g|png|gif|webp|avif)(?:$|[?#])/i', $url) === 1) {
            return ['valid' => false, 'reason' => 'image_url_not_listing_url', 'host' => $host];
        }

        if (! $this->isOvokoMarketplaceHost($host)) {
            return ['valid' => false, 'reason' => 'invalid_host', 'host' => $host];
        }

        return ['valid' => true, 'reason' => null, 'host' => $host];
    }

    private function isOvokoMarketplaceHost(string $host): bool
    {
        return $host === 'ovoko.pl'
            || str_ends_with($host, '.ovoko.pl')
            || $host === 'ovoko.com'
            || str_ends_with($host, '.ovoko.com')
            || $host === 'rrr.lt'
            || str_ends_with($host, '.rrr.lt');
    }

    private function firstUrl(mixed $payload): ?string
    {
        if (! is_array($payload)) return null;
        foreach (['shop_url', 'url', 'link'] as $key) {
            $value = $this->blankNull($payload[$key] ?? null);
            if ($value !== null) return $value;
        }
        foreach ($payload as $value) {
            $found = $this->firstUrl($value);
            if ($found !== null) return $found;
        }
        return null;
    }

    private function blankNull(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
