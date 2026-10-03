<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls\Console;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionResolverInterface;
use LeoJeremy\PostgresRls\Contracts\Auditor;

final class AuditCommand extends Command
{
    protected $signature = 'rls:audit
        {--database= : Connection to audit (defaults to rls.connection, then the default connection)}
        {--ignore=* : Table names to skip, on top of rls.audit.ignored_tables}';

    protected $description = 'Check that the database role and every tenant_id table actually enforce row-level security.';

    public function handle(ConnectionResolverInterface $db): int
    {
        $name = $this->option('database') ?: config('rls.connection');
        $connection = $db->connection($name);

        if ($connection->getDriverName() !== 'pgsql') {
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

        if ($report->passes()) {
            $this->components->info(sprintf(
                'All %d tenant table(s) enforce RLS, and role [%s] is subject to it.',
                count($report->tenantTables),
                $auditor->role()->name,
            ));

            return self::SUCCESS;
        }

        if ($report->unisolated !== []) {
            $this->components->error(sprintf(
                '%d tenant table(s) without an enabled and FORCEd tenant_isolation policy:',
                count($report->unisolated),
            ));

            foreach ($report->unisolated as $table) {
                $this->line("  - {$table}");
            }

            $this->newLine();
            $this->components->warn("Call SELECT enable_rls_for('<table>') (or RlsSchema::protect()) in the table's migration.");
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

        return self::FAILURE;
    }
}
