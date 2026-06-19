<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LastLaravelErrorToolTest extends TestCase
{
    private string $logFile;
    private ?string $originalLog = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = storage_path('logs/laravel.log');
        File::ensureDirectoryExists(dirname($this->logFile));
        $this->originalLog = is_file($this->logFile) ? (string) file_get_contents($this->logFile) : null;
    }

    protected function tearDown(): void
    {
        if ($this->originalLog === null) {
            File::delete($this->logFile);
        } else {
            File::put($this->logFile, $this->originalLog);
        }

        parent::tearDown();
    }

    public function test_last_laravel_error_returns_latest_complete_error_block(): void
    {
        File::put($this->logFile, implode("\n", [
            '[2026-06-19 18:20:00] staging.ERROR: old syntax error',
            'old trace line 1',
            'old trace line 2',
            '[CATALOG_MARKER] before-czesci-test 2026-06-19T18:30:00+00:00',
            '[2026-06-19 18:40:00] production.ERROR: fresh catalog error',
            'fresh trace line 1',
            'fresh trace line 2',
            'fresh trace line 3',
        ])."\n");

        $this->get('/tools/last-laravel-error?token=gps_images_import_2026')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('latest_error_timestamp', '2026-06-19 18:40:00')
            ->assertJsonPath('latest_error_header', '[2026-06-19 18:40:00] production.ERROR: fresh catalog error')
            ->assertJsonPath('latest_error_message', 'fresh catalog error')
            ->assertJsonPath('latest_error_block_first_80_lines.0', '[2026-06-19 18:40:00] production.ERROR: fresh catalog error')
            ->assertJsonPath('latest_error_block_last_40_lines.3', 'fresh trace line 3');
    }

    public function test_last_laravel_error_after_filters_to_errors_after_timestamp(): void
    {
        File::put($this->logFile, implode("\n", [
            '[2026-06-19 18:20:00] staging.ERROR: old syntax error',
            'old trace line',
            '[2026-06-19 18:40:00] production.ERROR: fresh catalog error',
            'fresh trace line',
        ])."\n");

        $this->get('/tools/last-laravel-error?token=gps_images_import_2026&after=2026-06-19T18:30:00')
            ->assertOk()
            ->assertJsonPath('matching_error_count', 1)
            ->assertJsonPath('first_error_after_timestamp', '2026-06-19 18:40:00')
            ->assertJsonPath('latest_error_timestamp', '2026-06-19 18:40:00')
            ->assertJsonPath('latest_error_message', 'fresh catalog error');
    }

    public function test_mark_log_appends_catalog_marker(): void
    {
        File::put($this->logFile, '');

        $this->get('/tools/mark-log?token=gps_images_import_2026&label=before-czesci-test')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('label', 'before-czesci-test');

        $this->assertStringContainsString('[CATALOG_MARKER] before-czesci-test ', (string) file_get_contents($this->logFile));
    }
}
