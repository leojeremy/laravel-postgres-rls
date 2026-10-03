<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls;

use LeoJeremy\PostgresRls\Contracts\TenantContext;

/**
 * Default TenantContext: the database session variable is the only state.
 * RlsContext writes it, so initialize() and end() have nothing to do.
 */
final class GucTenantContext implements TenantContext
{
    public function __construct(private readonly TenantSession $session) {}

    public function currentKey(): ?string
    {
        return $this->session->current();
    }

    public function initialize(int|string $key): void {}

    public function end(): void {}
}
