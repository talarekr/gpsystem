<?php

namespace App\Services\MigrationExport;

use RuntimeException;

final class MigrationExportException extends RuntimeException
{
    public function __construct(public readonly string $reasonCode, public readonly int $httpStatus = 409, public readonly array $details = [])
    {
        parent::__construct($reasonCode);
    }
}
