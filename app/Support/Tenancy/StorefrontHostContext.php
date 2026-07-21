<?php

namespace App\Support\Tenancy;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StorefrontHostContext
{
    public function __construct(private readonly ?Request $request = null) {}

    public function hasTenantContext(): bool
    {
        return $this->tenantUuid() !== null;
    }

    public function tenantUuid(): ?string
    {
        $request = $this->request ?? request();

        foreach (['tenant_uuid', 'tenantUuid'] as $key) {
            $value = $request->attributes->get($key);
            if (is_string($value) && $value !== '') return $value;
        }

        $tenant = $request->attributes->get('tenant');
        foreach (['uuid', 'id'] as $property) {
            $value = is_object($tenant) ? ($tenant->{$property} ?? null) : (is_array($tenant) ? ($tenant[$property] ?? null) : null);
            if (is_string($value) && $value !== '') return $value;
            if (is_int($value)) return (string) $value;
        }

        return null;
    }

    public function isCentralStorefront(): bool
    {
        return in_array($this->host(), $this->centralHosts(), true);
    }

    public function hostType(): string
    {
        if (Str::startsWith($this->host(), 'admin.')) return 'super_admin';
        if ($this->isCentralStorefront()) return 'central_storefront';
        if ($this->hasTenantContext()) return 'tenant_storefront';

        return 'unknown_host';
    }

    public function host(): string
    {
        return Str::lower($this->request?->getHost() ?? request()->getHost());
    }

    /** @return array<int, string> */
    public function centralHosts(): array
    {
        return array_values(array_unique(array_map(fn (string $host): string => Str::lower($host), config('storefront.central_hosts', []))));
    }
}
