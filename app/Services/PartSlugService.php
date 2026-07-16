<?php

namespace App\Services;

use App\Models\Part;
use Illuminate\Support\Str;

class PartSlugService
{
    public function uniqueSlugForName(string $name): ?string
    {
        $baseSlug = Str::slug($name);

        if ($baseSlug === '') {
            return null;
        }

        $slug = $baseSlug;
        $suffix = 2;

        while (Part::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
