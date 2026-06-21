<?php

namespace App\Services\Marketplace;

class OvokoPartIdExtractor
{
    /** @return array<int, string> */
    private const KEY_PATHS = [
        'woo_product.meta._ovoko_part_id',
        'woo_product.meta.ovoko_part_id',
        'woo_product.ovoko_part_id',
        'woo_product.legacy_payload_json._ovoko_part_id',
        'meta._ovoko_part_id',
        'meta.ovoko_part_id',
        'woo.meta._ovoko_part_id',
        'woo.meta.ovoko_part_id',
        'product.meta._ovoko_part_id',
        'product.meta.ovoko_part_id',
        'legacy_payload_json._ovoko_part_id',
        '_ovoko_part_id',
        'ovoko_id',
        'ovoko_part_id',
    ];

    public function extract(mixed $payload): ?string
    {
        return $this->extractWithPath($payload)['id'] ?? null;
    }

    /** @return array{id: ?string, path: ?string} */
    public function extractWithPath(mixed $payload): array
    {
        if ($payload === null || $payload === '') {
            return ['id' => null, 'path' => null];
        }

        if (is_array($payload)) {
            $match = $this->extractFromArrayWithPath($payload);
            if ($match['id'] !== null) {
                return $match;
            }

            return $this->extractByRegexWithPath($this->payloadToString($payload));
        }

        $payloadString = $this->payloadToString($payload);
        if ($payloadString === '') {
            return ['id' => null, 'path' => null];
        }

        $decoded = json_decode($payloadString, true);
        if (is_array($decoded)) {
            $match = $this->extractFromArrayWithPath($decoded);
            if ($match['id'] !== null) {
                return $match;
            }

            return $this->extractByRegexWithPath($payloadString);
        }

        return $this->extractByRegexWithPath($payloadString);
    }

    /** @return array<int, string> */
    public function knownPaths(): array
    {
        return self::KEY_PATHS;
    }

    private function extractFromArray(array $payload): ?string
    {
        return $this->extractFromArrayWithPath($payload)['id'] ?? null;
    }

    /** @return array{id: ?string, path: ?string} */
    private function extractFromArrayWithPath(array $payload): array
    {
        foreach (self::KEY_PATHS as $path) {
            $value = $this->valueAtPath($payload, explode('.', $path));
            if ($this->isValidId($value)) {
                return ['id' => trim((string) $value), 'path' => $path];
            }
        }

        return $this->findMetaValueRecursivelyWithPath($payload);
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
        return $this->findMetaValueRecursivelyWithPath($payload)['id'] ?? null;
    }

    /** @return array{id: ?string, path: ?string} */
    private function findMetaValueRecursivelyWithPath(array $payload, string $basePath = ''): array
    {
        foreach (['_ovoko_part_id', 'ovoko_id', 'ovoko_part_id'] as $key) {
            if (array_key_exists($key, $payload) && $this->isValidId($payload[$key])) {
                return ['id' => trim((string) $payload[$key]), 'path' => $basePath === '' ? $key : $basePath.'.'.$key];
            }
        }

        if (isset($payload['key'], $payload['value'])
            && in_array((string) $payload['key'], ['_ovoko_part_id', 'ovoko_id', 'ovoko_part_id'], true)
            && $this->isValidId($payload['value'])) {
            return ['id' => trim((string) $payload['value']), 'path' => ($basePath === '' ? 'meta_pair' : $basePath).'.'.(string) $payload['key']];
        }

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $found = $this->findMetaValueRecursivelyWithPath($value, $basePath === '' ? (string) $key : $basePath.'.'.(string) $key);
                if ($found['id'] !== null) {
                    return $found;
                }
            }
        }

        return ['id' => null, 'path' => null];
    }

    private function extractByRegex(string $payload): ?string
    {
        return $this->extractByRegexWithPath($payload)['id'] ?? null;
    }

    /** @return array{id: ?string, path: ?string} */
    private function extractByRegexWithPath(string $payload): array
    {
        foreach (['_ovoko_part_id', 'ovoko_id', 'ovoko_part_id'] as $key) {
            if (preg_match('/["\']'.preg_quote($key, '/').'["\']\s*[:=]\s*["\']?([A-Za-z0-9_-]+)/i', $payload, $matches)) {
                return ['id' => $matches[1], 'path' => 'regex.'.$key];
            }
            if (preg_match('/["\']key["\']\s*:\s*["\']'.preg_quote($key, '/').'["\'][^}\]]{0,300}["\']value["\']\s*:\s*["\']?([A-Za-z0-9_-]+)/is', $payload, $matches)) {
                return ['id' => $matches[1], 'path' => 'regex.meta_pair.'.$key];
            }
        }

        return ['id' => null, 'path' => null];
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
