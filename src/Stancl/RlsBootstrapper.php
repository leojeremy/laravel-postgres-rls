<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls\Stancl;

use LeoJeremy\PostgresRls\KeyType;
use LeoJeremy\PostgresRls\Mode;
use LeoJeremy\PostgresRls\TenantSession;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * stancl/tenancy bootstrapper: sets the RLS session variable whenever
 * stancl initializes a tenant (for example from its domain middleware),
 * and clears it when tenancy ends. Add it to tenancy.bootstrappers.
 *
 * In transaction mode it does nothing. A session-level SET made here would
 * outlive the transaction on a transaction-pooled connection, which is
 * the leak that mode exists to prevent. Without it, tenant queries outside
 * RlsContext::within() see no rows: the setup fails closed.
 */
final class RlsBootstrapper implements TenancyBootstrapper
{
    public function __construct(
        private readonly TenantSession $session,
        private readonly KeyType $keyType,
        private readonly Mode $mode,
    ) {}

    public function bootstrap(Tenant $tenant): void
    {
        if ($this->mode === Mode::Transaction) {
            return;
        }

        $this->session->set($this->keyType->normalise($tenant->getTenantKey()));
    }

    public function revert(): void
    {
        if ($this->mode === Mode::Transaction) {
            return;
        }

        $this->session->clear();
    }
}
