<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

abstract class StorefrontTranslation extends Model
{
    public const STATUSES = ['missing', 'queued', 'translated', 'reviewed', 'failed', 'needs_update'];

    public const PUBLIC_STATUSES = ['translated', 'reviewed'];

    protected function casts(): array
    {
        return ['translated_at' => 'datetime', 'reviewed_at' => 'datetime', 'attempts' => 'integer'];
    }

    public function isReady(): bool
    {
        return in_array($this->status, self::PUBLIC_STATUSES, true);
    }

    public function matchesSourceHash(string $hash): bool
    {
        return is_string($this->source_hash) && hash_equals($hash, $this->source_hash);
    }

    public function setErrorMessageAttribute(?string $message): void
    {
        if ($message === null) {
            $this->attributes['error_message'] = null;

            return;
        }

        $message = preg_replace('/\bBearer\s+[^\s,;]+/i', 'Bearer [redacted]', $message) ?? '';
        $message = preg_replace('/((?:api[_-]?key|key|token|secret|password|credential|assertion|authorization)["\x27]?\s*[:=]\s*["\x27]?)[^\s,;&"\x27]+/i', '$1[redacted]', $message) ?? '';
        $this->attributes['error_message'] = Str::limit(strip_tags($message), 500, '…');
    }
}
