<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EbayListingStatusScanRun extends Model
{
    protected $fillable = ['mode','status','dry_run','total','processed','remaining','active','ended','not_found','invalid','unknown','failed_requests','rate_limit_hits','started_at','finished_at','settings','summary'];

    protected function casts(): array
    {
        return ['dry_run'=>'boolean','settings'=>'array','summary'=>'array','started_at'=>'datetime','finished_at'=>'datetime'];
    }

    public function results(): HasMany
    {
        return $this->hasMany(EbayListingStatusScanResult::class, 'scan_run_id');
    }
}
