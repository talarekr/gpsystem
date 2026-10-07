<?php

namespace App\Jobs;

class TranslateCategoryToFrenchJob extends FrenchCatalogTranslationJob
{
    protected function catalogType(): string
    {
        return 'categories';
    }
}
