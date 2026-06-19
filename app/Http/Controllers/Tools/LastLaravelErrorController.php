<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Throwable;

class LastLaravelErrorController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        try {
            if (! hash_equals('gps_images_import_2026', (string) $request->query('token', ''))) {
                return response()->json([
                    'ok' => false,
                    'log_file' => null,
                    'exists' => false,
                    'last_lines' => [],
                    'error_message' => 'Invalid diagnostics token.',
                ], 403);
            }

            $logFile = $this->latestLogFile();

            if ($logFile === null) {
                return response()->json([
                    'ok' => true,
                    'log_file' => storage_path('logs/laravel.log'),
                    'exists' => false,
                    'last_lines' => [],
                ]);
            }

            return response()->json([
                'ok' => true,
                'log_file' => $logFile,
                'exists' => true,
                'last_lines' => $this->tail($logFile, 200),
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'ok' => false,
                'log_file' => isset($logFile) ? $logFile : storage_path('logs/laravel.log'),
                'exists' => isset($logFile) && is_string($logFile) ? file_exists($logFile) : null,
                'last_lines' => [],
                'error_message' => $exception->getMessage(),
            ], 200);
        }
    }

    private function latestLogFile(): ?string
    {
        $default = storage_path('logs/laravel.log');

        if (is_file($default)) {
            return $default;
        }

        $files = File::glob(storage_path('logs/*.log')) ?: [];
        usort($files, fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $files[0] ?? null;
    }

    /** @return array<int, string> */
    private function tail(string $path, int $lines): array
    {
        if (! is_readable($path)) {
            throw new \RuntimeException('Log file is not readable.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open log file.');
        }

        try {
            $buffer = '';
            $chunkSize = 8192;
            $position = filesize($path);

            while ($position > 0 && substr_count($buffer, "\n") <= $lines) {
                $readSize = min($chunkSize, $position);
                $position -= $readSize;
                fseek($handle, $position);
                $buffer = fread($handle, $readSize).$buffer;
            }
        } finally {
            fclose($handle);
        }

        return array_slice(preg_split('/\R/u', trim($buffer)) ?: [], -$lines);
    }
}
