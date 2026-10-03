<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LeoJeremy\PostgresRls\Audit\RoleStatus;
use LeoJeremy\PostgresRls\Audit\TableReport;
use LeoJeremy\PostgresRls\Contracts\Auditor;
use LeoJeremy\PostgresRls\RlsSchema;
use Workbench\App\Models\Note;

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

it('does not trust a policy by its name alone', function (): void {
    tenantTable('drifted');
    DB::statement('ALTER TABLE drifted ALTER COLUMN tenant_id SET DEFAULT current_tenant_id()');
    DB::statement('ALTER TABLE drifted ENABLE ROW LEVEL SECURITY');
    DB::statement('ALTER TABLE drifted FORCE ROW LEVEL SECURITY');
    DB::statement('CREATE POLICY tenant_isolation ON drifted USING (true) WITH CHECK (true)');

    $this->artisan('rls:audit')
        ->assertFailed()
        ->expectsOutputToContain('drifted: tenant_isolation USING is not a tenant rule: true');
});

it('rejects a tenant_isolation policy that covers SELECT only', function (): void {
    tenantTable('read_only_rule');
    DB::statement('ALTER TABLE read_only_rule ALTER COLUMN tenant_id SET DEFAULT current_tenant_id()');
    DB::statement('ALTER TABLE read_only_rule ENABLE ROW LEVEL SECURITY');
    DB::statement('ALTER TABLE read_only_rule FORCE ROW LEVEL SECURITY');
    DB::statement('CREATE POLICY tenant_isolation ON read_only_rule FOR SELECT USING (tenant_id = current_tenant_id())');

    $this->artisan('rls:audit')
        ->assertFailed()
        ->expectsOutputToContain('read_only_rule: tenant_isolation applies to SELECT only');
});

/**
 * Postgres combines PERMISSIVE policies with OR. One extra policy, added
 * for an admin screen or a report, silently widens every tenant's view.
 */
it('fails a table where another PERMISSIVE policy widens tenant_isolation', function (): void {
    [$alpha, $bravo] = $this->twoTenants();
    $this->rls()->within($bravo, fn () => Note::create(['title' => 'bravo private']));

    DB::statement('CREATE POLICY reports_read_all ON notes FOR SELECT USING (true)');

    // The leak is real: Alpha now reads Bravo's note.
    expect($this->rls()->within($alpha, fn () => DB::table('notes')->pluck('title')->all()))->toBe(['bravo private']);

    $this->artisan('rls:audit')
        ->assertFailed()
        ->expectsOutputToContain('notes: reports_read_all');
});

it('accepts an extra RESTRICTIVE policy, which can only narrow access', function (): void {
    DB::statement("CREATE POLICY hide_drafts ON notes AS RESTRICTIVE FOR SELECT USING (title <> 'draft')");

    $this->artisan('rls:audit')->assertSuccessful();
});

it('warns about a tenant table with no tenant_id index, and fails on it under --strict', function (): void {
    tenantTable('unindexed_rows');
    RlsSchema::on(DB::connection())->protect('unindexed_rows');

    $this->artisan('rls:audit')
        ->assertSuccessful()
        ->expectsOutputToContain('no index starting with tenant_id')
        ->expectsOutputToContain('unindexed_rows');

    $this->artisan('rls:audit', ['--strict' => true])->assertFailed();

    DB::statement('CREATE INDEX ON unindexed_rows (tenant_id)');

    $this->artisan('rls:audit', ['--strict' => true])->assertSuccessful();
});

/**
 * RLS hides rows, not constraint violations. A unique index on email alone
 * makes "is this email taken?" answerable across tenants: the insert fails
 * with 23505 even though the conflicting row is invisible.
 */
it('warns about a unique index that leaves out tenant_id, because it leaks existence', function (): void {
    [$alpha, $bravo] = $this->twoTenants();

    DB::statement('CREATE TABLE members (id bigserial PRIMARY KEY, tenant_id bigint NOT NULL DEFAULT current_tenant_id(), email text NOT NULL)');
    DB::statement('CREATE INDEX ON members (tenant_id)');
    DB::statement('CREATE UNIQUE INDEX members_email_unique ON members (email)');
    RlsSchema::on(DB::connection())->enable('members');

    $this->rls()->within($alpha, fn () => DB::insert("INSERT INTO members (email) VALUES ('ceo@example.com')"));

    $state = $this->rls()->within($bravo, function (): ?string {
        expect(DB::table('members')->count())->toBe(0); // Bravo cannot see the row…

        try {
            DB::transaction(fn () => DB::insert("INSERT INTO members (email) VALUES ('ceo@example.com')"));
        } catch (QueryException $e) {
            return $e->errorInfo[0];
        }

        return null;
    });

    expect($state)->toBe('23505'); // …but learns it exists.

    $this->artisan('rls:audit')
        ->assertSuccessful()
        ->expectsOutputToContain('unique indexes that leave out tenant_id')
        ->expectsOutputToContain('members: members_email_unique');

    DB::statement('DROP INDEX members_email_unique');
    DB::statement('CREATE UNIQUE INDEX members_tenant_email_unique ON members (tenant_id, email)');

    $this->artisan('rls:audit', ['--strict' => true])->assertSuccessful();
});
