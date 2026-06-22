<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Services\Marketplace\Api\MarketplaceApiManager;
use App\Services\Marketplace\Api\OvokoApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class OvokoPriceImportController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    public function check(Request $request, MarketplaceApiManager $manager): JsonResponse
    {
        if ($forbidden = $this->guard($request)) return $forbidden;
        $comparison = $this->buildComparison($request, $manager);

        return response()->json($comparison);
    }

    public function export(Request $request, MarketplaceApiManager $manager): StreamedResponse|JsonResponse
    {
        if ($forbidden = $this->guard($request)) return $forbidden;
        $comparison = $this->buildComparison($request, $manager, forceLimit: 10000);
        $rows = $comparison['rows_for_export'] ?? [];

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['part_id','part_number','name','ovoko_external_offer_id','storefront_price_pln','current_ovoko_price_pln','ovoko_api_price','ovoko_api_currency','ovoko_original_price','ovoko_original_currency','ovoko_api_price_pln','price_source','price_import_safe','difference','ovoko_title','ovoko_status','ovoko_quantity','action','notes']);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['part_id'], $row['part_number'], $row['name'], $row['ovoko_external_offer_id'],
                    $row['storefront_price_pln'], $row['current_ovoko_price_pln'], $row['ovoko_api_price'],
                    $row['ovoko_api_currency'], $row['ovoko_original_price'], $row['ovoko_original_currency'],
                    $row['ovoko_api_price_pln'], $row['price_source'], $row['price_import_safe'] ? 'true' : 'false',
                    $row['difference'], $row['ovoko_title'], $row['ovoko_status'], $row['ovoko_quantity'],
                    $row['action'], implode('; ', $row['notes']),
                ]);
            }
            fclose($out);
        }, 'ovoko_price_import_'.now()->format('Ymd_His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function debugPriceFields(Request $request, MarketplaceApiManager $manager): JsonResponse
    {
        if ($forbidden = $this->guard($request)) return $forbidden;

        $ovokoPartId = (string) $request->query('ovoko_part_id', '');
        if ($ovokoPartId === '') {
            return response()->json(['ok' => false, 'error_message' => 'Missing ovoko_part_id.'], 422);
        }

        $client = $manager->client('ovoko');
        if (! $client instanceof OvokoApiClient) {
            return response()->json(['ok' => false, 'blockers' => ['ovoko_client_unavailable']], 422);
        }

        $readiness = $client->getAccountReadiness();
        $blockers = $readiness['blockers'] ?? [];
        if ($blockers !== []) {
            return response()->json(['ok' => false, 'blockers' => $blockers], 422);
        }

        $limit = max(1, min((int) $request->query('limit', 200), 1000));
        $maxPages = max(1, min((int) $request->query('max_pages', 50), 200));
        $diagnostics = null;

        for ($page = 1; $page <= $maxPages; $page++) {
            try {
                $result = $client->fetchPartsPage($page, $limit);
            } catch (Throwable) {
                return response()->json(['ok' => false, 'blockers' => ['ovoko_api_request_failed'], 'diagnostics' => $client->safeExceptionDiagnostics($page, $limit, 'Ovoko/RRR API request failed without exposing credentials.')], 502);
            }

            $diagnostics ??= $result['diagnostics'] ?? null;
            if (! ($result['api_ok'] ?? false)) {
                return response()->json(['ok' => false, 'blockers' => ['ovoko_api_non_success_status'], 'diagnostics' => $result['diagnostics'] ?? null], 502);
            }

            foreach (($result['parts'] ?? []) as $item) {
                if ((string) ($item['external_offer_id'] ?? '') !== $ovokoPartId) continue;

                return response()->json([
                    'ok' => true,
                    'ovoko_part_id' => $ovokoPartId,
                    'price' => $item['price'] ?? null,
                    'currency' => $item['currency'] ?? null,
                    'original_price' => $item['original_price'] ?? null,
                    'original_currency' => $item['original_currency'] ?? null,
                    'price_resolution' => $this->resolveApiPricePln($item),
                ]);
            }

            if (count($result['parts'] ?? []) < ($result['limit'] ?? $limit)) break;
        }

        return response()->json(['ok' => false, 'ovoko_part_id' => $ovokoPartId, 'blockers' => ['ovoko_part_not_found_in_fetched_pages'], 'diagnostics' => $diagnostics], 404);
    }

    public function import(Request $request, MarketplaceApiManager $manager): JsonResponse
    {
        if ($forbidden = $this->guard($request)) return $forbidden;
        $comparison = $this->buildComparison($request, $manager, forceLimit: 10000);
        $blockers = $comparison['blockers'];
        if (($comparison['api_total_count'] ?? null) !== null && ($comparison['api_total_count'] > $comparison['api_fetched_count'])) {
            $blockers[] = 'api_fetch_is_partial';
        }
        if ($blockers !== []) {
            return response()->json(['ok' => false, 'updated_count' => 0, 'skipped_count' => $comparison['would_skip_count'], 'unmatched_count' => $comparison['unmatched_api_items_count'], 'sample_updated' => [], 'sample_skipped' => array_slice($comparison['rows_for_export'], 0, 10), 'warnings' => $comparison['warnings'], 'blockers' => $blockers], 422);
        }

        $updated = [];
        DB::transaction(function () use ($comparison, &$updated): void {
            foreach ($comparison['rows_for_export'] as $row) {
                if (($row['action'] ?? null) !== 'would_update_ovoko_price') continue;
                if (($row['price_import_safe'] ?? false) !== true) continue;
                Part::query()->whereKey($row['part_id'])->update(['ovoko_price' => $row['ovoko_api_price_pln']]);
                if (count($updated) < 10) $updated[] = $row + ['action' => 'updated_ovoko_price'];
            }
        });

        return response()->json(['ok' => true, 'updated_count' => $comparison['would_update_count'], 'skipped_count' => $comparison['would_skip_count'], 'unmatched_count' => $comparison['unmatched_api_items_count'], 'sample_updated' => $updated, 'sample_skipped' => array_slice(array_values(array_filter($comparison['rows_for_export'], fn ($r) => ($r['action'] ?? null) !== 'would_update_ovoko_price')), 0, 10), 'warnings' => $comparison['warnings'], 'blockers' => []]);
    }

    private function buildComparison(Request $request, MarketplaceApiManager $manager, ?int $forceLimit = null): array
    {
        $limit = $forceLimit ?? max(1, min((int) $request->query('limit', 1000), 10000));
        $onlyMissing = (bool) (int) $request->query('only_missing', 0);
        $changedOnly = (bool) (int) $request->query('changed_only', 0);
        $client = $manager->client('ovoko');
        $warnings = [];
        $blockers = [];
        if (! $client instanceof OvokoApiClient) {
            $blockers[] = 'ovoko_client_unavailable';
            return $this->emptySummary($limit, $onlyMissing, $changedOnly, $warnings, $blockers);
        }

        $readiness = $client->getAccountReadiness();
        $blockers = array_merge($blockers, $readiness['blockers'] ?? []);
        if ($blockers !== []) return $this->emptySummary($limit, $onlyMissing, $changedOnly, $warnings, $blockers);

        $items = [];
        $apiTotal = null;
        $firstPageDiagnostics = null;
        $page = 1;
        $pageSize = min($limit, 200);
        while (count($items) < $limit) {
            $requestLimit = min($pageSize, $limit - count($items));

            try {
                $result = $client->fetchPartsPage($page, $requestLimit);
            } catch (Throwable) {
                $result = [
                    'api_ok' => false,
                    'error' => 'Ovoko/RRR API request failed without exposing credentials.',
                    'diagnostics' => $client->safeExceptionDiagnostics($page, $requestLimit, 'Ovoko/RRR API request failed without exposing credentials.'),
                    'parts' => [],
                    'limit' => $requestLimit,
                ];
            }

            if ($page === 1) $firstPageDiagnostics = $result['diagnostics'] ?? null;

            if (! ($result['api_ok'] ?? false)) {
                $statusCode = $result['api_status_code'] ?? ($result['diagnostics']['ovoko_status_code'] ?? 'missing');
                $message = $result['error'] ?? ($result['diagnostics']['error_message_safe'] ?? 'Ovoko API returned a non-success response.');
                $blockers[] = 'ovoko_api_non_success_status: '.$statusCode.'; '.$this->safeMessage($message);
                break;
            }

            $apiTotal ??= $result['total_count'];
            $batch = $result['parts'];
            if ($batch === []) break;
            array_push($items, ...$batch);
            if (count($batch) < $result['limit']) break;
            $page++;
        }

        $ids = array_values(array_filter(array_map(fn ($i) => (string) $i['external_offer_id'], $items)));
        $listings = MarketplaceListing::query()->with('part')->where('marketplace', 'ovoko')->whereIn('external_offer_id', $ids)->get()->keyBy('external_offer_id');
        $localTotal = MarketplaceListing::query()->where('marketplace', 'ovoko')->count();
        $summary = $this->emptySummary($limit, $onlyMissing, $changedOnly, $warnings, $blockers);
        $summary['api_total_count'] = $apiTotal;
        $summary['api_fetched_count'] = count($items);
        $summary['local_ovoko_listings_total'] = $localTotal;
        $summary['safe_api_diagnostics_first_page'] = $firstPageDiagnostics;

        foreach ($items as $item) {
            $listing = $listings->get((string) $item['external_offer_id']);
            $part = $listing?->part;
            if (! $part) { $summary['unmatched_api_items_count']++; $this->push($summary['sample_unmatched_api_items'], $item); continue; }
            $summary['matched_to_part_count']++;
            $resolution = $this->resolveApiPricePln($item);
            $apiPrice = $resolution['ovoko_api_price_pln'];
            $safe = $resolution['price_import_safe'];
            $local = $this->money($part->ovoko_price);
            $diff = $apiPrice !== null && $local !== null ? round($apiPrice - $local, 2) : null;
            $same = $apiPrice !== null && $local !== null && abs($diff) < 0.01;
            $missing = $local === null;
            $different = $apiPrice !== null && $local !== null && ! $same;
            if ($missing) $summary['missing_local_ovoko_price_count']++;
            if ($same) $summary['same_price_count']++;
            if ($different) $summary['different_price_count']++;
            $action = ($safe && $apiPrice !== null && ($missing || (! $onlyMissing && $different))) ? 'would_update_ovoko_price' : 'skip';
            if ($changedOnly && $action === 'skip') continue;
            $notes = array_values(array_filter(array_merge([$missing ? 'missing_local_ovoko_price' : null, $different ? 'different_price' : null, $same ? 'same_price' : null, $apiPrice === null ? 'missing_api_price_pln' : null], $resolution['warnings'])));
            $row = ['part_id'=>$part->id,'part_number'=>$part->part_number,'name'=>$part->name,'ovoko_external_offer_id'=>(string)$item['external_offer_id'],'storefront_price_pln'=>$this->money($part->price),'current_ovoko_price_pln'=>$local,'ovoko_api_price'=>$this->money($item['price'] ?? null),'ovoko_api_currency'=>$this->currency($item['currency'] ?? null),'ovoko_original_price'=>$this->money($item['original_price'] ?? null),'ovoko_original_currency'=>$this->currency($item['original_currency'] ?? null),'ovoko_api_price_pln'=>$apiPrice,'price_source'=>$resolution['price_source'],'price_import_safe'=>$safe,'difference'=>$diff,'ovoko_title'=>$item['title'],'ovoko_status'=>$item['status'],'ovoko_quantity'=>$item['quantity'],'action'=>$action,'notes'=>$notes];
            $summary[$action === 'would_update_ovoko_price' ? 'would_update_count' : 'would_skip_count']++;
            $summary['rows_for_export'][] = $row;
            if ($action === 'would_update_ovoko_price') $this->push($summary['sample_would_update'], $row);
            if ($missing) $this->push($summary['sample_missing_local_price'], $row);
            if ($different) $this->push($summary['sample_different_price'], $row);
        }
        $summary['ok'] = $blockers === [];
        if (! (bool) (int) $request->query('debug', 0) && $summary['ok']) {
            unset($summary['safe_api_diagnostics_first_page']);
        }
        return $summary;
    }

    private function emptySummary(int $limit, bool $onlyMissing, bool $changedOnly, array $warnings, array $blockers): array
    {
        return ['ok'=>false,'limit'=>$limit,'only_missing'=>$onlyMissing,'changed_only'=>$changedOnly,'api_total_count'=>null,'api_fetched_count'=>0,'local_ovoko_listings_total'=>0,'matched_to_part_count'=>0,'unmatched_api_items_count'=>0,'missing_local_ovoko_price_count'=>0,'same_price_count'=>0,'different_price_count'=>0,'would_update_count'=>0,'would_skip_count'=>0,'sample_would_update'=>[],'sample_missing_local_price'=>[],'sample_different_price'=>[],'sample_unmatched_api_items'=>[],'safe_api_diagnostics_first_page'=>null,'warnings'=>$warnings,'blockers'=>$blockers,'rows_for_export'=>[]];
    }

    private function guard(Request $request): ?JsonResponse
    {
        return hash_equals(self::TOKEN, (string) $request->query('token', '')) ? null : response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
    }

    private function safeMessage(mixed $message): string
    {
        return str($message ?: 'Ovoko API returned a non-success response.')
            ->replaceMatches('/(username|password|user_token)=[^\s&]+/i', '$1=[redacted]')
            ->limit(300, '')
            ->toString();
    }

    private function resolveApiPricePln(array $item): array
    {
        $price = $this->money($item['price'] ?? null);
        $currency = $this->currency($item['currency'] ?? null);
        $originalPrice = $this->money($item['original_price'] ?? null);
        $originalCurrency = $this->currency($item['original_currency'] ?? null);

        if ($originalCurrency === 'PLN' && $originalPrice !== null && $originalPrice > 0) {
            return ['ovoko_api_price_pln' => $originalPrice, 'price_source' => 'original_price_pln', 'price_import_safe' => true, 'warnings' => []];
        }

        if ($originalCurrency === 'EUR') {
            return ['ovoko_api_price_pln' => null, 'price_source' => 'blocked_original_price_eur', 'price_import_safe' => false, 'warnings' => ['blocked_original_price_eur_no_auto_conversion']];
        }

        if ($originalPrice === null && $currency === 'PLN' && $price !== null && $price > 0) {
            return ['ovoko_api_price_pln' => $price, 'price_source' => 'price_pln', 'price_import_safe' => true, 'warnings' => []];
        }

        if ($currency === 'EUR') {
            return ['ovoko_api_price_pln' => null, 'price_source' => 'blocked_price_eur', 'price_import_safe' => false, 'warnings' => ['blocked_price_eur_no_auto_conversion']];
        }

        return ['ovoko_api_price_pln' => null, 'price_source' => null, 'price_import_safe' => false, 'warnings' => ['missing_confirmed_pln_price']];
    }

    private function currency(mixed $value): ?string { return is_string($value) && trim($value) !== '' ? strtoupper(trim($value)) : null; }
    private function money(mixed $value): ?float { return is_numeric($value) ? round((float) $value, 2) : null; }
    private function push(array &$bucket, array $row): void { if (count($bucket) < 10) $bucket[] = $row; }
}
