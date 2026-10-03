<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls\Tests\Fixtures\HandRolled;

/**
 * Allowed: reading tenancy()->initialized as a guard is not a context
 * switch. This docblock even mentions tenancy()->initialize($tenant) and
 * SET app.current_tenant_id, which must not count: comments are stripped.
 */
final class GuardOnly
{
    public function run(): bool
    {
        return tenancy()->initialized;
    }
}
