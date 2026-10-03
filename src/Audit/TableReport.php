<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls\Audit;

final readonly class TableReport
{
    /**
     * @param  list<string>  $tenantTables  base tables with a tenant_id column
     * @param  list<string>  $unisolated  of those, tables without an enabled and FORCEd tenant_isolation policy
     * @param  list<string>  $unstamped  NOT NULL tenant_id with no DEFAULT current_tenant_id() and no model stamping it
     */
    public function __construct(
        public array $tenantTables,
        public array $unisolated,
        public array $unstamped,
    ) {}

    public function passes(): bool
    {
        return $this->unisolated === [] && $this->unstamped === [];
    }
}
