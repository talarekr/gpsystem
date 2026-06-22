<?php

namespace App\Services\Marketplace\Api;

interface MarketplaceApiClient
{
    public function getAccountReadiness(): array;

    public function testConnection(): array;

    public function fetchOffersSample(int $limit): array;
}
