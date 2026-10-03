<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls\Queue;

use Closure;
use LeoJeremy\PostgresRls\RlsContext;

/**
 * Job middleware: runs the job inside RlsContext::within() for the tenant
 * that dispatched it, or central() when none did. Required in transaction
 * mode; optional in session mode, where TenantPropagation already does it.
 *
 *     public function middleware(): array
 *     {
 *         return [new RunAsDispatchingTenant];
 *     }
 */
final class RunAsDispatchingTenant
{
    public function handle(object $job, Closure $next): mixed
    {
        $rls = app(RlsContext::class);
        $tenant = TenantPropagation::tenantOf(property_exists($job, 'job') ? $job->job : null);

        return $tenant === null
            ? $rls->central(fn (): mixed => $next($job))
            : $rls->within($tenant, fn (): mixed => $next($job));
    }
}
