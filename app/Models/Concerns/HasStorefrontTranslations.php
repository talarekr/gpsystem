<?php

namespace App\Models\Concerns;

use App\Models\StorefrontTranslation;
use Illuminate\Database\Eloquent\Builder;

trait HasStorefrontTranslations
{
    /** @return array<int, string> */
    abstract public function translationSourceFields(): array;

    /** @return array<string, string> */
    public function translationSource(): array
    {
        $source = [];
        foreach ($this->translationSourceFields() as $field) {
            $source[$field] = (string) ($this->getAttribute($field) ?? '');
        }

        return $source;
    }

    public function translationSourceHash(): string
    {
        return hash('sha256', json_encode($this->translationSource(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function translationForLocale(string $locale): ?StorefrontTranslation
    {
        if (! $this->relationLoaded('translations')) {
            if (! $this->exists || ! $this->translationTableExists()) {
                return null;
            }

            $this->load('translations');
        }

        return $this->getRelation('translations')->firstWhere('locale', $locale);
    }

    public function scopeWithStorefrontTranslations(Builder $query, string $locale): Builder
    {
        return $locale === 'fr' && $this->translationTableExists()
            ? $query->with('translations') : $query;
    }

    private function translationTableExists(): bool
    {
        return $this->getConnection()->getSchemaBuilder()->hasTable($this->translations()->getRelated()->getTable());
    }

    protected function translatedStorefrontField(string $field, string $locale): ?string
    {
        if ($locale !== 'fr') {
            return null;
        }

        $translation = $this->translationForLocale($locale);
        $value = $translation?->getAttribute($field);

        return $translation?->isReady() && is_string($value) && trim($value) !== '' ? $value : null;
    }
}
