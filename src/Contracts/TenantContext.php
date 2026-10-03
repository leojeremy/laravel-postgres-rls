<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls\Contracts;

/**
 * The application's own idea of "which tenant is current".
 *
 * RlsContext keeps this and the database session variable in step. The
 * default implementation (GucTenantContext) has no state of its own and
 * reads the session variable, so the two can never disagree. Apps on
 * stancl/tenancy bind StanclTenantContext instead, so that tenancy() and
 * the policies see the same tenant.
 */
interface TenantContext
{
    /** Key of the tenant in scope, or null when running centrally. */
    public function currentKey(): int|string|null;

    public function initialize(int|string $key): void;

    public function end(): void;
}
