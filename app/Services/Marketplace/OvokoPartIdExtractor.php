<?php

namespace App\Services\Marketplace;

class OvokoPartIdExtractor
{
    /** @return array<int, string> */
    private const KEY_PATHS = [
        'woo_product.meta._ovoko_part_id',
        'woo_product.meta.ovoko_part_id',
        'meta._ovoko_part_id',
        'meta.ovoko_part_id',
        'woo.meta._ovoko_part_id',
        'woo.meta.ovoko_part_id',
        'product.meta._ovoko_part_id',
        'product.meta.ovoko_part_id',
        '_ovoko_part_id',
        'ovoko_part_id',
    ];

    public function extract(mixed $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        if (is_array($payload)) {
            return $this->extractFromArray($payload) ?? $this->extractByRegex($this->payloadToString($payload));
        }

        $payloadString = $this->payloadToString($payload);
        if ($payloadString === '') {
            return null;
        }

        $decoded = json_decode($payloadString, true);
        if (is_array($decoded)) {
            return $this->extractFromArray($decoded) ?? $this->extractByRegex($payloadString);
        }

        return $this->extractByRegex($payloadString);
    }

    /** @return array<int, string> */
    public function knownPaths(): array
    {
        return self::KEY_PATHS;
    }

    private function extractFromArray(array $payload): ?string
    {
        foreach (self::KEY_PATHS as $path) {
            $value = $this->valueAtPath($payload, explode('.', $path));
            if ($this->isValidId($value)) {
                return trim((string) $value);
            }
        }

        return $this->findMetaValueRecursively($payload);
    }

    /** @param array<int, string> $segments */
    private function valueAtPath(array $payload, array $segments): mixed
    {
        $current = $payload;
        foreach ($segments as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    private function findMetaValueRecursively(array $payload): ?string
    {
        foreach (['_ovoko_part_id', 'ovoko_part_id'] as $key) {
            if (array_key_exists($key, $payload) && $this->isValidId($payload[$key])) {
                return trim((string) $payload[$key]);
            }
        }

        if (isset($payload['key'], $payload['value'])
            && in_array((string) $payload['key'], ['_ovoko_part_id', 'ovoko_part_id'], true)
            && $this->isValidId($payload['value'])) {
            return trim((string) $payload['value']);
        }

        foreach ($payload as $value) {
            if (is_array($value)) {
                $found = $this->findMetaValueRecursively($value);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function extractByRegex(string $payload): ?string
    {
        foreach (['_ovoko_part_id', 'ovoko_part_id'] as $key) {
            if (preg_match('/["\']'.preg_quote($key, '/').'["\']\s*[:=]\s*["\']?([A-Za-z0-9_-]+)/i', $payload, $matches)) {
                return $matches[1];
            }
            if (preg_match('/["\']key["\']\s*:\s*["\']'.preg_quote($key, '/').'["\'][^}\]]{0,300}["\']value["\']\s*:\s*["\']?([A-Za-z0-9_-]+)/is', $payload, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    private function isValidId(mixed $value): bool
    {
        return is_scalar($value) && trim((string) $value) !== '';
    }

    private function payloadToString(mixed $payload): string
    {
        if (is_scalar($payload) || $payload instanceof \Stringable) {
            return (string) $payload;
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
}
