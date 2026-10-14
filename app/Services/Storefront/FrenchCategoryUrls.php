<?php

namespace App\Services\Storefront;

use App\Models\PartCategory;
use Illuminate\Support\Str;

/** One read-only URL policy for links, routing and SEO. Source PL slugs are never changed. */
class FrenchCategoryUrls
{
    public static function generatedSlug(string $name, int $id): ?string
    {
        $slug = Str::slug(str_replace(['/', '\\'], ' ', Str::limit(trim($name), 200, '')));

        // Stable category ID makes even identical names/transliterations collision-free without guessing.
        return $slug !== '' ? $slug.'-'.$id : null;
    }

    public static function nameHash(string $name): string
    {
        return hash('sha256', trim($name));
    }

    public function legacyPath(PartCategory $category): string
    {
        return trim((string) ($category->full_slug_path ?: $category->slug ?: $category->id), '/');
    }

    public function segment(PartCategory $category): array
    {
        $fr = $category->translationForLocale('fr');
        $reason = match (true) {
            ! $fr => 'missing_translation',
            ! $fr->isReady() => 'nonpublic_status',
            trim((string) $fr->name) === '' => 'empty_fr_name',
            default => null,
        };
        if ($reason === null) {
            $generated = self::generatedSlug($fr->name, $category->id);
            if ($generated !== null) {
                $cached = $fr->slug_name_hash === self::nameHash($fr->name) && $fr->slug === $generated;

                return ['slug' => $generated, 'reason' => $cached ? 'cached_fr_slug' : 'derived_fr_slug', 'fallback' => false];
            }
            $reason = 'empty_fr_slug';
        }

        return ['slug' => basename($this->legacyPath($category)), 'reason' => $reason, 'fallback' => true];
    }

    public function chain(PartCategory $category, $all): array
    {
        $nodes = [];
        $seen = [];
        while (! isset($seen[$category->id])) {
            $seen[$category->id] = true;
            array_unshift($nodes, $category);
            if (! $category->parent_id || ! $all->has($category->parent_id)) {
                break;
            }
            $category = $all->get($category->parent_id);
        }

        return $nodes;
    }

    public function path(PartCategory $category, $all, string $locale): string
    {
        if ($locale !== 'fr') {
            return $this->legacyPath($category);
        }
        $nodes = $this->chain($category, $all);
        $legacy = explode('/', $this->legacyPath($category));
        $prefix = array_slice($legacy, 0, max(0, count($legacy) - count($nodes)));

        return implode('/', array_merge($prefix, array_map(fn ($node) => $this->segment($node)['slug'], $nodes)));
    }

    public function matchesAlias(string $path, PartCategory $category, $all): bool
    {
        $segments = explode('/', trim($path, '/'));
        $chain = $this->chain($category, $all);
        $legacy = explode('/', $this->legacyPath($category));
        $prefix = array_slice($legacy, 0, max(0, count($legacy) - count($chain)));
        if (array_slice($segments, 0, count($prefix)) !== $prefix) {
            return false;
        }
        $segments = array_slice($segments, count($prefix));
        if (count($segments) !== count($chain)) {
            return false;
        }
        foreach ($chain as $i => $node) {
            $fr = $node->translationForLocale('fr');
            $aliases = [$this->segment($node)['slug'], basename($this->legacyPath($node)), $fr?->slug];
            $aliases = array_merge($aliases, (array) ($fr?->slug_history ?? []));
            if (! in_array($segments[$i], $aliases, true)) {
                return false;
            }
        }

        return true;
    }
}
