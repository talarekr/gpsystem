<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BuildAllegroMappingsFromPartsService
{
    public function __construct(private readonly AllegroOfferExtractor $extractor) {}

    /** @return array<string, mixed> */
    public function run(bool $dryRun = false): array
    {
        if (! Schema::hasTable('parts') || ! Schema::hasTable('marketplace_listings')) {
            throw new \RuntimeException('Required tables parts and marketplace_listings must exist.');
        }

        $summary = $this->emptySummary($dryRun);
        $summary['parts_total'] = DB::table('parts')->count();
        $rowsByOfferId = $this->collectRowsByOfferId($summary);
        $summary['unique_allegro_ids'] = count($rowsByOfferId);
        $duplicates = [];

        foreach ($rowsByOfferId as $offerId => $rows) {
            if (count($rows) > 1) {
                $summary['would_conflict']++;
                $summary['duplicates_count'] += count($rows);
                $duplicates[$offerId] = count($rows);
                $this->pushSample($summary['sample_conflict'], ['allegro_offer_id' => $offerId, 'part_ids' => array_column($rows, 'id'), 'count' => count($rows)]);
                if (! $dryRun) $this->writeConflictListing($offerId, $rows);
                continue;
            }

            $row = $rows[0];
            $exists = MarketplaceListing::query()->where('marketplace', 'allegro')->where('external_offer_id', $offerId)->exists();
            $summary[$exists ? 'would_update' : 'would_create']++;
            if (! $exists) $this->pushSample($summary['sample_create'], ['allegro_offer_id' => $offerId, 'part_id' => $row['id'], 'sku' => $row['sku'], 'title' => $row['name']]);
            if (! $dryRun) $this->writeMappedListing($offerId, $row);
        }

        arsort($duplicates);
        $summary['top_duplicate_allegro_ids'] = array_slice($duplicates, 0, 20, true);
        return $summary;
    }

    /** @return array<string, mixed> */
    private function emptySummary(bool $dryRun): array
    {
        return ['dry_run'=>$dryRun,'parts_total'=>0,'with_allegro_id'=>0,'unique_allegro_ids'=>0,'duplicates_count'=>0,'without_allegro_id'=>0,'would_create'=>0,'would_update'=>0,'would_conflict'=>0,'would_skip'=>0,'sample_create'=>[],'sample_conflict'=>[],'sample_without_allegro_id'=>[],'top_duplicate_allegro_ids'=>[]];
    }

    /** @param array<string, mixed> $summary @return array<string, array<int, array<string, mixed>>> */
    private function collectRowsByOfferId(array &$summary): array
    {
        $rows = [];
        DB::table('parts')->select(['id','sku','name','price','quantity','legacy_payload'])->orderBy('id')->chunkById(500, function ($parts) use (&$rows, &$summary): void {
            foreach ($parts as $part) {
                $offers = $this->extractor->extract($part->legacy_payload ?? null);
                if ($offers === []) {
                    $summary['without_allegro_id']++;
                    $summary['would_skip']++;
                    $this->pushSample($summary['sample_without_allegro_id'], ['part_id'=>$part->id,'sku'=>$part->sku,'title'=>$part->name]);
                    continue;
                }
                $summary['with_allegro_id']++;
                foreach ($offers as $offer) {
                    $rows[$offer['offer_id']][] = ['id'=>$part->id,'sku'=>$part->sku,'name'=>$part->name,'price'=>$part->price,'quantity'=>$part->quantity,'offer'=>$offer];
                }
            }
        });
        return $rows;
    }

    /** @param array<string, mixed> $row */
    private function writeMappedListing(string $offerId, array $row): void
    {
        $offer = $row['offer'];
        $account = MarketplaceAccount::query()->firstOrCreate(['code'=>$offer['account_code']], ['marketplace'=>'allegro','name'=>$offer['account_name'],'status'=>'active','config'=>['source_channel'=>$offer['source_channel'],'source_account'=>$offer['source_account']]]);
        $listing = MarketplaceListing::query()->updateOrCreate(['marketplace'=>'allegro','external_offer_id'=>$offerId], [
            'marketplace_account_id'=>$account->id,'part_id'=>$row['id'],'external_listing_id'=>$offerId,'sku'=>$row['sku'],'title'=>$row['name'],'price'=>is_numeric($row['price'])?(float)$row['price']:null,'quantity'=>is_numeric($row['quantity'])?(int)$row['quantity']:null,'currency'=>'PLN','status'=>$offer['status'] ?? 'imported','url'=>$offer['url'],'sync_status'=>'mapped','match_status'=>'confirmed','match_confidence'=>100,'match_reason'=>'allegro offer id from parts.legacy_payload','raw_payload'=>['source'=>'parts.legacy_payload','allegro_offer_id'=>$offerId,'part_id'=>$row['id'],'offer'=>$offer],'last_error'=>null,'last_synced_at'=>now(),
        ]);
        MarketplaceSyncLog::query()->create(['marketplace'=>'allegro','marketplace_listing_id'=>$listing->id,'part_id'=>$listing->part_id,'action'=>'build_allegro_mapping_from_parts','status'=>'success','message'=>'allegro offer id from parts.legacy_payload','payload'=>['external_offer_id'=>$offerId,'part_id'=>$listing->part_id],'created_at'=>now()]);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function writeConflictListing(string $offerId, array $rows): void
    {
        $listing = MarketplaceListing::query()->updateOrCreate(['marketplace'=>'allegro','external_offer_id'=>$offerId], ['part_id'=>null,'sku'=>null,'title'=>'Conflict: duplicate Allegro offer ID '.$offerId,'price'=>null,'quantity'=>null,'currency'=>'PLN','status'=>'imported','sync_status'=>'conflict','match_status'=>'conflict','match_confidence'=>0,'match_reason'=>'duplicate allegro offer id in parts.legacy_payload','raw_payload'=>['source'=>'parts.legacy_payload','allegro_offer_id'=>$offerId,'part_ids'=>array_column($rows, 'id'),'count'=>count($rows),'offers'=>array_column($rows, 'offer')],'last_error'=>'Duplicate Allegro offer ID found in multiple parts; not mapped automatically.','last_synced_at'=>now()]);
        MarketplaceSyncLog::query()->create(['marketplace'=>'allegro','marketplace_listing_id'=>$listing->id,'part_id'=>null,'action'=>'build_allegro_mapping_from_parts','status'=>'conflict','message'=>'duplicate allegro offer id in parts.legacy_payload','payload'=>['external_offer_id'=>$offerId,'part_ids'=>array_column($rows, 'id')],'created_at'=>now()]);
    }

    /** @param array<int, array<string, mixed>> $samples @param array<string, mixed> $sample */
    private function pushSample(array &$samples, array $sample): void { if (count($samples) < 20) $samples[] = $sample; }
}
