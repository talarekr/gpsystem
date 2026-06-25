<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorageLocation extends Model
{
    use HasFactory;

    public const ALLEGRO_IMPORT_DESCRIPTION = 'Imported from Allegro export external_id';

    protected $fillable = [
        'name',
        'description',
        'is_active',
    ];

    public function parts(): HasMany
    {
        return $this->hasMany(Part::class);
    }

    public function publicDescription(): ?string
    {
        if ($this->description === self::ALLEGRO_IMPORT_DESCRIPTION) {
            return null;
        }

        return $this->description;
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
