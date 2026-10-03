<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use LeoJeremy\PostgresRls\Audit\TableReport;
use LeoJeremy\PostgresRls\Contracts\Auditor;

final class AuditCommand extends Command
{
    protected $signature = 'rls:audit
        {--database= : Connection to audit (defaults to rls.connection, then the default connection)}
        {--ignore=* : Table names to skip, on top of rls.audit.ignored_tables}
        {--strict : Fail on warnings too (missing tenant_id index, unique indexes across tenants)}';

    protected $description = 'Check that the database role and every tenant_id table actually enforce row-level security.';

    public function handle(ConnectionResolverInterface $db): int
    {
        $name = $this->option('database') ?: config('rls.connection');
        $connection = $db->connection($name);

        if (! $connection instanceof Connection || $connection->getDriverName() !== 'pgsql') {
            $this->components->error('The RLS audit only runs on Postgres connections.');

            return self::FAILURE;
        }

        /** @var Auditor $auditor */
        $auditor = $this->laravel->make(Auditor::class, ['connection' => $connection]);

        // The role is checked first and can end the audit on its own: a
        // SUPERUSER or BYPASSRLS role skips every policy, so a clean table
        // report would be reassuring exactly when it should be alarming.
        $problem = $auditor->role()->problem();

        if ($problem !== null) {
            $this->components->error($problem);
            $this->components->warn('Connect as a role created with NOSUPERUSER NOBYPASSRLS, and let it own the tables so FORCE ROW LEVEL SECURITY applies to it.');

            return self::FAILURE;
        }

        $ignored = array_values(array_unique(array_map('strval', array_merge(
            (array) config('rls.audit.ignored_tables', []),
            (array) $this->option('ignore'),
        ))));

        $report = $auditor->tables($ignored);

        if ($report->tenantTables === []) {
            $this->components->info('No tenant_id tables found. Nothing to audit.');

            return self::SUCCESS;
        }

        if (! $report->passes()) {
            $this->reportFailures($report);
        }

        $this->reportWarnings($report);

        if (! $report->passes()) {
            return self::FAILURE;
        }

        if ($report->hasWarnings() && $this->option('strict')) {
            $this->components->error('Warnings are failures under --strict.');

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'All %d tenant table(s) enforce RLS, and role [%s] is subject to it.',
            count($report->tenantTables),
            $auditor->role()->name,
        ));

        return self::SUCCESS;
    }

    private function reportFailures(TableReport $report): void
    {
        if ($report->unisolated !== []) {
            $this->components->error(sprintf('%d tenant table(s) not isolated:', count($report->unisolated)));

            foreach ($report->unisolated as $table => $reason) {
                $this->line("  - {$table}: {$reason}");
            }

            $this->newLine();
            $this->components->warn("Call SELECT enable_rls_for('<table>') (or RlsSchema::protect()) in the table's migration.");
        }

        if ($report->widened !== []) {
            $this->components->error(sprintf(
                '%d tenant table(s) with other PERMISSIVE policies, which Postgres ORs with tenant_isolation:',
                count($report->widened),
            ));

            foreach ($report->widened as $table => $policies) {
                $this->line("  - {$table}: ".implode(', ', $policies));
            }

            $this->newLine();
            $this->components->warn('Drop them, or recreate them AS RESTRICTIVE so they can only narrow what tenant_isolation allows.');
        }

        if ($report->unstamped !== []) {
            $this->components->error(sprintf(
                '%d tenant table(s) with a NOT NULL tenant_id that nothing fills in:',
                count($report->unstamped),
            ));

            foreach ($report->unstamped as $table) {
                $this->line("  - {$table}");
            }

            $this->newLine();
            $this->components->warn(
                'Tables written without a tenant-stamping model (pivots, raw inserts) need '
                .'ALTER TABLE ... ALTER COLUMN tenant_id SET DEFAULT current_tenant_id(), '
                .'or attach() and raw inserts fail the policy.'
            );
        }
    }

    private function reportWarnings(TableReport $report): void
    {
        if ($report->unindexed !== []) {
            $this->components->warn(sprintf(
                '%d tenant table(s) with no index starting with tenant_id, so every policy check scans the table:',
                count($report->unindexed),
            ));

            foreach ($report->unindexed as $table) {
                $this->line("  - {$table}");
            }

            $this->newLine();
        }

        if ($report->crossTenantUnique !== []) {
            $this->components->warn(sprintf(
                '%d tenant table(s) with unique indexes that leave out tenant_id. A duplicate insert fails with 23505 '
                .'even when the existing row belongs to another tenant, which tells the caller it exists:',
                count($report->crossTenantUnique),
            ));

            foreach ($report->crossTenantUnique as $table => $indexes) {
                $this->line("  - {$table}: ".implode(', ', $indexes));
            }

            $this->newLine();
        }
    }
}
