<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\PendingDispatch;
use LeoJeremy\PostgresRls\Contracts\TenantContext;
use LeoJeremy\PostgresRls\Exceptions\TenantSessionMismatch;

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
     * @param  int|string|Model  $tenant  a tenant key, or the tenant model itself
     * @param  Closure(): TReturn  $fn
     * @return TReturn
     */
    public function within(int|string|Model $tenant, Closure $fn): mixed
    {
        $key = $this->keyType->normalise(self::keyOf($tenant));

        return $this->unitOfWork(function () use ($key, $fn): mixed {
            $previous = $this->tenants->currentKey();

            $this->tenants->initialize($key);
            $this->session->set($key, $this->local());

            try {
                return self::settle($fn());
            } finally {
                $this->restore($previous);
            }
        });
    }

    /**
     * The inverse: run code with no tenant in scope, then put back whatever
     * was in scope before. With no tenant set, tenant rows are invisible:
     * this is for central tables, and for the NULL-tenant rows of tables
     * protected with allowCentral.
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
                return self::settle($fn());
            }

            $this->tenants->end();
            $this->session->clear($this->local());

            try {
                return self::settle($fn());
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

    /**
     * Check what the DATABASE thinks the current tenant is before a
     * statement that is only correct because RLS scopes it, typically an
     * unconditional `DELETE FROM table` used to purge one tenant.
     *
     * "Some tenant is active" is not the same claim as "the tenant we were
     * asked to destroy is active": a stale value on a reused connection, or
     * no value at all on a table with central rows, turns that DELETE into
     * a different tenant's data, or the central rows. One SELECT turns the
     * assumption into a checked precondition.
     *
     * @param  int|string|Model|null  $expected  null asserts the central (no tenant) session
     *
     * @throws TenantSessionMismatch
     */
    public function assertRunningAs(int|string|Model|null $expected, string $operation): void
    {
        $want = $expected === null ? null : $this->keyType->normalise(self::keyOf($expected));
        $actual = $this->session->current();

        if ($want !== $actual) {
            throw TenantSessionMismatch::during($operation, $want, $actual);
        }
    }

    /**
     * `fn () => SomeJob::dispatch()` returns a PendingDispatch, which only
     * dispatches when it is destroyed: after this block has already put
     * the previous tenant back. Nested inside another tenant's block, the
     * job would be stamped with the OUTER tenant. Destroy it here, inside
     * the block, so it dispatches with the tenant it was written for.
     */
    private static function settle(mixed $result): mixed
    {
        if ($result instanceof PendingDispatch) {
            unset($result);

            return null;
        }

        return $result;
    }

    private static function keyOf(int|string|Model $tenant): int|string
    {
        if (! $tenant instanceof Model) {
            return $tenant;
        }

        // stancl's tenant models expose getTenantKey(); plain models getKey().
        $key = method_exists($tenant, 'getTenantKey') ? $tenant->getTenantKey() : $tenant->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw new \InvalidArgumentException(sprintf('Tenant model %s has no key yet. Save it first.', $tenant::class));
        }

        return $key;
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
