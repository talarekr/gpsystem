<?php

namespace Tests\Feature;

use App\Services\MigrationExport\AllegroSupplementDiagnostics;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AllegroSupplementDiagnosticsTest extends TestCase
{
    public function test_production_scale_c_initialization_fits_128mb_and_reuses_completed_a_b_without_gets(): void
    {
        $root = sys_get_temp_dir().'/c-scale-'.Str::uuid();
        $script = base_path('tests/Fixtures/allegro-supplement-scale.php');
        try {
            (new Process([PHP_BINARY, '-d', 'memory_limit=256M', $script, $root, 'generate']))->mustRun();
            $inputs = glob($root.'/exports/.*/*/*/plans/*/checkpoint.json');
            $before = array_map(fn ($file) => hash_file('sha256', $file), $inputs);
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', $script, $root, 'run']);
            $process->mustRun();
            $status = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('refreshed_schema', $status['phase']);
            $this->assertSame('PLANNING', $status['plan']['status']);
            $this->assertSame(334, $status['plan']['categories']);
            $this->assertSame(6326, $status['plan']['offers']);
            $this->assertSame(5462, $status['plan']['readback_successes']);
            $this->assertSame(864, $status['plan']['readback_not_found_404']);
            $this->assertSame(0, $status['plan']['provider_gets']);
            $this->assertLessThan(128 * 1024 * 1024, $status['peak_memory_bytes']);
            $process->mustRun();
            $again = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($status['plan'], $again['plan']);
            $this->assertSame($before, array_map(fn ($file) => hash_file('sha256', $file), $inputs));
            $this->assertCount(2, $inputs);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_exception_details_are_safe_and_persist_when_no_checkpoint_exists(): void
    {
        $root = sys_get_temp_dir().'/c-diagnostic-'.Str::uuid();
        try {
            $diagnostic = new AllegroSupplementDiagnostics($root, 'plan', ['parent_run_id' => 'synthetic']);
            $diagnostic->stage('initialize_plan');
            $failure = $diagnostic->failure(new \RuntimeException('Bearer SECRET SQL customer@example.test'));
            $this->assertSame('RuntimeException', $failure['backend_exception_class']);
            $this->assertSame('supplement_server_exception', $failure['reason_code']);
            $this->assertFalse($failure['checkpoint_present']);
            $this->assertNull($failure['last_successful_checkpoint']);
            $this->assertSame($failure, $diagnostic->stored()['last_operation_failure']);
            $this->assertStringNotContainsString('SECRET', file_get_contents($root.'/request.json'));
            $this->assertStringNotContainsString('customer@example.test', file_get_contents($root.'/request.json'));
            $this->assertSame(0600, fileperms($root.'/request.json') & 0777);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_actual_uncatchable_memory_fatal_is_persisted_by_shutdown_reserve(): void
    {
        $root = sys_get_temp_dir().'/c-fatal-'.Str::uuid();
        try {
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=64M', base_path('tests/Fixtures/allegro-supplement-fatal.php'), $root]);
            $process->run();
            $this->assertFalse($process->isSuccessful());
            $this->assertFileExists($root.'/request.json');
            $saved = (new AllegroSupplementDiagnostics($root, 'status', ['parent_run_id' => 'synthetic']))->stored();
            $failure = $saved['last_operation_failure'];
            $this->assertSame('OutOfMemoryError', $failure['backend_exception_class']);
            $this->assertSame('supplement_memory_limit_exceeded', $failure['reason_code']);
            $this->assertSame('persist_parent_index', $failure['phase']);
            $this->assertFalse($failure['checkpoint_present']);
            $this->assertSame('64M', $failure['memory_limit']);
        } finally {
            File::deleteDirectory($root);
        }
    }
}
