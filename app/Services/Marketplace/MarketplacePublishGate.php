<?php

namespace App\Services\Marketplace;

class MarketplacePublishGate
{
    private const CHANNEL_FLAGS = [
        'ebay' => 'GPS_EBAY_PUBLISHING_ENABLED',
        'ebay_de' => 'GPS_EBAY_PUBLISHING_ENABLED',
        'ebay_fr' => 'GPS_EBAY_PUBLISHING_ENABLED',
        'allegro' => 'GPS_ALLEGRO_PUBLISHING_ENABLED',
        'allegro_main' => 'GPS_ALLEGRO_PUBLISHING_ENABLED',
        'ovoko' => 'GPS_OVOKO_PUBLISHING_ENABLED',
    ];

    public function allows(string $channel): bool
    {
        return $this->decision($channel)['allowed'];
    }

    public function decision(string $channel): array
    {
        $newFlags = ['GPS_EXTERNAL_API_WRITES_ENABLED', 'GPS_MARKETPLACE_PUBLISHING_ENABLED', self::CHANNEL_FLAGS[$channel] ?? strtoupper('GPS_'.$channel.'_PUBLISHING_ENABLED')];
        $hasNew = collect($newFlags)->contains(fn (string $flag): bool => getenv($flag) !== false || array_key_exists($flag, $_ENV) || array_key_exists($flag, $_SERVER));
        if (! $hasNew) {
            $allowed = filter_var(env('MARKETPLACE_PUBLISH_ENABLED', false), FILTER_VALIDATE_BOOL);
            return ['allowed' => $allowed, 'mode' => 'legacy', 'required_flags' => ['MARKETPLACE_PUBLISH_ENABLED'], 'blocking_flags' => $allowed ? [] : ['MARKETPLACE_PUBLISH_ENABLED']];
        }

        $blocking = array_values(array_filter($newFlags, fn (string $flag): bool => ! filter_var(env($flag, false), FILTER_VALIDATE_BOOL)));
        return ['allowed' => $blocking === [], 'mode' => 'gps_flags', 'required_flags' => $newFlags, 'blocking_flags' => $blocking];
    }
}
