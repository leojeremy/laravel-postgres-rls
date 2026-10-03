<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls\Audit;

final readonly class TableReport
{
    /**
     * Failures:
     *
     * @param  list<string>  $tenantTables  base tables with a tenant_id column
     * @param  array<string, string>  $unisolated  table => why its tenant_isolation policy does not isolate it
     * @param  array<string, list<string>>  $widened  table => other PERMISSIVE policies, which Postgres ORs with tenant_isolation
     * @param  list<string>  $unstamped  NOT NULL tenant_id with no DEFAULT current_tenant_id() and no model stamping it
     *
     * Warnings (isolation holds, but):
     * @param  list<string>  $unindexed  no index leads with tenant_id, so every policy check scans the table
     * @param  array<string, list<string>>  $crossTenantUnique  table => unique indexes without tenant_id, which reveal other tenants' values through 23505 errors
     */
    public function __construct(
        public array $tenantTables,
        public array $unisolated,
        public array $unstamped,
        public array $widened = [],
        public array $unindexed = [],
        public array $crossTenantUnique = [],
    ) {}

    public function passes(): bool
    {
        return $this->unisolated === [] && $this->widened === [] && $this->unstamped === [];
    }

    public function hasWarnings(): bool
    {
        return $this->unindexed !== [] || $this->crossTenantUnique !== [];
    }
}
