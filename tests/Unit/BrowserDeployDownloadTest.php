<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

/** Standalone helper tests: fake transports and temporary ZIPs; never boot Laravel or execute a deploy. */
class BrowserDeployDownloadTest extends TestCase
{
    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private string $folder;

    private string $fixture;

    private array $logs = [];

    private array $pauses = [];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2).'/deploy.example.php';
    }

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/deploy-download-test-'.bin2hex(random_bytes(8));
        mkdir($this->folder, 0700);
        $this->fixture = $this->zip(self::SHA);
    }

    protected function tearDown(): void
    {
        \removePath($this->folder);
    }

    private function zip(string $sha, ?string $comment = null): string
    {
        $path = $this->folder.'/fixture-'.bin2hex(random_bytes(4)).'.zip';
        $zip = new ZipArchive;
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('gpsystem-'.$sha.'/artisan', '<?php // synthetic archive fixture');
        $zip->addFromString('gpsystem-'.$sha.'/composer.json', '{}');
        $zip->setArchiveComment($comment ?? $sha);
        $zip->close();

        return $path;
    }

    private function log(): callable
    {
        return function (string $line): void {
            $this->logs[] = $line;
        };
    }

    private function pause(): callable
    {
        return function (int $microseconds): void {
            $this->pauses[] = $microseconds;
        };
    }

    public function test_503_falls_back_to_codeload_with_the_same_sha_and_can_extract(): void
    {
        $calls = [];
        $request = function ($url, $destination, $timeout) use (&$calls): array {
            $calls[] = [$url, $timeout];
            if (str_starts_with($url, 'https://github.com/')) {
                file_put_contents($destination, '503 private error body');

                return ['http_status' => 503, 'curl_errno' => 0];
            }
            $this->assertSame('https://codeload.github.com/talarekr/gpsystem/zip/'.self::SHA, $url);
            copy($this->fixture, $destination);

            return ['http_status' => 200, 'curl_errno' => 0];
        };
        $destination = $this->folder.'/download.zip';
        $source = \downloadZip(self::SHA, $destination, $request, $this->pause(), $this->log());
        $this->assertSame('codeload', $source);
        $this->assertCount(4, $calls);
        $this->assertSame([250000, 750000], $this->pauses);
        $this->assertSame([60, 60, 60, 60], array_column($calls, 1));
        $this->assertSame(hash_file('sha256', $this->fixture), hash_file('sha256', $destination));
        $this->assertStringContainsString('source=github_archive attempt=3/3 http_status=503 curl_errno=0 downloaded_bytes=22 expected_sha='.self::SHA, $this->logs[2]);
        $this->assertStringContainsString('final_source_used=codeload', end($this->logs));
        \safeExtractZip($destination, $this->folder.'/extract');
        $this->assertSame('gpsystem-'.self::SHA, basename(\findExtractedRepository($this->folder.'/extract')));
    }

    public function test_both_sources_down_stop_after_six_attempts_without_leaking_body_or_curl_error(): void
    {
        $calls = 0;
        $request = function ($url, $destination) use (&$calls): array {
            $calls++;
            file_put_contents($destination, 'PRIVATE_BODY_SECRET');

            return ['http_status' => 503, 'curl_errno' => 0, 'curl_error' => 'PRIVATE_CURL_SECRET'];
        };
        try {
            \downloadZip(self::SHA, $this->folder.'/download.zip', $request, $this->pause(), $this->log());
            $this->fail('Unavailable sources must stop the deploy');
        } catch (RuntimeException $exception) {
            $this->assertStringStartsWith('release_zip_sources_unavailable', $exception->getMessage());
            $this->assertStringNotContainsString('PRIVATE_', $exception->getMessage().implode('\n', $this->logs));
        }
        $this->assertSame(6, $calls);
        $this->assertCount(6, $this->logs);
        $this->assertSame([250000, 750000, 250000, 750000], $this->pauses);
        $this->assertFileDoesNotExist($this->folder.'/download.zip');
    }

    public static function transientFailures(): array
    {
        return ['429' => [429, 0], '500' => [500, 0], '502' => [502, 0], '503' => [503, 0], '504' => [504, 0], 'timeout' => [0, 28], 'connection error' => [0, 7], 'partial transfer' => [200, 18]];
    }

    #[DataProvider('transientFailures')]
    public function test_each_transient_failure_retries_then_uses_fallback(int $status, int $errno): void
    {
        $calls = 0;
        $request = function ($url, $destination) use (&$calls, $status, $errno): array {
            $calls++;
            if ($calls <= 3) {
                file_put_contents($destination, 'partial bytes');

                return ['http_status' => $status, 'curl_errno' => $errno];
            }
            copy($this->fixture, $destination);

            return ['http_status' => 200, 'curl_errno' => 0];
        };
        $this->assertSame('codeload', \downloadZip(self::SHA, $this->folder.'/download.zip', $request, $this->pause(), $this->log()));
        $this->assertSame(4, $calls);
    }

    public function test_primary_success_has_no_fallback_or_backoff(): void
    {
        $calls = 0;
        $request = function ($url, $destination) use (&$calls): array {
            $calls++;
            $this->assertSame('https://github.com/talarekr/gpsystem/archive/'.self::SHA.'.zip', $url);
            copy($this->fixture, $destination);

            return ['http_status' => 200, 'curl_errno' => 0];
        };
        $this->assertSame('github_archive', \downloadZip(self::SHA, $this->folder.'/download.zip', $request, $this->pause(), $this->log()));
        $this->assertSame(1, $calls);
        $this->assertSame([], $this->pauses);
    }

    public function test_terminal_404_stops_without_retry(): void
    {
        $this->expectExceptionMessage('release_download_http_blocked source=github_archive http_status=404');
        \downloadZip(self::SHA, $this->folder.'/download.zip', fn () => ['http_status' => 404, 'curl_errno' => 0], $this->pause(), $this->log());
    }

    public function test_wrong_sha_zip_is_blocked_before_extraction_or_app_copy(): void
    {
        $wrong = $this->zip(str_repeat('b', 40));
        $request = function ($url, $destination) use ($wrong): array {
            copy($wrong, $destination);

            return ['http_status' => 200, 'curl_errno' => 0];
        };
        $this->expectExceptionMessage('release_zip_sha_mismatch');
        \downloadZip(self::SHA, $this->folder.'/download.zip', $request, $this->pause(), $this->log());
    }

    public function test_wrong_commit_comment_is_blocked_even_with_expected_root(): void
    {
        $wrong = $this->zip(self::SHA, str_repeat('b', 40));
        $this->expectExceptionMessage('release_zip_sha_mismatch');
        \verifyDeployZipSha($wrong, self::SHA);
    }

    public function test_malformed_zip_is_a_clear_blocker(): void
    {
        $path = $this->folder.'/invalid.zip';
        file_put_contents($path, 'not a ZIP');
        $this->expectExceptionMessage('release_zip_invalid');
        \verifyDeployZipSha($path, self::SHA);
    }

    public function test_no_commit_comment_still_requires_the_full_expected_sha_root(): void
    {
        $path = $this->zip(self::SHA, '');
        \verifyDeployZipSha($path, self::SHA);
        $this->assertTrue(true);
    }

    public function test_main_is_resolved_once_and_cannot_move_during_fallback(): void
    {
        $apiCalls = 0;
        $requests = [];
        $request = function ($url, $destination, $timeout) use (&$apiCalls, &$requests): array {
            $requests[] = $url;
            if (str_starts_with($url, 'https://api.github.com/')) {
                $apiCalls++;
                $this->assertSame(10, $timeout);
                file_put_contents($destination, json_encode(['ref' => 'refs/heads/main', 'object' => ['type' => 'commit', 'sha' => $apiCalls === 1 ? self::SHA : str_repeat('b', 40)]]));

                return ['http_status' => 200, 'curl_errno' => 0];
            }
            $this->assertStringContainsString(self::SHA, $url);
            if (str_starts_with($url, 'https://github.com/')) {
                return ['http_status' => 503, 'curl_errno' => 0];
            }
            copy($this->fixture, $destination);

            return ['http_status' => 200, 'curl_errno' => 0];
        };
        $reference = $this->folder.'/ref.json';
        $sha = \resolveExpectedDeploySha($reference, null, $request, $this->pause(), $this->log());
        $this->assertSame(self::SHA, $sha);
        $this->assertSame('codeload', \downloadZip($sha, $this->folder.'/download.zip', $request, $this->pause(), $this->log()));
        $this->assertSame(1, $apiCalls);
        $this->assertCount(5, $requests);
        $this->assertFileDoesNotExist($reference);
    }

    public function test_explicit_expected_sha_mismatch_stops_before_downloading_zip(): void
    {
        $calls = 0;
        $request = function ($url, $destination) use (&$calls): array {
            $calls++;
            file_put_contents($destination, json_encode(['ref' => 'refs/heads/main', 'object' => ['type' => 'commit', 'sha' => str_repeat('b', 40)]]));

            return ['http_status' => 200, 'curl_errno' => 0];
        };
        try {
            \resolveExpectedDeploySha($this->folder.'/ref.json', self::SHA, $request, $this->pause(), $this->log());
            $this->fail('Explicit pin must match main');
        } catch (RuntimeException $exception) {
            $this->assertSame('expected_sha_mismatch', $exception->getMessage());
        }
        $this->assertSame(1, $calls);
        $this->assertFileDoesNotExist($this->folder.'/ref.json');
    }

    public function test_malformed_api_response_is_not_used_as_a_sha(): void
    {
        $request = function ($url, $destination): array {
            file_put_contents($destination, '{"PRIVATE_SECRET":"no SHA"}');

            return ['http_status' => 200, 'curl_errno' => 0];
        };
        $this->expectExceptionMessage('expected_sha_response_invalid');
        \resolveExpectedDeploySha($this->folder.'/ref.json', null, $request, $this->pause(), $this->log());
    }

    public function test_unavailable_api_stops_after_three_attempts_without_a_branch_zip(): void
    {
        $calls = 0;
        $request = function ($url) use (&$calls): array {
            $calls++;
            $this->assertStringStartsWith('https://api.github.com/', $url);

            return ['http_status' => 503, 'curl_errno' => 0];
        };
        try {
            \resolveExpectedDeploySha($this->folder.'/ref.json', null, $request, $this->pause(), $this->log());
            $this->fail('Cannot guess a SHA when API is unavailable');
        } catch (RuntimeException $exception) {
            $this->assertSame('expected_sha_resolution_unavailable', $exception->getMessage());
        }
        $this->assertSame(3, $calls);
        $this->assertSame([250000, 750000], $this->pauses);
    }

    public function test_invalid_sha_is_rejected_before_any_download(): void
    {
        $this->expectExceptionMessage('expected_sha_invalid');
        \downloadZip('main', $this->folder.'/download.zip', fn () => $this->fail('No download permitted'), $this->pause(), $this->log());
    }
}
