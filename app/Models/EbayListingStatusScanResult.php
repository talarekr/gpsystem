<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EbayListingStatusScanResult extends Model
{
    protected $fillable = ['scan_run_id','marketplace_listing_id','part_id','ebay_item_id','local_status','normalized_status','http_status','error_type','attempts','currently_blocks_relisting','should_allow_relisting','item_end_date','checked_at','diagnostic'];

    protected function casts(): array
    {
        return ['currently_blocks_relisting'=>'boolean','should_allow_relisting'=>'boolean','diagnostic'=>'array','item_end_date'=>'datetime','checked_at'=>'datetime'];
    }

    public function scanRun(): BelongsTo
    {
        return $this->belongsTo(EbayListingStatusScanRun::class, 'scan_run_id');
    }
}
