<?php

namespace App\Services\Marketplace;

use App\Models\Part;

class LocalSaleEndMarketplacesService
{
    public function __construct(private readonly SaleFinalizationService $service) {}

    public function dryRun(Part $part): array
    {
        return $this->service->dryRunPart($part, 'local_sale');
    }

    public function apply(Part $part): array
    {
        return $this->service->applyForPart($part, 'local_sale');
    }
}
