<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls\Audit;

use Illuminate\Database\Connection;
use LeoJeremy\PostgresRls\Contracts\Auditor;

final class PostgresAuditor implements Auditor
{
    public function __construct(
        private readonly Connection $db,
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
            ->reject(fn (\stdClass $column): bool => in_array($column->table_name, $ignoredTables, true))
            ->values();

        /** @var list<string> $tenantTables */
        $tenantTables = $columns->map(fn (\stdClass $column): string => (string) $column->table_name)->values()->all();

        if ($tenantTables === []) {
            return new TableReport([], [], []);
        }

        $flags = collect($this->db->select(<<<'SQL'
            SELECT c.relname, c.relrowsecurity, c.relforcerowsecurity
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = ? AND c.relkind IN ('r', 'p')
            SQL, [$this->schema]))->keyBy('relname');

        $policies = collect($this->db->select(<<<'SQL'
            SELECT tablename, policyname, permissive, cmd, qual, with_check
            FROM pg_policies
            WHERE schemaname = ?
            SQL, [$this->schema]))->groupBy('tablename');

        $unisolated = [];
        $widened = [];

        foreach ($tenantTables as $table) {
            $reason = $this->whyNotIsolated($flags->get($table), $policies->get($table, collect())->all());

            if ($reason !== null) {
                $unisolated[$table] = $reason;
            }

            // Permissive policies are OR'd together: any other one on the
            // table can admit rows the tenant rule would have refused.
            $others = $policies->get($table, collect())
                ->filter(fn (\stdClass $p): bool => $p->policyname !== 'tenant_isolation' && $p->permissive === 'PERMISSIVE')
                ->map(fn (\stdClass $p): string => (string) $p->policyname)->all();

            if ($others !== []) {
                $widened[$table] = array_values($others);
            }
        }

        $stamped = $this->models->stampedTables();

        $unstamped = array_values($columns
            ->filter(fn (\stdClass $column): bool => $column->is_nullable === 'NO'
                && ! in_array($column->table_name, $stamped, true)
                && ! str_contains((string) $column->column_default, 'current_tenant_id()'))
            ->map(fn (\stdClass $column): string => (string) $column->table_name)
            ->all());

        return new TableReport(
            tenantTables: $tenantTables,
            unisolated: $unisolated,
            unstamped: $unstamped,
            widened: $widened,
            unindexed: $this->unindexed($tenantTables),
            crossTenantUnique: $this->crossTenantUnique($tenantTables),
        );
    }

    /** @param  array<int, \stdClass>  $policies */
    private function whyNotIsolated(?\stdClass $flags, array $policies): ?string
    {
        $policy = collect($policies)->firstWhere('policyname', 'tenant_isolation');

        return match (true) {
            $policy === null => 'no tenant_isolation policy',
            ! $flags?->relrowsecurity => 'row level security is not enabled',
            ! $flags->relforcerowsecurity => 'row level security is not FORCEd, so the table owner is exempt',
            $policy->permissive !== 'PERMISSIVE' => 'tenant_isolation is RESTRICTIVE; on its own it admits no rows',
            $policy->cmd !== 'ALL' => "tenant_isolation applies to {$policy->cmd} only",
            ! PolicyShape::isolates($policy->qual) => "tenant_isolation USING is not a tenant rule: {$policy->qual}",
            ! PolicyShape::isolates($policy->with_check ?? $policy->qual) => "tenant_isolation WITH CHECK is not a tenant rule: {$policy->with_check}",
            default => null,
        };
    }

    /**
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function unindexed(array $tables): array
    {
        $indexed = collect($this->db->select(<<<'SQL'
            SELECT DISTINCT c.relname
            FROM pg_index i
            JOIN pg_class c ON c.oid = i.indrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = i.indkey[0]
            WHERE n.nspname = ? AND a.attname = 'tenant_id'
            SQL, [$this->schema]))->map(fn (\stdClass $row): string => (string) $row->relname)->all();

        return array_values(array_diff($tables, $indexed));
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, list<string>>
     */
    private function crossTenantUnique(array $tables): array
    {
        return collect($this->db->select(<<<'SQL'
            SELECT c.relname AS table_name, ic.relname AS index_name
            FROM pg_index i
            JOIN pg_class c ON c.oid = i.indrelid
            JOIN pg_class ic ON ic.oid = i.indexrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = ?
              AND i.indisunique
              AND NOT i.indisprimary
              AND NOT EXISTS (
                  SELECT 1 FROM pg_attribute a
                  WHERE a.attrelid = i.indrelid
                    AND a.attname = 'tenant_id'
                    AND a.attnum = ANY (i.indkey)
              )
            ORDER BY 1, 2
            SQL, [$this->schema]))
            ->filter(fn (\stdClass $row): bool => in_array($row->table_name, $tables, true))
            ->groupBy('table_name')
            ->map(fn ($rows): array => array_values($rows->map(fn (\stdClass $row): string => (string) $row->index_name)->all()))
            ->all();
    }
}
