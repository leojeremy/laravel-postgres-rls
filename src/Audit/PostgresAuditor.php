<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls\Audit;

use Illuminate\Database\ConnectionInterface;
use LeoJeremy\PostgresRls\Contracts\Auditor;

final class PostgresAuditor implements Auditor
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ModelTableResolver $models,
        private readonly string $schema = 'public',
    ) {}

    public function role(): RoleStatus
    {
        $row = $this->db->selectOne(
            'SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user'
        );

        return new RoleStatus((string) $row->name, (bool) $row->rolsuper, (bool) $row->rolbypassrls);
    }

    public function tables(array $ignoredTables = []): TableReport
    {
        // Base tables only: a view over a tenant table has a tenant_id
        // column too, but a view cannot carry a policy.
        $columns = collect($this->db->select(<<<'SQL'
            SELECT c.table_name, c.is_nullable, c.column_default
            FROM information_schema.columns c
            JOIN information_schema.tables t
              ON t.table_schema = c.table_schema
             AND t.table_name = c.table_name
             AND t.table_type = 'BASE TABLE'
            WHERE c.table_schema = ?
              AND c.column_name = 'tenant_id'
            ORDER BY c.table_name
            SQL, [$this->schema]))
            ->reject(fn (object $column): bool => in_array($column->table_name, $ignoredTables, true))
            ->values();

        $tenantTables = $columns->pluck('table_name')->all();

        // Isolated means the policy exists AND row security is enabled AND
        // forced. A policy without FORCE silently exempts the table owner,
        // which is usually the very role the app connects as.
        $isolated = collect($this->db->select(<<<'SQL'
            SELECT p.tablename
            FROM pg_policies p
            JOIN pg_namespace n ON n.nspname = p.schemaname
            JOIN pg_class c ON c.relnamespace = n.oid AND c.relname = p.tablename
            WHERE p.schemaname = ?
              AND p.policyname = 'tenant_isolation'
              AND c.relrowsecurity
              AND c.relforcerowsecurity
            SQL, [$this->schema]))->pluck('tablename')->all();

        $stamped = $columns->isEmpty() ? [] : $this->models->stampedTables();

        $unstamped = $columns
            ->filter(fn (object $column): bool => $column->is_nullable === 'NO'
                && ! in_array($column->table_name, $stamped, true)
                && ! str_contains((string) $column->column_default, 'current_tenant_id()'))
            ->pluck('table_name')
            ->values()
            ->all();

        return new TableReport(
            tenantTables: $tenantTables,
            unisolated: array_values(array_diff($tenantTables, $isolated)),
            unstamped: $unstamped,
        );
    }
}
