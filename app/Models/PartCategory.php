<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PartCategory extends Model
{
    protected $fillable = ['source_system', 'external_id', 'name', 'slug', 'category_path', 'legacy_payload'];

    protected function casts(): array
    {
        return ['legacy_payload' => 'array'];
    }

    public function parts(): HasMany
    {
        return $this->hasMany(Part::class, 'category_id');
    }
}
