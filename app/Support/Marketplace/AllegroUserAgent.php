<?php

namespace App\Support\Marketplace;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class AllegroUserAgent
{
    public const FALLBACK = 'GPswiss/v1.0 (+https://gpswiss.pl/api-info)';

    public static function value(): string
    {
        $configured = (string) config('marketplace.allegro_user_agent', '');

        return trim($configured) !== '' ? trim($configured) : self::FALLBACK;
    }

    /** @return array<string, string> */
    public static function header(): array
    {
        return ['User-Agent' => self::value()];
    }

    public static function request(): PendingRequest
    {
        return Http::withUserAgent(self::value());
    }
}
