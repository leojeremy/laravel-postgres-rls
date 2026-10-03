<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls;

use Illuminate\Database\Connection;

/**
 * Schema helpers for migrations.
 *
 *     $rls = RlsSchema::on(DB::connection());
 *     $rls->protect('notes');   // ENABLE + FORCE + policy + tenant_id default
 */
final class RlsSchema
{
    public function __construct(private readonly Connection $db) {}

    public static function on(Connection $db): self
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
        $this->db->statement('DROP FUNCTION IF EXISTS enable_rls_for(regclass, boolean)');
        $this->db->statement('DROP FUNCTION IF EXISTS current_tenant_id()');
    }

    /**
     * ENABLE + FORCE row level security and (re)create the tenant_isolation
     * policy.
     *
     * $allowCentral adds central rows: rows with a NULL tenant_id that only
     * a session with NO tenant set can see or write (a platform audit log,
     * shared templates). Read the README's "central rows" section first:
     * with this policy, "no tenant" no longer means "no rows".
     */
    public function enable(string $table, bool $allowCentral = false): void
    {
        $this->db->select(
            sprintf('SELECT enable_rls_for(?::regclass, %s)', $allowCentral ? 'true' : 'false'),
            [$table],
        );
    }

    /** The inverse of enable(), for down() migrations. */
    public function disable(string $table): void
    {
        $wrapped = $this->db->getQueryGrammar()->wrapTable($table);

        $this->db->statement("DROP POLICY IF EXISTS tenant_isolation ON {$wrapped}");
        $this->db->statement("ALTER TABLE {$wrapped} NO FORCE ROW LEVEL SECURITY");
        $this->db->statement("ALTER TABLE {$wrapped} DISABLE ROW LEVEL SECURITY");
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
    public function protect(string $table, bool $allowCentral = false): void
    {
        $this->enable($table, $allowCentral);
        $this->defaultTenantColumn($table);
    }

    /** @return literal-string */
    public static function installSql(KeyType $keyType): string
    {
        // One of three literals, never user input.
        $type = match ($keyType) {
            KeyType::Bigint => 'bigint',
            KeyType::Uuid => 'uuid',
            KeyType::Text => 'text',
        };

        return <<<SQL
            CREATE OR REPLACE FUNCTION current_tenant_id() RETURNS {$type} AS \$\$
                SELECT NULLIF(current_setting('app.current_tenant_id', true), '')::{$type}
            \$\$ LANGUAGE sql STABLE;

            -- An earlier one-argument version is replaced, not overloaded.
            DROP FUNCTION IF EXISTS enable_rls_for(regclass);

            CREATE OR REPLACE FUNCTION enable_rls_for(target regclass, allow_central boolean DEFAULT false)
            RETURNS void AS \$\$
            DECLARE
                rule text := 'tenant_id = current_tenant_id()';
            BEGIN
                IF allow_central THEN
                    -- NULL-tenant rows belong to the central (no tenant) session.
                    rule := '(tenant_id IS NULL AND current_tenant_id() IS NULL) OR ' || rule;
                END IF;

                EXECUTE format('ALTER TABLE %s ENABLE ROW LEVEL SECURITY', target);
                -- FORCE makes the policy apply to the table owner too. Without
                -- it the role that ran the migrations (usually the app's own
                -- role) silently bypasses every policy.
                EXECUTE format('ALTER TABLE %s FORCE ROW LEVEL SECURITY', target);
                -- Drop first so a second call (a re-run migration, a fix-up
                -- migration) replaces the policy instead of erroring.
                EXECUTE format('DROP POLICY IF EXISTS tenant_isolation ON %s', target);
                EXECUTE format(
                    'CREATE POLICY tenant_isolation ON %s USING (%s) WITH CHECK (%s)',
                    target, rule, rule
                );
            END;
            \$\$ LANGUAGE plpgsql;
            SQL;
    }
}
