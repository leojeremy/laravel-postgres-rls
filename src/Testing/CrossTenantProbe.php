<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls\Testing;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

/**
 * Forges an INSERT carrying another tenant's id into a table and reports
 * how Postgres answered. For use in an app's own test suite:
 *
 *     $probe = new CrossTenantProbe(DB::connection());
 *     RlsContext::within($tenantA->id, function () use ($probe, $tenantB) {
 *         foreach ($probe->protectedTables() as $table) {
 *             $result = $probe->insertAs($table, $tenantB->id);
 *             expect($result->rejectedByRls())->toBeTrue($result->describe());
 *         }
 *     });
 *
 * Every probe runs in its own transaction (a savepoint when one is already
 * open) and is always rolled back, so an accepted row never persists and a
 * rejected one does not abort the caller's transaction.
 */
final class CrossTenantProbe
{
    /** @var array<string, mixed> "table.column" or "column" => value */
    private array $fillers = [];

    public function __construct(
        private readonly Connection $db,
        private readonly string $schema = 'public',
    ) {}

    public function withFiller(string $column, mixed $value): self
    {
        $this->fillers[$column] = $value;

        return $this;
    }

    /**
     * Tables in the schema that have a tenant_id column and a
     * tenant_isolation policy. A new protected table is picked up with no
     * test change.
     *
     * @param  list<string>  $ignored
     * @return list<string>
     */
    public function protectedTables(array $ignored = []): array
    {
        $rows = $this->db->select(<<<'SQL'
            SELECT DISTINCT c.table_name
            FROM information_schema.columns c
            JOIN pg_policies p
              ON p.schemaname = c.table_schema
             AND p.tablename = c.table_name
             AND p.policyname = 'tenant_isolation'
            WHERE c.table_schema = ?
              AND c.column_name = 'tenant_id'
            ORDER BY c.table_name
            SQL, [$this->schema]);

        return array_values(array_filter(
            array_map(fn (\stdClass $row): string => (string) $row->table_name, $rows),
            fn (string $table): bool => ! in_array($table, $ignored, true),
        ));
    }

    public function insertAs(string $table, int|string $foreignTenantKey): ProbeResult
    {
        [$sql, $bindings] = $this->forgedInsert($table, $foreignTenantKey);

        try {
            $this->db->transaction(function () use ($sql, $bindings): never {
                $this->db->insert($sql, $bindings);

                throw new ProbeRollback;
            });
        } catch (ProbeRollback) {
            return new ProbeResult($table, accepted: true);
        } catch (QueryException $e) {
            return new ProbeResult(
                $table,
                accepted: false,
                sqlState: (string) ($e->errorInfo[0] ?? $e->getCode()),
                message: $e->getPrevious()?->getMessage() ?? $e->getMessage(),
            );
        }
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function forgedInsert(string $table, int|string $foreignTenantKey): array
    {
        $columns = $this->db->select(<<<'SQL'
            SELECT column_name, data_type, udt_name, is_nullable, column_default, is_identity
            FROM information_schema.columns
            WHERE table_schema = ? AND table_name = ?
            ORDER BY ordinal_position
            SQL, [$this->schema, $table]);

        $names = [];
        $bindings = [];

        foreach ($columns as $column) {
            $name = $column->column_name;
            $override = $this->fillers["{$table}.{$name}"] ?? $this->fillers[$name] ?? null;

            if ($name === 'tenant_id') {
                $names[] = $name;
                $bindings[] = $foreignTenantKey;

                continue;
            }

            if ($override === null && ($column->column_default !== null
                || $column->is_identity === 'YES'
                || $column->is_nullable === 'YES')) {
                continue;
            }

            $names[] = $name;
            $bindings[] = $override ?? self::filler($column->data_type, $column->udt_name);
        }

        $grammar = $this->db->getQueryGrammar();

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $grammar->wrapTable($this->schema.'.'.$table),
            implode(', ', array_map(fn (string $c): string => $grammar->wrap($c), $names)),
            implode(', ', array_fill(0, count($names), '?')),
        );

        return [$sql, $bindings];
    }

    /**
     * A value Postgres will accept for the type, so the INSERT reaches the
     * policy check. Unknown types get 'x'; if that fails, the result says
     * so instead of passing, and withFiller() supplies a real value.
     */
    private static function filler(string $dataType, string $udtName): mixed
    {
        return match ($udtName) {
            'int2', 'int4', 'int8', 'numeric', 'float4', 'float8', 'money' => 0,
            'bool' => 'f',
            'timestamp', 'timestamptz', 'date' => '1970-01-01',
            'time', 'timetz' => '00:00',
            'interval' => '0 seconds',
            'json', 'jsonb' => '{}',
            'uuid' => '00000000-0000-0000-0000-000000000000',
            'inet', 'cidr' => '127.0.0.1',
            'bytea' => '',
            default => str_starts_with($udtName, '_') ? '{}' : 'x',
        };
    }
}
