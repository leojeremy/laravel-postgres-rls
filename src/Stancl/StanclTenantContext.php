<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls\Stancl;

use LeoJeremy\PostgresRls\Contracts\TenantContext;

/**
 * TenantContext backed by stancl/tenancy, so tenancy() and the RLS session
 * variable agree inside RlsContext blocks.
 *
 * Bind it in a service provider:
 *     $this->app->singleton(TenantContext::class, StanclTenantContext::class);
 */
final class StanclTenantContext implements TenantContext
{
    public function currentKey(): int|string|null
    {
        return tenancy()->initialized ? tenant()?->getTenantKey() : null;
    }

    public function initialize(int|string $key): void
    {
        // stancl looks the tenant up by key when given a scalar.
        tenancy()->initialize($key);
    }

    public function end(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }
}
