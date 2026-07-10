<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OvokoCarModelSyncRun extends Model
{
    public const STATUS_IDLE = 'idle';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'status', 'batch_size', 'delay_seconds', 'only_missing', 'total_brand_count',
        'processed_brand_count', 'synced_models_count', 'failed_brand_count', 'last_offset',
        'processed_brand_ids', 'failed_brands', 'last_batch', 'errors', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'batch_size' => 'integer',
            'delay_seconds' => 'integer',
            'only_missing' => 'boolean',
            'total_brand_count' => 'integer',
            'processed_brand_count' => 'integer',
            'synced_models_count' => 'integer',
            'failed_brand_count' => 'integer',
            'last_offset' => 'integer',
            'processed_brand_ids' => 'array',
            'failed_brands' => 'array',
            'last_batch' => 'array',
            'errors' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
