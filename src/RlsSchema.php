<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls;

use Illuminate\Database\ConnectionInterface;

/**
 * Schema helpers for migrations.
 *
 *     $rls = RlsSchema::on(DB::connection());
 *     $rls->protect('notes');   // ENABLE + FORCE + policy + tenant_id default
 */
final class RlsSchema
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public static function on(ConnectionInterface $db): self
    {
        return new self($db);
    }

    /** Create current_tenant_id() and enable_rls_for(). Safe to run twice. */
    public function install(KeyType $keyType = KeyType::Bigint): void
    {
        $this->db->unprepared(self::installSql($keyType));
    }

    public function uninstall(): void
    {
        // No CASCADE: if a table still has a tenant_isolation policy this
        // fails, which is the right order. Drop the tables' RLS first.
        $this->db->statement('DROP FUNCTION IF EXISTS enable_rls_for(regclass)');
        $this->db->statement('DROP FUNCTION IF EXISTS current_tenant_id()');
    }

    /** ENABLE + FORCE row level security and (re)create the tenant_isolation policy. */
    public function enable(string $table): void
    {
        $this->db->select('SELECT enable_rls_for(?::regclass)', [$table]);
    }

    /**
     * Default tenant_id to current_tenant_id(), so inserts that never set it
     * (pivot attach(), raw inserts, query builder) are stamped by the
     * database and pass the policy's WITH CHECK.
     */
    public function defaultTenantColumn(string $table): void
    {
        $this->db->statement(sprintf(
            'ALTER TABLE %s ALTER COLUMN tenant_id SET DEFAULT current_tenant_id()',
            $this->db->getQueryGrammar()->wrapTable($table),
        ));
    }

    /** enable() + defaultTenantColumn(): what most tenant tables need. */
    public function protect(string $table): void
    {
        $this->enable($table);
        $this->defaultTenantColumn($table);
    }

    public static function installSql(KeyType $keyType): string
    {
        // $keyType->value is one of three enum literals, never user input.
        $type = $keyType->value;

        return <<<SQL
            CREATE OR REPLACE FUNCTION current_tenant_id() RETURNS {$type} AS \$\$
                SELECT NULLIF(current_setting('app.current_tenant_id', true), '')::{$type}
            \$\$ LANGUAGE sql STABLE;

            CREATE OR REPLACE FUNCTION enable_rls_for(target regclass) RETURNS void AS \$\$
            BEGIN
                EXECUTE format('ALTER TABLE %s ENABLE ROW LEVEL SECURITY', target);
                -- FORCE makes the policy apply to the table owner too. Without
                -- it the role that ran the migrations (usually the app's own
                -- role) silently bypasses every policy.
                EXECUTE format('ALTER TABLE %s FORCE ROW LEVEL SECURITY', target);
                -- Drop first so a second call (a re-run migration, a fix-up
                -- migration) replaces the policy instead of erroring.
                EXECUTE format('DROP POLICY IF EXISTS tenant_isolation ON %s', target);
                EXECUTE format(
                    'CREATE POLICY tenant_isolation ON %s '
                    || 'USING (tenant_id = current_tenant_id()) '
                    || 'WITH CHECK (tenant_id = current_tenant_id())',
                    target
                );
            END;
            \$\$ LANGUAGE plpgsql;
            SQL;
    }
}
