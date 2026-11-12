<?php

namespace App\Services\MigrationExport;

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/** Safe, durable operation receipts. Status polling never changes these records. */
final class ProductExportOperation
{
    private array $record = [];

    private float $started;

    private bool $active = false;

    private string $reserve;

    public function __construct(private string $directory, string $id, string $action)
    {
        $this->started = microtime(true);
        $this->reserve = str_repeat('x', 262144);
        $this->record = ['operation_id' => (string) Str::uuid(), 'export_id' => $id, 'action' => $action, 'phase' => 'operation_start', 'reason_code' => 'running', 'backend_exception_class' => null, 'safe_exception_category' => null, 'current_table' => null, 'current_join' => null, 'current_part_id' => null, 'capture_processed' => 0, 'selected_products' => 0, 'checkpoint_present' => is_file($directory.'/state.json'), 'last_successful_phase' => null, 'duration_ms' => 0, 'memory_peak_bytes' => 0];
        register_shutdown_function(function () {
            if (! $this->active) {
                return;
            }
            $this->reserve = '';
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                // Do not copy fatal messages, file paths or traces into the receipt.
                $this->record['reason_code'] = 'operation_fatal';
                $this->record['backend_exception_class'] = 'PHPFatalError';
                $this->record['safe_exception_category'] = 'fatal_resource_or_runtime';
                $this->persist();
            }
        });
    }

    public function run(callable $work): mixed
    {
        $this->active = true;
        $this->persist();
        try {
            $result = $work();
            $this->record['last_successful_phase'] = $this->record['phase'];
            $this->record['reason_code'] = 'operation_completed';

            return $result;
        } catch (\Throwable $e) {
            $class = get_class($e);
            $this->record['backend_exception_class'] = preg_match('/^[a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*$/D', $class) ? $class : 'AnonymousThrowable';
            $this->record['safe_exception_category'] = self::category($e);
            $this->record['reason_code'] = $e instanceof MigrationExportException ? $e->reasonCode : 'product_export_operation_failed';
            throw $e;
        } finally {
            $this->active = false;
            $this->persist();
            $this->reserve = '';
        }
    }

    public function phase(string $phase, ?string $table = null, ?string $part = null): void
    {
        if ($this->record['reason_code'] === 'running' && $this->record['phase'] !== 'operation_start') {
            $this->record['last_successful_phase'] = $this->record['phase'];
        }
        $this->record['phase'] = $phase;
        $this->record['current_table'] = $table;
        $this->record['current_join'] = $table ? 'parts -> '.$table : null;
        $this->record['current_part_id'] = $part;
        $this->persist();
    }

    public function counts(int $processed, int $selected): void
    {
        $this->record['capture_processed'] = $processed;
        $this->record['selected_products'] = $selected;
        $this->persist();
    }

    public function receipt(): array
    {
        return $this->record;
    }

    private function persist(): void
    {
        $this->record['duration_ms'] = (int) round((microtime(true) - $this->started) * 1000);
        $this->record['memory_peak_bytes'] = memory_get_peak_usage(true);
        $this->record['checkpoint_present'] = is_file($this->directory.'/state.json');
        $bytes = json_encode($this->record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        // A filesystem failure must not replace the original operation exception.
        $written = false;
        foreach (['operation-'.$this->record['operation_id'].'.json', 'operation.json'] as $name) {
            $path = $this->directory.'/'.$name;
            if (is_dir($this->directory) && @file_put_contents($path.'.tmp', $bytes) === strlen($bytes)) {
                @chmod($path.'.tmp', 0600);
                $written = @rename($path.'.tmp', $path) || $written;
            }
        }
        if (! $written && $this->record['reason_code'] !== 'running') {
            error_log($bytes);
        }

    }

    private static function category(\Throwable $e): string
    {
        if ($e instanceof QueryException || $e instanceof \PDOException) {
            $info = $e->errorInfo ?? [];

            return match (true) {
                ($info[0] ?? '') === '42S02' => 'missing_table',
                ($info[0] ?? '') === '42S22' => 'missing_column',
                ($info[1] ?? 0) === 1064 => 'sql_syntax',
                in_array($info[1] ?? 0, [1792, 1207], true) => 'read_only_transaction',
                in_array($info[1] ?? 0, [1205, 3024], true) => 'database_timeout',
                ($info[0] ?? '') === '28000' => 'database_permission',
                default => 'database_error',
            };
        }

        return match (true) {
            $e instanceof \JsonException => 'serialization',
            $e instanceof MigrationExportException => 'export_precondition',
            $e instanceof \ErrorException => 'php_runtime_warning',
            $e instanceof \Error => 'php_runtime_error',
            default => 'runtime_error',
        };
    }
}
