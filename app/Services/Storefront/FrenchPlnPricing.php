<?php

namespace App\Services\Storefront;

use Carbon\CarbonImmutable;

/** Final FR policy; source PLN only, margins before conversion, EUR rounded once. */
class FrenchPlnPricing
{
    public const PART_MULTIPLIER = 1.25;

    public const JAREK_MULTIPLIER = 1.25;

    public static function validRate(array $rate): bool
    {
        $value = $rate['rate'] ?? null;
        $date = $rate['effective_date'] ?? null;
        if (! ($rate['ok'] ?? false) || ($rate['currency'] ?? null) !== 'EUR'
            || ($rate['source'] ?? null) !== 'NBP_TABLE_A' || ! is_numeric($value)
            || ! is_finite((float) $value) || $value <= 0 || ! is_string($date)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        try {
            $effective = CarbonImmutable::createFromFormat('!Y-m-d', $date);

            return $effective && $effective->toDateString() === $date && ! $effective->isFuture()
                && ! $effective->lessThan(today()->subDays(7));
        } catch (\Throwable) {
            return false;
        }
    }

    public static function convert($amount, ?string $currency, float $multiplier, array $rate, int $deliveryCents = 0): array
    {
        $reason = $currency !== 'PLN' ? 'non_pln_price' :
            (! is_numeric($amount) || ! is_finite((float) $amount) || $amount <= 0 ? 'invalid_source_price' : null);
        $marked = $reason === null ? round((float) $amount * $multiplier, 4, PHP_ROUND_HALF_UP) : null;
        $reason ??= self::validRate($rate) ? null : 'no_nbp_rate';
        $cents = $reason === null ? round(round($marked / (float) $rate['rate'], 2, PHP_ROUND_HALF_UP) * 100, 0, PHP_ROUND_HALF_UP) : null;
        if ($cents !== null && (! is_finite($cents) || $cents < 1 || $cents + $deliveryCents > 99999999)) {
            $reason = 'price_out_of_range';
            $cents = null;
        }

        return ['cents' => $cents === null ? null : (int) $cents, 'reason' => $reason,
            'applied_margin' => (int) round(($multiplier - 1) * 100),
            'price_pln_after_margin' => $marked, 'nbp_eur_rate' => self::validRate($rate) ? (float) $rate['rate'] : null];
    }

    /** Mirrors convert(), including unavailable prices. Inputs are internal column names/constants. */
    public static function sql(string $amount, string $currency, float $multiplier, float $rate, int $deliveryCents = 0): string
    {
        if (! is_finite($rate) || $rate <= 0) {
            return 'NULL';
        }
        $cents = 'ROUND(ROUND('.$amount.' * '.sprintf('%.2F', $multiplier).' / '.sprintf('%.12F', $rate).', 2) * 100, 0)';

        return "CASE WHEN {$currency} = 'PLN' AND {$amount} > 0 AND {$cents} BETWEEN 1 AND ".(99999999 - $deliveryCents)." THEN {$cents} ELSE NULL END";
    }
}
