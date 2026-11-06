<?php

namespace App\Services\MigrationExport;

/** Private business export: retain customer/order data, remove authentication and card capabilities. */
final class MigrationDataPolicy
{
    public static function sensitiveKey(string $key): bool
    {
        $key = strtolower(preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key));
        $compact = preg_replace('/[^a-z0-9]/', '', $key);

        return preg_match('/cardnumber|carddata|carddetails|cardtoken|creditcard|debitcard|paymentcard|unmaskedcard|primaryaccountnumber/', $compact) === 1
            || in_array($compact, ['fullpan', 'unmaskedpan', 'cardpan'], true)
            || preg_match('/password|passwd|credential|authorization|authentication|secret|token|signature|privatekey|apikey|merchantkey|securitycode|clientsecret|cryptogram|bearer/', $compact) === 1
            || preg_match('/^(?:pan|cvv|cvc|cvv2|cvc2|pin|pinblock|track1|track2|sessionid|checkoutkey|checkouturl|paymenturl|paymentlink|redirecturl|continueurl|notifyurl|accesskey|secretkey|authorizationheader|orderkey|auth|pass)$/', $compact) === 1
            || preg_match('/^(?:card|cardnumber|carddetails|creditcard|creditcardnumber|creditcarddetails|debitcard|paymentcard|fullcard|primaryaccountnumber)$/', $compact) === 1;
    }

    public static function identifier(string $key): bool
    {
        $key = strtolower(preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key));

        return preg_match('/(?:^|_)(?:id|ids|uuid|sku|skus|code|codes|number|numbers|reference|references)$/', $key) === 1
            || in_array($key, ['nip', 'tax_id', 'phone', 'postal_code', 'part_number', 'oem_number', 'manufacturer_code', 'external_reference', 'vin', 'additional_part_codes_saved', 'ean', 'gtin', 'barcode', 'tracking', 'waybill', 'awb', 'iban', 'path', 'original_path', 'original_filename', 'filename', 'file_name', 'original_relative_path', 'package_path'], true);
    }

    public static function clean(mixed $value, ?string $field = null): mixed
    {
        if (is_array($value)) {
            $kindValue = $value['type'] ?? $value['object'] ?? '';
            $kind = is_scalar($kindValue) ? strtolower((string) $kindValue) : '';
            if (in_array($kind, ['card', 'card_token', 'credit_card', 'debit_card'], true)) {
                return ['type' => $kind, 'details_omitted' => 'card_or_payment_token'];
            }
            $out = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && self::sensitiveKey($key)) {
                    continue;
                }
                $context = is_string($key) ? $key : ($field !== null && self::identifier($field) ? $field : null);
                $out[$key] = self::clean($item, $context);
            }

            return $out;
        }
        if ($value === null) {
            return null;
        }
        if ($field !== null && self::identifier($field) && is_scalar($value) && ! is_string($value)) {
            return (string) $value;
        }
        if (! is_string($value)) {
            return $value;
        }
        $trimmed = ltrim($value);
        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            $json = json_decode($value, true, 512, JSON_BIGINT_AS_STRING);
            if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
                return self::clean($json, $field);
            }
        }
        $value = preg_replace_callback('~https?://[^\s<>"\']+~u', static function ($match) {
            $url = parse_url($match[0]);
            if (! isset($url['host']) || preg_match('/(?:checkout\.stripe\.com|cs_(?:live|test)_|_secret_|sk_(?:live|test)_)/i', $match[0])) {
                return '[CAPABILITY_URL_REDACTED]';
            }

            $safeQuery = [];
            if (preg_match('/(?:^|\\.)ebay\\.(?:com|de|pl|co\\.uk|fr|it|es)$/iD', $url['host'])) {
                parse_str($url['query'] ?? '', $query);
                foreach (['var', 'item', 'itemid'] as $key) {
                    if (isset($query[$key]) && is_string($query[$key]) && preg_match('/^[0-9]{1,30}$/D', $query[$key])) {
                        $safeQuery[$key] = $query[$key];
                    }
                }
            }

            return ($url['scheme'] ?? 'https').'://'.$url['host'].($url['path'] ?? '/').($safeQuery ? '?'.http_build_query($safeQuery, '', '&', PHP_QUERY_RFC3986) : '');
        }, $value);
        $value = preg_replace('/\b(?:Bearer|Basic)\s+[A-Za-z0-9+\/=_\-.]+/i', '[AUTHORIZATION_REDACTED]', $value);
        $value = preg_replace('/\b(?:sk|rk)_(?:live|test)_[A-Za-z0-9]+\b|\b[A-Za-z0-9_]+_secret_[A-Za-z0-9]+\b/', '[PAYMENT_SECRET_REDACTED]', $value);

        $value = preg_replace('/\b(access_token|refresh_token|token|client_secret|password|api_key|authorization|card_number|cardnumber|cvv|cvc|pan)["\']?\s*[:=]\s*["\']?[^\s<>"\',;}]+/i', '$1=[REDACTED]', $value);
        if ($field === null || ! self::identifier($field)) {
            $value = preg_replace_callback('/(?<![0-9])(?:[0-9][ -]?){13,18}[0-9](?![0-9])/', static function ($match) {
                $digits = preg_replace('/[^0-9]/', '', $match[0]);
                if (strlen($digits) < 14 || strlen($digits) > 19 || count(array_unique(str_split($digits))) < 2) {
                    return $match[0];
                }
                $sum = 0;
                $double = false;
                for ($i = strlen($digits) - 1; $i >= 0; $i--) {
                    $digit = (int) $digits[$i];
                    if ($double) {
                        $digit *= 2;
                        if ($digit > 9) {
                            $digit -= 9;
                        }
                    }
                    $sum += $digit;
                    $double = ! $double;
                }

                return $sum % 10 === 0 ? '[CARD_NUMBER_REDACTED]' : $match[0];
            }, $value);
        }

        return $value;
    }
}
