<?php

namespace App\Services\MigrationExport;

/** Request C only. No record bodies, exception messages, stack traces or integration credentials. */
final class AllegroSupplementDiagnostics
{
    private array $context;

    private ?string $checkpoint = null;

    private ?string $reserve = null;

    private bool $finished = false;

    private float $started;

    public function __construct(private string $directory, string $action, private array $identity)
    {
        $this->started = microtime(true);
        $this->context = ['action' => $action, 'phase' => 'validate_completed_inputs', 'plan_id' => null, 'shard' => null, 'row' => null,
            'part_id' => null, 'offer_id' => null,
            'checkpoint_present' => false, 'last_successful_checkpoint' => $this->stored()['last_successful_checkpoint'] ?? null];
    }

    public function arm(): void
    {
        $this->reserve = str_repeat('r', 512 * 1024);
        register_shutdown_function(function (): void {
            $fatal = error_get_last();
            $this->reserve = null;
            if (! $this->finished && $fatal && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $memory = str_contains($fatal['message'], 'Allowed memory size');
                $timeout = str_contains($fatal['message'], 'Maximum execution time');
                $this->persistFailure($memory ? 'supplement_memory_limit_exceeded' : ($timeout ? 'supplement_backend_timeout' : 'supplement_php_fatal_error'),
                    $memory ? 'OutOfMemoryError' : 'PhpFatalError', $fatal['file'], $fatal['line']);
            }
        });
    }

    public function stage(string $phase, ?array $state = null, ?string $folder = null): void
    {
        $this->context['phase'] = $phase;
        if ($folder !== null) {
            $this->checkpoint = $folder.'/checkpoint.json';
        }
        if ($state !== null) {
            $this->context['plan_id'] = $state['plan']['plan_id'] ?? null;
            $this->context['shard'] = $state['shard'] ?? null;
            $this->context['row'] = $state['row'] ?? null;
        }
    }

    public function committed(string $folder, array $state): void
    {
        $this->stage($state['phase'], $state, $folder);
        $this->context['last_successful_checkpoint'] = ['phase' => $state['phase'], 'plan_id' => $state['plan']['plan_id'] ?? null,
            'shard' => $state['shard'], 'row' => $state['row'], 'refresh_offset' => $state['refresh_offset'] ?? null,
            'readback_offset' => $state['readback_offset'] ?? null, 'sha256' => hash_file('sha256', $this->checkpoint)];
    }

    public function record(?string $part = null, ?string $offer = null): void
    {
        $this->context['part_id'] = $part !== null && preg_match('/\A[0-9]{1,30}\z/D', $part) ? $part : null;
        $this->context['offer_id'] = $offer !== null && preg_match('/\A[0-9]{1,30}\z/D', $offer) ? $offer : null;
    }

    public function failure(\Throwable $error): array
    {
        $this->finished = true;
        $this->reserve = null;
        $reason = $error instanceof MigrationExportException ? $error->reasonCode : match ($this->context['phase']) {
            'persist_checkpoint' => 'supplement_checkpoint_write_failed',
            'persist_parent_index' => 'supplement_index_write_failed',
            default => 'supplement_server_exception',
        };

        return $this->persistFailure($reason, class_basename($error), $error->getFile(), $error->getLine());
    }

    private function persistFailure(string $reason, string $class, string $file, int $line): array
    {
        $failure = $this->context + $this->identity;
        $failure['reason_code'] = $reason;
        $failure['backend_exception_class'] = preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $class) ? $class : 'AnonymousThrowable';
        $failure['checkpoint_present'] = $this->checkpoint !== null && is_file($this->checkpoint);
        $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
        $failure['file'] = preg_match('#\A(?:app|vendor)/[A-Za-z0-9_./-]+\.php\z#D', $relative) ? $relative : null;
        $failure['line'] = $failure['file'] ? $line : null;
        $failure['safe_message'] = $reason; // Never persist Throwable::getMessage(): it can contain data/SQL/secrets.
        $failure['duration_ms'] = (int) ((microtime(true) - $this->started) * 1000);
        $failure['failed_at'] = gmdate('c');
        $failure['memory_limit'] = ini_get('memory_limit');
        $failure['peak_memory_bytes'] = memory_get_peak_usage(true);
        $this->store(['last_operation_failed' => true, 'last_operation_failure' => $failure, 'last_successful_checkpoint' => $failure['last_successful_checkpoint']]);

        return $failure;
    }

    public function success(): void
    {
        $this->finished = true;
        $this->reserve = null;
        $stored = $this->stored();
        $this->store(['last_operation_failed' => false, 'last_operation_failure' => $stored['last_operation_failure'] ?? null,
            'last_successful_checkpoint' => $this->context['last_successful_checkpoint']]);
    }

    public function stored(): array
    {
        $path = $this->directory.'/request.json';
        if (! is_file($path) || filesize($path) > 32768) {
            return [];
        }
        $value = json_decode(file_get_contents($path), true);
        if (! is_array($value) || ($value['identity'] ?? null) !== $this->identity) {
            return [];
        }

        return array_intersect_key($value, array_flip(['last_operation_failure', 'last_operation_failed', 'last_successful_checkpoint']));
    }

    private function store(array $value): void
    {
        // A diagnostic write must never replace the checkpoint or mask the original failure.
        try {
            if (! is_dir($this->directory) && ! @mkdir($this->directory, 0700, true) && ! is_dir($this->directory)) {
                return;
            }
            $path = $this->directory.'/request.json';
            $temp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
            $bytes = json_encode($value + ['identity' => $this->identity], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (@file_put_contents($temp, $bytes, LOCK_EX) === strlen($bytes) && @chmod($temp, 0600)) {
                @rename($temp, $path);
            }
            if (is_file($temp)) {
                @unlink($temp);
            }
        } catch (\Throwable) {
        }
    }
}
