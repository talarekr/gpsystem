<?php

namespace App\Services\Marketplace;

class AllegroChannel
{
    public const CANONICAL = 'allegro_main';

    public static function normalize(string $channel): string
    {
        return in_array($channel, ['allegro', self::CANONICAL], true) ? self::CANONICAL : $channel;
    }

    public static function isAllegro(string $channel): bool
    {
        return self::normalize($channel) === self::CANONICAL;
    }
}
