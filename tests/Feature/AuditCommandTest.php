<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LeoJeremy\PostgresRls\Audit\RoleStatus;
use LeoJeremy\PostgresRls\Audit\TableReport;
use LeoJeremy\PostgresRls\Contracts\Auditor;

uses(RefreshDatabase::class);

function tenantTable(string $name): void
{
    Schema::create($name, function (Blueprint $table): void {
        $table->id();
        $table->foreignId('tenant_id');
    });
}

it('passes on a clean schema where every tenant table has RLS', function (): void {
    $this->artisan('rls:audit')
        ->expectsOutputToContain('All 3 tenant table(s) enforce RLS')
        ->assertSuccessful();
});

it('detects a tenant table with no policy', function (): void {
    tenantTable('rls_test_no_policy');

    $this->artisan('rls:audit')
        ->assertFailed()
        ->expectsOutputToContain('rls_test_no_policy');
});

it('detects a tenant table whose RLS is not FORCEd onto the owner', function (): void {
    tenantTable('rls_test_unforced');
    DB::select("SELECT enable_rls_for('rls_test_unforced')");
    DB::statement('ALTER TABLE rls_test_unforced ALTER COLUMN tenant_id SET DEFAULT current_tenant_id()');
    DB::statement('ALTER TABLE rls_test_unforced NO FORCE ROW LEVEL SECURITY');

    $this->artisan('rls:audit')
        ->assertFailed()
        ->expectsOutputToContain('rls_test_unforced');
});

it('detects a model-less tenant table without a tenant_id default', function (): void {
    tenantTable('rls_test_pivot');
    DB::select("SELECT enable_rls_for('rls_test_pivot')");

    $this->artisan('rls:audit')
        ->assertFailed()
        ->expectsOutputToContain('NOT NULL tenant_id that nothing fills in')
        ->expectsOutputToContain('rls_test_pivot');

    DB::statement('ALTER TABLE rls_test_pivot ALTER COLUMN tenant_id SET DEFAULT current_tenant_id()');

    $this->artisan('rls:audit')->assertSuccessful();
});

it('accepts a table without a default when a model stamps tenant_id', function (): void {
    tenantTable('invoices');
    DB::select("SELECT enable_rls_for('invoices')");

    $this->artisan('rls:audit')->assertFailed();

    config([
        'rls.audit.model_paths' => [dirname(__DIR__).'/Fixtures/Models'],
        'rls.audit.tenant_traits' => ['LeoJeremy\PostgresRls\Tests\Fixtures\Models\StampsTenant'],
    ]);

    $this->artisan('rls:audit')->assertSuccessful();
});

it('honours --ignore and rls.audit.ignored_tables', function (): void {
    tenantTable('legacy_a');
    tenantTable('legacy_b');

    $this->artisan('rls:audit', ['--ignore' => ['legacy_a']])
        ->assertFailed()
        ->expectsOutputToContain('legacy_b')
        ->doesntExpectOutputToContain('legacy_a');

    config(['rls.audit.ignored_tables' => ['legacy_b']]);

    $this->artisan('rls:audit', ['--ignore' => ['legacy_a']])->assertSuccessful();
});

it('does not report views, which cannot carry a policy', function (): void {
    // The original query read information_schema.columns alone, which
    // lists view columns too, so any view over a tenant table was reported
    // as a table missing its policy.
    DB::statement('CREATE VIEW recent_notes AS SELECT id, tenant_id, title FROM notes');

    $this->artisan('rls:audit')->assertSuccessful();
});

it('refuses to audit a non-Postgres connection', function (): void {
    config(['database.connections.sqlite_audit' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);

    $this->artisan('rls:audit', ['--database' => 'sqlite_audit'])
        ->assertFailed()
        ->expectsOutputToContain('only runs on Postgres');
});

/**
 * The ordering is the point: a role that bypasses RLS makes every table
 * finding meaningless, so the role verdict must be able to end the audit
 * before any table is inspected. Tested by behaviour, with an auditor
 * whose table audit must never run.
 */
it('audits the connecting role before any table, and stops there', function (): void {
    $this->app->bind(Auditor::class, fn (): Auditor => new class implements Auditor
    {
        public function role(): RoleStatus
        {
            return new RoleStatus('deployer', superuser: false, bypassRls: true);
        }

        public function tables(array $ignoredTables = []): TableReport
        {
            throw new LogicException('The table audit ran for a role that bypasses RLS.');
        }
    });

    $this->artisan('rls:audit')
        ->assertFailed()
        ->expectsOutputToContain('[deployer] holds BYPASSRLS');
});

it('fails when connected as a real superuser', function (): void {
    $this->superuser();

    $this->artisan('rls:audit', ['--database' => 'pgsql_superuser'])
        ->assertFailed()
        ->expectsOutputToContain('is a SUPERUSER');
});

it('fails when connected as a real BYPASSRLS role', function (): void {
    $super = $this->superuser();
    $super->beginTransaction();

    try {
        // Created and assumed inside a transaction that is rolled back:
        // CREATE ROLE is transactional in Postgres.
        $super->statement('CREATE ROLE rls_bypass_probe NOLOGIN NOSUPERUSER BYPASSRLS');
        $super->statement('SET LOCAL ROLE rls_bypass_probe');

        $this->artisan('rls:audit', ['--database' => 'pgsql_superuser'])
            ->assertFailed()
            ->expectsOutputToContain('[rls_bypass_probe] holds BYPASSRLS');
    } finally {
        $super->rollBack();
    }
});
