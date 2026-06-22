<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleTranslateDiagnosticsToolTest extends TestCase
{
    public function test_readiness_uses_api_key_basic_v2_probe_and_returns_safe_google_error_details(): void
    {
        config()->set('services.google_translate.enabled', true);
        config()->set('services.google_translate.mode', 'dry_run');
        config()->set('services.google_translate.key', 'secret-api-key');
        config()->set('services.google_translate.project_id', null);
        config()->set('services.google_translate.credentials_path', null);

        Http::fake([
            'translation.googleapis.com/language/translate/v2?key=secret-api-key' => Http::response([
                'error' => [
                    'message' => 'API key not valid. Please pass a valid API key.',
                    'status' => 'INVALID_ARGUMENT',
                    'errors' => [
                        ['reason' => 'keyInvalid'],
                    ],
                ],
            ], 400),
        ]);

        $this->getJson('/tools/check-google-translate-readiness?token=gps_images_import_2026')
            ->assertOk()
            ->assertJsonPath('api_test_ok', false)
            ->assertJsonPath('api_test_http_status', 400)
            ->assertJsonPath('google_error_status', 'INVALID_ARGUMENT')
            ->assertJsonPath('google_error_message', 'API key not valid. Please pass a valid API key.')
            ->assertJsonPath('google_error_reason', 'keyInvalid')
            ->assertJsonPath('api_test_endpoint_family', 'translate_v2_basic')
            ->assertJsonMissing(['secret-api-key']);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://translation.googleapis.com/language/translate/v2?key=secret-api-key'
                && $request['q'] === 'Oryginalna używana część samochodowa'
                && $request['source'] === 'pl'
                && $request['target'] === 'fr'
                && $request['format'] === 'text';
        });
    }

    public function test_translate_test_endpoint_returns_safe_google_error_details(): void
    {
        config()->set('services.google_translate.enabled', true);
        config()->set('services.google_translate.mode', 'dry_run');
        config()->set('services.google_translate.key', 'secret-api-key');
        config()->set('services.google_translate.project_id', null);
        config()->set('services.google_translate.credentials_path', null);

        Http::fake([
            'translation.googleapis.com/language/translate/v2?key=secret-api-key' => Http::response([
                'error' => [
                    'message' => 'Requests from this referer are blocked.',
                    'status' => 'PERMISSION_DENIED',
                    'errors' => [
                        ['reason' => 'forbidden'],
                    ],
                ],
            ], 403),
        ]);

        $this->getJson('/tools/test-google-translate?token=gps_images_import_2026')
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('api_test_http_status', 403)
            ->assertJsonPath('google_error_status', 'PERMISSION_DENIED')
            ->assertJsonPath('google_error_message', 'Requests from this referer are blocked.')
            ->assertJsonPath('google_error_reason', 'forbidden')
            ->assertJsonPath('api_test_endpoint_family', 'translate_v2_basic')
            ->assertJsonMissing(['secret-api-key']);
    }
}
