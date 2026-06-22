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
            fputcsv($out, ['part_id','part_number','name','ovoko_external_offer_id','storefront_price_pln','current_ovoko_price_pln','ovoko_api_price_pln','difference','ovoko_title','ovoko_status','ovoko_quantity','action','notes']);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['part_id'], $row['part_number'], $row['name'], $row['ovoko_external_offer_id'],
                    $row['storefront_price_pln'], $row['current_ovoko_price_pln'], $row['ovoko_api_price_pln'],
                    $row['difference'], $row['ovoko_title'], $row['ovoko_status'], $row['ovoko_quantity'],
                    $row['action'], implode('; ', $row['notes']),
                ]);
            }
            fclose($out);
        }, 'ovoko_price_import_'.now()->format('Ymd_His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
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
        if (! $client instanceof OvokoApiClient) $blockers[] = 'ovoko_client_unavailable';
        $readiness = $client->getAccountReadiness();
        $blockers = array_merge($blockers, $readiness['blockers'] ?? []);
        if ($blockers !== []) return $this->emptySummary($limit, $onlyMissing, $changedOnly, $warnings, $blockers);

        $items = [];
        $apiTotal = null;
        $page = 1;
        $pageSize = min($limit, 500);
        while (count($items) < $limit) {
            $result = $client->fetchPartsPage($page, min($pageSize, $limit - count($items)));
            if (! ($result['api_ok'] ?? false)) { $blockers[] = 'ovoko_api_non_success_status'; break; }
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

        foreach ($items as $item) {
            $listing = $listings->get((string) $item['external_offer_id']);
            $part = $listing?->part;
            if (! $part) { $summary['unmatched_api_items_count']++; $this->push($summary['sample_unmatched_api_items'], $item); continue; }
            $summary['matched_to_part_count']++;
            $apiPrice = $this->money($item['price']);
            $local = $this->money($part->ovoko_price);
            $diff = $apiPrice !== null && $local !== null ? round($apiPrice - $local, 2) : null;
            $same = $apiPrice !== null && $local !== null && abs($diff) < 0.01;
            $missing = $local === null;
            $different = $apiPrice !== null && $local !== null && ! $same;
            if ($missing) $summary['missing_local_ovoko_price_count']++;
            if ($same) $summary['same_price_count']++;
            if ($different) $summary['different_price_count']++;
            $action = ($apiPrice !== null && ($missing || (! $onlyMissing && $different))) ? 'would_update_ovoko_price' : 'skip';
            if ($changedOnly && $action === 'skip') continue;
            $row = ['part_id'=>$part->id,'part_number'=>$part->part_number,'name'=>$part->name,'ovoko_external_offer_id'=>(string)$item['external_offer_id'],'storefront_price_pln'=>$this->money($part->price),'current_ovoko_price_pln'=>$local,'ovoko_api_price_pln'=>$apiPrice,'difference'=>$diff,'ovoko_title'=>$item['title'],'ovoko_status'=>$item['status'],'ovoko_quantity'=>$item['quantity'],'action'=>$action,'notes'=>array_values(array_filter([$missing ? 'missing_local_ovoko_price' : null, $different ? 'different_price' : null, $same ? 'same_price' : null, $apiPrice === null ? 'missing_api_price' : null]))];
            $summary[$action === 'would_update_ovoko_price' ? 'would_update_count' : 'would_skip_count']++;
            $summary['rows_for_export'][] = $row;
            if ($action === 'would_update_ovoko_price') $this->push($summary['sample_would_update'], $row);
            if ($missing) $this->push($summary['sample_missing_local_price'], $row);
            if ($different) $this->push($summary['sample_different_price'], $row);
        }
        $summary['ok'] = $blockers === [];
        return $summary;
    }

    private function emptySummary(int $limit, bool $onlyMissing, bool $changedOnly, array $warnings, array $blockers): array
    {
        return ['ok'=>false,'limit'=>$limit,'only_missing'=>$onlyMissing,'changed_only'=>$changedOnly,'api_total_count'=>null,'api_fetched_count'=>0,'local_ovoko_listings_total'=>0,'matched_to_part_count'=>0,'unmatched_api_items_count'=>0,'missing_local_ovoko_price_count'=>0,'same_price_count'=>0,'different_price_count'=>0,'would_update_count'=>0,'would_skip_count'=>0,'sample_would_update'=>[],'sample_missing_local_price'=>[],'sample_different_price'=>[],'sample_unmatched_api_items'=>[],'warnings'=>$warnings,'blockers'=>$blockers,'rows_for_export'=>[]];
    }

    private function guard(Request $request): ?JsonResponse
    {
        return hash_equals(self::TOKEN, (string) $request->query('token', '')) ? null : response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
    }

    private function money(mixed $value): ?float { return is_numeric($value) ? round((float) $value, 2) : null; }
    private function push(array &$bucket, array $row): void { if (count($bucket) < 10) $bucket[] = $row; }
}
