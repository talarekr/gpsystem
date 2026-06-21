<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BuildEbayMappingsFromPartsService
{
    public function __construct(private readonly EbayListingExtractor $extractor) {}

    public function run(bool $dryRun = false): array
    {
        if (! Schema::hasTable('parts') || ! Schema::hasTable('marketplace_listings')) throw new \RuntimeException('Required tables parts and marketplace_listings must exist.');
        $summary = ['ok'=>true,'dry_run'=>$dryRun,'parts_total'=>DB::table('parts')->count(),'channels'=>[],'total'=>$this->emptyChannel(),'command_availability'=>['artisan'=>array_key_exists('marketplace:build-ebay-mappings-from-parts', Artisan::all())]];
        foreach ($this->extractor->channels() as $channel) {
            $channelSummary = $this->processChannel($channel, $dryRun);
            $summary['channels'][$channel] = $channelSummary;
            foreach (['with_identifier','unique_identifiers','duplicates_count','without_identifier','would_create','would_update','would_conflict','would_skip','created','updated','conflicts','skipped'] as $key) {
                if (isset($channelSummary[$key])) $summary['total'][$key] = ($summary['total'][$key] ?? 0) + $channelSummary[$key];
            }
        }
        return $summary;
    }

    private function emptyChannel(): array
    {
        return ['with_identifier'=>0,'unique_identifiers'=>0,'duplicates_count'=>0,'without_identifier'=>0,'would_create'=>0,'would_update'=>0,'would_conflict'=>0,'would_skip'=>0,'created'=>0,'updated'=>0,'conflicts'=>0,'skipped'=>0];
    }

    private function processChannel(string $channel, bool $dryRun): array
    {
        $summary = ['with_identifier'=>0,'unique_identifiers'=>0,'duplicates_count'=>0,'without_identifier'=>0,'would_create'=>0,'would_update'=>0,'would_conflict'=>0,'would_skip'=>0,'sample_create'=>[],'sample_conflict'=>[]];
        $rowsById = [];
        DB::table('parts')->select(['id','sku','name','price','quantity','legacy_payload'])->orderBy('id')->chunkById(500, function ($parts) use (&$summary, &$rowsById, $channel): void {
            foreach ($parts as $part) {
                $listing = $this->extractor->extract($part->legacy_payload ?? null, $channel);
                if ($listing === null) { $summary['without_identifier']++; $summary['would_skip']++; continue; }
                $summary['with_identifier']++;
                $rowsById[$listing['external_offer_id']][] = ['part'=>$part,'listing'=>$listing];
            }
        });
        $summary['unique_identifiers'] = count($rowsById);
        foreach ($rowsById as $externalId => $rows) {
            if (count($rows) > 1) {
                $summary['would_conflict']++; $summary['duplicates_count'] += count($rows); $this->push($summary['sample_conflict'], ['external_offer_id'=>$externalId,'part_ids'=>array_map(fn ($r) => $r['part']->id, $rows),'count'=>count($rows)]);
                if (! $dryRun) $this->writeConflict($channel, $externalId, $rows);
                continue;
            }
            $exists = MarketplaceListing::query()->where('marketplace', $channel)->where('external_offer_id', $externalId)->exists();
            $summary[$exists ? 'would_update' : 'would_create']++;
            if (! $exists) $this->push($summary['sample_create'], ['external_offer_id'=>$externalId,'part_id'=>$rows[0]['part']->id,'sku'=>$rows[0]['part']->sku,'title'=>$rows[0]['part']->name]);
            if (! $dryRun) $this->writeMapped($channel, $externalId, $rows[0]);
        }
        if (! $dryRun) {
            $summary['created'] = $summary['would_create']; $summary['updated'] = $summary['would_update']; $summary['conflicts'] = $summary['would_conflict']; $summary['skipped'] = $summary['would_skip'];
            unset($summary['would_create'], $summary['would_update'], $summary['would_conflict'], $summary['would_skip'], $summary['sample_create'], $summary['sample_conflict']);
        }
        return $summary;
    }

    private function account(string $channel): MarketplaceAccount
    {
        return MarketplaceAccount::query()->firstOrCreate(['code'=>$channel], ['marketplace'=>$channel,'name'=>strtoupper(str_replace('_', ' ', $channel)),'status'=>'active','config'=>['source'=>'parts.legacy_payload']]);
    }

    private function writeMapped(string $channel, string $externalId, array $row): void
    {
        $part = $row['part']; $listingData = $row['listing']; $account = $this->account($channel);
        $listing = MarketplaceListing::query()->updateOrCreate(['marketplace'=>$channel,'external_offer_id'=>$externalId], ['marketplace_account_id'=>$account->id,'part_id'=>$part->id,'external_listing_id'=>$listingData['fields']['listing_id'] ?? $listingData['fields']['item_id'] ?? $externalId,'external_inventory_id'=>$listingData['fields']['inventory_id'] ?? null,'sku'=>$listingData['fields']['sku'] ?? $part->sku,'title'=>$part->name,'price'=>is_numeric($part->price)?(float)$part->price:null,'quantity'=>is_numeric($part->quantity)?(int)$part->quantity:null,'currency'=>'EUR','status'=>$listingData['fields']['listing_status'] ?? 'imported','url'=>$listingData['url'],'sync_status'=>'mapped','match_status'=>'confirmed','match_confidence'=>100,'match_reason'=>'historical ebay reference from parts.legacy_payload_json','raw_payload'=>['source'=>'parts.legacy_payload.legacy_payload_json','channel'=>$channel,'part_id'=>$part->id,'ebay'=>$listingData['fields'],'source_keys'=>$listingData['source_keys']],'last_error'=>null,'last_synced_at'=>now()]);
        MarketplaceSyncLog::query()->create(['marketplace'=>$channel,'marketplace_listing_id'=>$listing->id,'part_id'=>$listing->part_id,'action'=>'build_ebay_mapping_from_parts','status'=>'success','message'=>'historical ebay reference mapping only','payload'=>['channel'=>$channel,'external_offer_id'=>$externalId,'part_id'=>$listing->part_id],'created_at'=>now()]);
    }

    private function writeConflict(string $channel, string $externalId, array $rows): void
    {
        $account = $this->account($channel); $first = $rows[0]['listing'];
        $listing = MarketplaceListing::query()->updateOrCreate(['marketplace'=>$channel,'external_offer_id'=>$externalId], ['marketplace_account_id'=>$account->id,'part_id'=>null,'sku'=>null,'title'=>'Conflict: duplicate eBay '.$channel.' external ID '.$externalId,'price'=>null,'quantity'=>null,'currency'=>'EUR','status'=>$first['fields']['listing_status'] ?? 'imported','url'=>$first['url'],'sync_status'=>'conflict','match_status'=>'conflict','match_confidence'=>0,'match_reason'=>'duplicate ebay external id in parts.legacy_payload_json for channel '.$channel,'raw_payload'=>['source'=>'parts.legacy_payload.legacy_payload_json','channel'=>$channel,'external_offer_id'=>$externalId,'part_ids'=>array_map(fn ($r) => $r['part']->id, $rows),'count'=>count($rows),'ebay_rows'=>array_map(fn ($r) => $r['listing']['fields'], $rows)],'last_error'=>'Duplicate eBay external ID found in multiple parts; not mapped automatically.','last_synced_at'=>now()]);
        MarketplaceSyncLog::query()->create(['marketplace'=>$channel,'marketplace_listing_id'=>$listing->id,'part_id'=>null,'action'=>'build_ebay_mapping_from_parts','status'=>'conflict','message'=>'duplicate ebay external id in parts.legacy_payload_json','payload'=>['channel'=>$channel,'external_offer_id'=>$externalId,'part_ids'=>array_map(fn ($r) => $r['part']->id, $rows)],'created_at'=>now()]);
    }

    private function push(array &$samples, array $sample): void { if (count($samples) < 20) $samples[] = $sample; }
}
