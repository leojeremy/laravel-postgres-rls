<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls\Http;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use LeoJeremy\PostgresRls\Contracts\TenantResolver;
use LeoJeremy\PostgresRls\RlsContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs the rest of the request inside RlsContext::within() for the tenant
 * the resolver returns, or inside central() when it returns null, so a
 * value left on a reused connection (Octane) never leaks into a request.
 *
 * Register a resolver once, in a service provider:
 *
 *     ScopeToTenant::resolveTenantUsing(fn (Request $r) => $r->user()?->tenant_id);
 *
 * then add the `rls.tenant` middleware after authentication.
 */
final class ScopeToTenant
{
    public function __construct(
        private readonly RlsContext $rls,
        private readonly Container $container,
    ) {}

    /** @param  Closure(Request): (int|string|Model|null)  $resolver */
    public static function resolveTenantUsing(Closure $resolver): void
    {
        app()->instance(TenantResolver::class, new ClosureTenantResolver($resolver));
    }

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->container->bound(TenantResolver::class)) {
            throw new \LogicException(
                'No tenant resolver is registered. Call ScopeToTenant::resolveTenantUsing() in a service provider, '
                .'or bind '.TenantResolver::class.'.'
            );
        }

        $tenant = $this->container->make(TenantResolver::class)->resolve($request);

        return $tenant === null
            ? $this->rls->central(fn (): Response => $next($request))
            : $this->rls->within($tenant, fn (): Response => $next($request));
    }
}
