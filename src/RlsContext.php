<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls;

use Closure;
use LeoJeremy\PostgresRls\Contracts\TenantContext;

/**
 * The one sanctioned way to run code as a tenant (or as nobody) outside
 * whatever sets the tenant for a normal request: webhooks, scheduled
 * commands, queued jobs.
 *
 * Hand-rolled initialize() … end() pairs are fragile under queue workers
 * and Octane, which reuse the same database connection across jobs and
 * requests. If `app.current_tenant_id` survives a missed end(), the next
 * job silently runs as a stale tenant. This wrapper restores the previous
 * state in a `finally`, so it holds on the throw path too, and it resets
 * the session variable itself rather than trusting a bootstrapper chain.
 *
 * Session mode and open transactions: a plain `SET` is transactional in
 * Postgres, while the application's TenantContext is not. If a transaction
 * that wraps central() or within() is rolled back after the block, the
 * session variable goes back to its value at BEGIN, but an app-level
 * context (stancl's tenancy()) does not. Keep context switches outside
 * transactions you might roll back, or use transaction mode, where
 * RlsContext owns the transaction.
 */
final class RlsContext
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly TenantSession $session,
        private readonly KeyType $keyType,
        private readonly Mode $mode,
    ) {}

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $fn
     * @return TReturn
     */
    public function within(int|string $tenantKey, Closure $fn): mixed
    {
        $key = $this->keyType->normalise($tenantKey);

        return $this->unitOfWork(function () use ($key, $fn): mixed {
            $previous = $this->tenants->currentKey();

            $this->tenants->initialize($key);
            $this->session->set($key, $this->local());

            try {
                return $fn();
            } finally {
                $this->restore($previous);
            }
        });
    }

    /**
     * The inverse: run code with no tenant in scope, then put back whatever
     * was in scope before. With no tenant set, every tenant_isolation policy
     * matches nothing, so this is for central tables only.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $fn
     * @return TReturn
     */
    public function central(Closure $fn): mixed
    {
        return $this->unitOfWork(function () use ($fn): mixed {
            $previous = $this->tenants->currentKey();

            // Nothing to step out of, so nothing is written. This is the path
            // scheduled commands take, and it keeps them free of session
            // writes that a rollback could undo behind PHP's back.
            if ($previous === null && $this->session->current() === null) {
                return $fn();
            }

            $this->tenants->end();
            $this->session->clear($this->local());

            try {
                return $fn();
            } finally {
                $this->restore($previous);
            }
        });
    }

    public function currentKey(): int|string|null
    {
        return $this->tenants->currentKey();
    }

    public function mode(): Mode
    {
        return $this->mode;
    }

    private function restore(int|string|null $previous): void
    {
        $this->tenants->end();
        // Unconditional: a pooled connection must never keep a tenant id
        // after the block, whatever the TenantContext did on end().
        $this->session->clear($this->local());

        if ($previous !== null) {
            $this->tenants->initialize($previous);
            $this->session->set((string) $previous, $this->local());
        }
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $fn
     * @return TReturn
     */
    private function unitOfWork(Closure $fn): mixed
    {
        if ($this->mode === Mode::Session) {
            return $fn();
        }

        return $this->session->connection()->transaction($fn);
    }

    private function local(): bool
    {
        return $this->mode === Mode::Transaction;
    }
}
