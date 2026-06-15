<?php

namespace App\Services\ImportMigration;

use App\Models\Car; use App\Models\Part; use App\Models\PartCategory; use App\Models\PartImage; use App\Models\StorageLocation; use Illuminate\Support\Str;

class WooProductImport
{
    public const MODE_DRY_RUN='dry_run'; public const MODE_CREATE_ONLY='create_only'; public const MODE_UPDATE_EXISTING='update_existing';
    public function __construct(private CsvReader $csvReader) {}
    public function import(string $productsPath, array $paths = [], string $mode = self::MODE_DRY_RUN): ImportReport
    {
        $report = new ImportReport(['created'=>0,'updated'=>0,'skipped_existing'=>0,'skipped_duplicates'=>0,'images_linked'=>0,'categories_created'=>0,'categories_matched'=>0]);
        $images=$this->group($paths['images']??null,'woo_product_id'); $cats=$this->group($paths['categories']??null,'woo_product_id'); $meta=$this->group($paths['meta']??null,'woo_product_id'); $attrs=$this->group($paths['attributes']??null,'woo_product_id');
        foreach ($this->csvReader->rows($productsPath) as $line=>$row) {
            $report->inc('total_rows'); $woo=(string)($row['woo_product_id']??''); if ($woo==='') { $report->error("Wiersz {$line}: brak woo_product_id."); continue; }
            $existing=Part::query()->where('source_system','woo')->where('external_id',$woo)->first();
            $sku=trim((string)($row['sku']??'')); $skuConflict=$sku!=='' ? Part::query()->where('sku',$sku)->where(function($q)use($woo){$q->where('source_system','!=','woo')->orWhere('external_id','!=',$woo)->orWhereNull('external_id');})->first() : null;
            if ($skuConflict && ! $existing) { $report->inc('skipped_duplicates'); $report->warning("Wiersz {$line}: SKU {$sku} istnieje przy innej części; pominięto produkt Woo {$woo}."); continue; }
            $category=$this->category($cats[$woo][0]??null,$report,$mode); $carId=$this->carId($row,$report,$line); $payload=$this->map($row,$meta[$woo]??[],$attrs[$woo]??[],$category?->id,$carId);
            if ($mode===self::MODE_DRY_RUN) { $existing ? $report->inc('skipped_existing') : $report->inc('created'); continue; }
            if ($existing) { if ($mode===self::MODE_UPDATE_EXISTING) { $existing->fill($payload)->save(); $part=$existing; $report->inc('updated'); } else { $part=$existing; $report->inc('skipped_existing'); } }
            else { $part=Part::query()->create($payload); $report->inc('created'); }
            $this->images($part,$images[$woo]??[],$report); if (empty($images[$woo])) $report->inc('products_missing_images'); if (empty($cats[$woo])) $report->inc('products_missing_categories');
        }
        return $report;
    }
    private function group(?string $path,string $key): array { $out=[]; if(!$path||!is_file($path)) return $out; foreach($this->csvReader->rows($path) as $r) $out[(string)($r[$key]??'')][]=$r; return $out; }
    private function map(array $r,array $meta,array $attrs,?int $categoryId,?int $carId): array {
        $legacyJson=null; if (filled($r['legacy_payload_json']??null)) { try { $legacyJson=json_decode($r['legacy_payload_json'], true, 512, JSON_THROW_ON_ERROR); } catch (\Throwable) { $legacyJson=['legacy_payload_json_malformed'=>true]; } }
        $metaMap=[]; foreach($meta as $m) $metaMap[$m['meta_key']??'']=$m['meta_value']??null;
        return ['source_system'=>'woo','external_id'=>(string)$r['woo_product_id'],'sku'=>blank($r['sku']??null)?null:$r['sku'],'name'=>$r['name'] ?: ('Woo produkt '.$r['woo_product_id']),'slug'=>blank($r['slug']??null)?null:Str::limit($r['slug'],255,''),'legacy_slug'=>$r['slug']??null,'legacy_url'=>$r['permalink']??null,'short_description'=>$r['short_description']??null,'description'=>$r['description']??null,'price'=>(float)($r['price']?:0)?:null,'currency'=>$r['currency']?:'PLN','quantity'=>(int)($r['quantity']?:1),'status'=>$this->status($r),'part_number'=>$r['part_number'] ?: ($metaMap['_part_number']??null),'oem_number'=>$r['oem_number'] ?: ($metaMap['_oem_number']??null),'manufacturer_code'=>$r['manufacturer_code']??null,'condition_notes'=>$r['condition']??null,'category_id'=>$categoryId,'car_id'=>$carId,'storage_location_id'=>$this->locationId($r['storage_location_name']??null),'is_visible_storefront'=>false,'legacy_payload'=>['woo_product'=>$r,'legacy_payload_json'=>$legacyJson,'meta'=>$meta,'attributes'=>$attrs,'brand'=>$r['brand']??null,'manufacturer'=>$r['manufacturer']??null,'donor_car_id'=>$r['donor_car_id']??null,'source_car_id'=>$r['car_id']??null,'vehicle_id'=>$r['vehicle_id']??null]];
    }
    private function status(array $r): string { $s=strtolower((string)($r['status']??'')); $p=(string)($r['published']??''); return $s==='trash'?'archived':(($s==='publish'||$p==='1')?'ready':'draft'); }
    private function carId(array $r,ImportReport $report,int $line): ?int { $id=(int)($r['ovoko_car_id']??0); if($id<=0){$report->inc('products_without_ovoko_car_id'); return null;} $report->inc('products_with_ovoko_car_id'); if(Car::query()->whereKey($id)->exists()){ $report->inc('products_linked_to_imported_car'); return $id;} $report->inc('products_with_missing_car_reference'); $report->warning("Wiersz {$line}: brak lokalnego samochodu dla ovoko_car_id {$id}."); return null; }
    private function locationId(?string $name): ?int { return filled($name) ? StorageLocation::query()->where('name',$name)->value('id') : null; }
    private function category(?array $r,ImportReport $report,string $mode): ?PartCategory { if(!$r) return null; $slug=$r['slug'] ?: Str::slug($r['category_name'] ?: $r['category_path']); $cat=PartCategory::query()->where('source_system','woo')->where('external_id',(string)($r['category_id']??''))->first() ?: PartCategory::query()->where('slug',$slug)->first(); if($cat){$report->inc('categories_matched'); return $cat;} if($mode===self::MODE_DRY_RUN) { $report->inc('categories_created'); return null; } $report->inc('categories_created'); return PartCategory::query()->create(['source_system'=>'woo','external_id'=>(string)($r['category_id']??''),'name'=>$r['category_name'] ?: basename(str_replace('>','/',$r['category_path']??'Kategoria Woo')),'slug'=>$slug,'category_path'=>$r['category_path']??null,'legacy_payload'=>['woo_category'=>$r]]); }
    private function images(Part $part,array $rows,ImportReport $report): void { foreach($rows as $r){ $url=$r['image_url']??null; if(blank($url)){ $report->warning('Pominięto obraz bez URL dla części '.$part->id); continue;} $img=PartImage::query()->firstOrCreate(['part_id'=>$part->id,'source_system'=>'woo','external_id'=>(string)($r['image_id'] ?: md5($url))],['path'=>$url,'alt_text'=>$r['alt_text']??null,'sort_order'=>(int)($r['position']??0),'is_primary'=>((string)($r['is_primary']??''))==='1'||((int)($r['position']??0)===0)]); if($img->wasRecentlyCreated) $report->inc('images_linked'); } }
}
