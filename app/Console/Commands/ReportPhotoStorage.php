<?php

namespace App\Console\Commands;

use App\Services\Tools\PhotoStorageReportService;
use Illuminate\Console\Command;

class ReportPhotoStorage extends Command
{
    protected $signature = 'gps:report-photo-storage {--json : Output JSON report}';

    protected $description = 'Report photo storage usage without deleting or modifying files.';

    public function handle(PhotoStorageReportService $reports): int
    {
        $report = $reports->report();
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }
}
