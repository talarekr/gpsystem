<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OvokoStockSyncRunItem extends Model
{
    protected $fillable = ['ovoko_stock_sync_run_id', 'part_id', 'ovoko_id', 'action', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
