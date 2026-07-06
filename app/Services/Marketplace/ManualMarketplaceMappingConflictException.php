<?php

namespace App\Services\Marketplace;

use RuntimeException;

class ManualMarketplaceMappingConflictException extends RuntimeException
{
    public function __construct(
        public readonly string $existingId,
        public readonly string $newId,
        public readonly ?int $existingListingId = null,
        public readonly ?int $existingPartId = null,
    ) {
        parent::__construct('existing_mapping_conflict');
    }
}
