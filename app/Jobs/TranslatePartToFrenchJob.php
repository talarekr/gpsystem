<?php

namespace App\Jobs;

class TranslatePartToFrenchJob extends FrenchCatalogTranslationJob
{
    protected function catalogType(): string
    {
        return 'parts';
    }
}
