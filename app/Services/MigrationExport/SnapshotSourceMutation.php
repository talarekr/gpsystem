<?php

namespace App\Services\MigrationExport;

use RuntimeException;

/** Only non-secret identifiers/timestamps; never an original row or exception body. */
final class SnapshotSourceMutation extends RuntimeException
{
    public function __construct(public readonly array $diagnostic)
    {
        parent::__construct('snapshot_row_updated_after_cutoff');
    }
}
