<?php

namespace App\Services\Marketplace\ShippingRateAudit;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\DB;
use LogicException;
use WeakMap;

/** Block SQL mutations before execution, including accidental model/observer writes. */
final class ReadOnlyDatabase
{
    private static int $depth = 0;

    private static int $catalogDepth = 0;

    private static ?WeakMap $connections = null;

    private static ?WeakMap $dispatchers = null;

    public static function run(Closure $work): mixed
    {
        self::$depth++;
        try {
            self::$dispatchers ??= new WeakMap;
            $events = app('events');
            if (! isset(self::$dispatchers[$events])) {
                $events->listen(ConnectionEstablished::class, static fn ($event) => self::guard($event->connection));
                self::$dispatchers[$events] = true;
            }
            DB::connection();
            foreach (DB::getConnections() as $connection) {
                self::guard($connection);
            }

            return $work();
        } finally {
            self::$depth--;
        }
    }

    public static function catalogWrites(Closure $work): mixed
    {
        self::$catalogDepth++;
        try {
            return self::run($work);
        } finally {
            self::$catalogDepth--;
        }
    }

    private static function guard(Connection $connection): void
    {
        self::$connections ??= new WeakMap;
        if (isset(self::$connections[$connection])) {
            return;
        }
        $connection->beforeExecuting(static function (string $sql): void {
            $catalog = self::$catalogDepth > 0 && preg_match('/^\s*(?:insert(?:\s+(?:ignore|or\s+ignore))?\s+into|update)\s+[`"\[]?allegro_shipping_(?:rates|catalog_syncs)[`"\]]?(?:\s|\()/i', $sql);
            if (self::$depth > 0 && ! $catalog && ! preg_match('/^\s*select\b/i', $sql)) {
                throw new LogicException('Shipping rate audit blocked a non-SELECT database statement.');
            }
        });
        self::$connections[$connection] = true;
    }
}
