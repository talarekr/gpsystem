<?php

namespace App\Services\Tools;

use RuntimeException;

class CsvUnavailableException extends RuntimeException
{
    public readonly string $correlationId;

    public function __construct(public readonly array $diagnostics, public readonly int $statusCode = 422, public readonly string $stage = 'resolve_csv_path', ?string $correlationId = null)
    {
        $this->correlationId = $correlationId ?? uniqid('parts_to_list_storage_backfill_', true);

        parent::__construct('CSV file is missing or not readable. Application looked for the file at '.$diagnostics['expected_absolute_path'].'.');
    }

    public static function fromDiagnostics(array $diagnostics): self
    {
        return new self($diagnostics, ($diagnostics['file_exists'] ?? false) ? 422 : 404);
    }

    public function withStage(string $stage): self
    {
        return new self($this->diagnostics, $this->statusCode, $stage, $this->correlationId);
    }
}
