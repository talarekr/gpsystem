<?php

namespace App\Services\Marketplace\Api;

use App\Models\MarketplaceAccount;

class MarketplaceApiManager
{
    public const CHANNELS = ['ovoko', 'allegro_main', 'ebay_de', 'ebay_fr'];

    public function client(string $channel): MarketplaceApiClient
    {
        $account = MarketplaceAccount::query()->where('code', $this->accountCode($channel))->first();
        return match ($channel) {
            'ovoko' => new OvokoApiClient($channel, $account),
            'allegro_main' => new AllegroApiClient($channel, $account),
            'ebay_de', 'ebay_fr' => new EbayApiClient($channel, $account),
            default => throw new \InvalidArgumentException('Unsupported marketplace channel.'),
        };
    }

    public function accountCode(string $channel): string
    {
        return $channel === 'ovoko' ? 'ovoko_main' : $channel;
    }
}
