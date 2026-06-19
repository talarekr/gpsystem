<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use RuntimeException;
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
                    'log_modified_at' => null,
                    'latest_error_timestamp' => null,
                    'latest_error_header' => null,
                    'latest_error_message' => null,
                    'latest_error_block_first_80_lines' => [],
                    'latest_error_block_last_40_lines' => [],
                    'error_message' => 'Invalid diagnostics token.',
                ], 403);
            }

            $logFile = $this->latestLogFile();

            if ($logFile === null) {
                return response()->json($this->emptyPayload(storage_path('logs/laravel.log')));
            }

            $after = $this->parseAfter($request->query('after'));
            $blocks = $this->errorBlocks($logFile);
            $matchingBlocks = $after === null
                ? $blocks
                : array_values(array_filter(
                    $blocks,
                    fn (array $block): bool => $block['datetime'] > $after,
                ));
            $latestBlock = $matchingBlocks === [] ? null : $matchingBlocks[array_key_last($matchingBlocks)];
            $firstBlock = $matchingBlocks[0] ?? null;

            return response()->json($this->payload($logFile, $latestBlock, [
                'after' => $after?->format(DateTimeInterface::ATOM),
                'matching_error_count' => count($matchingBlocks),
                'first_error_after_timestamp' => $firstBlock['timestamp'] ?? null,
                'first_error_after_header' => $firstBlock['header'] ?? null,
            ]));
        } catch (Throwable $exception) {
            return response()->json([
                'ok' => false,
                'log_file' => isset($logFile) ? $logFile : storage_path('logs/laravel.log'),
                'log_modified_at' => isset($logFile) && is_string($logFile) && is_file($logFile) ? date('c', filemtime($logFile) ?: 0) : null,
                'latest_error_timestamp' => null,
                'latest_error_header' => null,
                'latest_error_message' => null,
                'latest_error_block_first_80_lines' => [],
                'latest_error_block_last_40_lines' => [],
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

    private function parseAfter(mixed $after): ?DateTimeImmutable
    {
        if ($after === null || $after === '') {
            return null;
        }

        $value = (string) $after;
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $value) === 1) {
            $value .= date('P');
        }

        return new DateTimeImmutable($value);
    }

    /** @return array<int, array{timestamp: string, datetime: DateTimeImmutable, header: string, message: ?string, lines: array<int, string>}> */
    private function errorBlocks(string $path): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException('Log file is not readable.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open log file.');
        }

        $blocks = [];
        $current = null;

        try {
            while (($line = fgets($handle)) !== false) {
                $line = rtrim($line, "\r\n");

                if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (?:staging|production)\.ERROR: (.*)$/', $line, $matches) === 1) {
                    if ($current !== null) {
                        $blocks[] = $current;
                    }

                    $current = [
                        'timestamp' => $matches[1],
                        'datetime' => new DateTimeImmutable($matches[1]),
                        'header' => $line,
                        'message' => $matches[2] !== '' ? $matches[2] : null,
                        'lines' => [$line],
                    ];

                    continue;
                }

                if ($current !== null) {
                    $current['lines'][] = $line;
                }
            }
        } finally {
            fclose($handle);
        }

        if ($current !== null) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    /** @param array<string, mixed> $extra */
    private function payload(string $logFile, ?array $block, array $extra = []): array
    {
        return array_merge([
            'ok' => true,
            'log_file' => $logFile,
            'log_modified_at' => is_file($logFile) ? date('c', filemtime($logFile) ?: 0) : null,
            'latest_error_timestamp' => $block['timestamp'] ?? null,
            'latest_error_header' => $block['header'] ?? null,
            'latest_error_message' => $block['message'] ?? null,
            'latest_error_block_first_80_lines' => isset($block['lines']) ? array_slice($block['lines'], 0, 80) : [],
            'latest_error_block_last_40_lines' => isset($block['lines']) ? array_slice($block['lines'], -40) : [],
        ], $extra);
    }

    private function emptyPayload(string $logFile): array
    {
        return $this->payload($logFile, null);
    }
}
