# laravel-postgres-rls

[![CI](https://github.com/leojeremy/laravel-postgres-rls/actions/workflows/ci.yml/badge.svg)](https://github.com/leojeremy/laravel-postgres-rls/actions/workflows/ci.yml)

PostgreSQL row-level security (RLS) as the tenant boundary for a Laravel app, plus an audit command that catches the setups where RLS looks switched on but is off.

With this in place, a raw query, a join, a report or a forgotten `where tenant_id = ?` cannot read or write another tenant's rows. The database refuses, whatever the PHP code does.

```php
Rls::within($tenant, function () {
    DB::table('notes')->get();                 // only this tenant's notes, no where clause
    DB::table('notes')->insert([               // SQLSTATE 42501:
        'tenant_id' => $otherTenant->id,       // new row violates row-level security policy
        'title' => 'planted',
    ]);
});
```

What is in the box:

- SQL functions and a migration helper that turn on RLS correctly for a table (ENABLE + FORCE + policy + column default).
- `RlsContext` (`Rls` facade): run code as a tenant or centrally, with the database session reset on every exit path.
- Request middleware, queued-job propagation and a model trait, so the tenant follows the work.
- An adapter for stancl/tenancy, which stays optional.
- `rls:audit`: checks the connecting role, every tenant table's policy, extra policies that widen it, and side channels.
- Test helpers: a cross-tenant insert probe and an architecture-test scanner.
- A runnable demo against a real Postgres.

## Why the database, and not only a global scope

A global scope (or stancl/tenancy's `BelongsToTenant`) adds `where tenant_id = ?` to Eloquent queries. It is a good default, but it only covers the queries it sees:

- `DB::table(...)`, `DB::select(...)` and raw SQL skip it.
- Joins and subqueries need the condition on every joined table, and nothing checks that.
- `withoutGlobalScopes()` is one call away.
- Writes are not checked: a scope filters reads, so nothing stops a row being created or updated with someone else's `tenant_id`.

A policy in Postgres applies to every statement on the table, from any code path, for reads and for writes. Keep the scope if you like it: with both, a missing scope returns nothing instead of everything.

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- PostgreSQL 15+
- Optional: stancl/tenancy 3.x

## Install

The package is not on Packagist. Install it from GitHub:

```bash
composer config repositories.laravel-postgres-rls vcs https://github.com/leojeremy/laravel-postgres-rls
```

```bash
composer require leojeremy/laravel-postgres-rls:dev-main
```

Publish the config and the migration that creates the SQL functions:

```bash
php artisan vendor:publish --tag=rls-config
```

```bash
php artisan vendor:publish --tag=rls-migrations
```

Set `rls.key_type` (`bigint`, `uuid` or `text`) to the type of your tenants' primary key **before** you migrate; it decides what `current_tenant_id()` returns.

The role your app connects as must be `NOSUPERUSER NOBYPASSRLS` and must own the tables (see [trap 2](#2-a-superuser-or-bypassrls-role-skips-every-policy)). `php artisan rls:audit` checks this.

## Usage

### Protect each tenant table in its migration

```php
use LeoJeremy\PostgresRls\RlsSchema;

public function up(): void
{
    Schema::create('notes', function (Blueprint $table) {
        $table->id();
        $table->foreignId('tenant_id')->constrained();
        $table->string('title');
        $table->timestamps();
        $table->index('tenant_id');            // Postgres does not index foreign keys
    });

    RlsSchema::on(DB::connection())->protect('notes');
}

public function down(): void
{
    RlsSchema::on(DB::connection())->disable('notes');
    Schema::dropIfExists('notes');
}
```

`protect()` is two steps, also available on their own:

- `enable('notes')` runs `SELECT enable_rls_for('notes')`: ENABLE + FORCE row level security and (re)create the `tenant_isolation` policy. It is safe to run twice.
- `defaultTenantColumn('notes')` sets `tenant_id DEFAULT current_tenant_id()`, so inserts that never mention `tenant_id` (pivot `attach()`, the query builder, raw SQL) are stamped by the database.

Make unique indexes per tenant (`unique(['tenant_id', 'email'])`), not global: see [side channels](#side-channel-unique-indexes-across-tenants).

### Run code as a tenant

`RlsContext` (or the `Rls` facade) is the one way to switch tenant: in jobs, scheduled commands, webhooks, anywhere outside a request.

```php
use LeoJeremy\PostgresRls\Facades\Rls;

Rls::within($tenant, fn () => Report::generate());    // a key or the tenant model
Rls::central(fn () => PlatformLog::record($event));   // as nobody, then back
Rls::currentKey();                                     // the tenant in scope, or null
```

Both blocks restore the previous state in a `finally`, so they hold when the closure throws, and they nest. With no tenant in scope, every protected table reads as empty: the setup fails closed.

A closure that returns `SomeJob::dispatch()` is safe too. A `PendingDispatch` only dispatches when it is destroyed, which PHP would do after the block has already restored the previous tenant; the block destroys it inside instead (see [bugs found while building](#bugs-found-while-building-this)).

### Requests

Register how a request maps to a tenant, once, in a service provider:

```php
use LeoJeremy\PostgresRls\Http\ScopeToTenant;

ScopeToTenant::resolveTenantUsing(fn (Request $request) => $request->user()?->tenant_id);
```

Then add the `rls.tenant` middleware after authentication. The rest of the request runs inside `within()` for that tenant, or inside `central()` when the resolver returns null, so a value left on a reused connection (Octane) never reaches a request.

### Queued jobs

On by default (`rls.queue.propagate`). When a job is dispatched, the tenant in scope is written into its payload. The worker sets it before the job and restores what was there afterwards, on success and on failure. A worker that runs jobs for many tenants on one connection (Horizon) therefore never hands one job's tenant to the next. A job dispatched with no tenant runs with none.

In `transaction` mode, give jobs the middleware instead, because there is no transaction around a job to `SET LOCAL` in:

```php
use LeoJeremy\PostgresRls\Queue\RunAsDispatchingTenant;

public function middleware(): array
{
    return [new RunAsDispatchingTenant];
}
```

On stancl/tenancy with its `QueueTenancyBootstrapper`, set `rls.queue.propagate` to `false` and let stancl carry the tenant.

### Models

The column default already stamps `tenant_id` in the database, but Eloquent never reads that value back, so `$note->tenant_id` is null after `create()`. Add `StampsTenantId` when your code needs it:

```php
use LeoJeremy\PostgresRls\Eloquent\StampsTenantId;

class Note extends Model
{
    use StampsTenantId;   // fills tenant_id from the tenant in scope on create
}
```

### Central rows

Some tables hold both tenant rows and rows that belong to nobody: a platform audit log, shared templates. `protect('audit_log', allowCentral: true)` writes a two-way policy instead:

```sql
USING ((tenant_id IS NULL AND current_tenant_id() IS NULL) OR tenant_id = current_tenant_id())
```

Tenants see only their rows. The central session (no tenant set) sees only the NULL-tenant rows. Neither can write the other's. Read [trap 5](#5-with-central-rows-no-tenant-no-longer-means-no-rows) before you use it.

### Guarding destructive statements

An unconditional `DELETE FROM table`, used to purge one tenant, is correct only because RLS scopes it. Check that precondition instead of assuming it:

```php
Rls::within($tenant, function () use ($tenant) {
    Rls::assertRunningAs($tenant, 'purge tenant data');   // throws TenantSessionMismatch
    DB::delete('DELETE FROM notes');
});
```

`assertRunningAs(null, ...)` asserts the central session. It reads what the **database** thinks the tenant is, so a stale value on a reused connection is caught too.

### With stancl/tenancy

Bind the adapter so `tenancy()` and the policies always agree, and add the bootstrapper so stancl's own middleware sets the session variable:

```php
// AppServiceProvider::register()
$this->app->singleton(
    \LeoJeremy\PostgresRls\Contracts\TenantContext::class,
    \LeoJeremy\PostgresRls\Stancl\StanclTenantContext::class,
);
```

```php
// config/tenancy.php
'bootstrappers' => [
    \LeoJeremy\PostgresRls\Stancl\RlsBootstrapper::class,
    // ...
],
```

Without stancl, the default `TenantContext` keeps no state of its own: it reads the session variable, so PHP and the database cannot disagree about the current tenant.

### Read replicas

With a read/write split, a Laravel connection holds two database sessions and sends SELECTs to the read one. The tenant is written to both, so reads and writes inside one block run as the same tenant.

## How it works

Three pieces, all in [`RlsSchema::installSql()`](src/RlsSchema.php):

1. **A session variable (GUC).** `RlsContext` sets `app.current_tenant_id` with `set_config()`, which takes a bound parameter like any other value (a plain `SET` cannot be parameterised).
2. **A policy per table.** `tenant_isolation` compares each row with `current_tenant_id()`:

   ```sql
   CREATE FUNCTION current_tenant_id() RETURNS bigint AS $$
       SELECT NULLIF(current_setting('app.current_tenant_id', true), '')::bigint
   $$ LANGUAGE sql STABLE;

   CREATE POLICY tenant_isolation ON notes
       USING (tenant_id = current_tenant_id())          -- which rows you can see
       WITH CHECK (tenant_id = current_tenant_id());    -- which rows you can write
   ```

3. **FORCE.** `ALTER TABLE notes FORCE ROW LEVEL SECURITY` makes the policy apply to the table's owner too.

## The traps

Each of these leaves RLS looking enabled while it protects less than you think. Each has a test that shows the failure, and the audit catches the first four.

### 1. Without FORCE, the table owner is exempt

`ENABLE ROW LEVEL SECURITY` alone does not apply policies to the table's owner. The owner is usually the role that ran the migrations, which is usually the role the app connects as. So the app, the one client that matters, bypasses every policy. `enable_rls_for()` always FORCEs, and the audit fails any table that is not forced.

Test: [`TrapsTest`: exempts the table owner when RLS is enabled but not FORCEd](tests/Feature/TrapsTest.php)

### 2. A SUPERUSER or BYPASSRLS role skips every policy

Postgres does not consult policies at all for these roles, FORCE included. Every table can be perfectly configured and isolation can still be off.

This is not theoretical. The first deploy of the app this package came from connected as a role that the managed Postgres provider created, and that role held BYPASSRLS. The audit at the time only looked at tables, and it reported "All 49 tenant-scoped table(s) have RLS isolation enforced" while a probe table with a `USING (false)` policy (deny everything) still returned its row. That is why `rls:audit` checks the connecting role first and stops there if the role bypasses RLS.

Tests: [`TrapsTest`: lets a SUPERUSER or BYPASSRLS role skip every policy](tests/Feature/TrapsTest.php), [`AuditCommandTest`: fails when connected as a real superuser / BYPASSRLS role](tests/Feature/AuditCommandTest.php), [`RoleStatusTest`](tests/Unit/RoleStatusTest.php)

### 3. Pivot tables written without a model need `DEFAULT current_tenant_id()`

`$note->tags()->attach($tag)` inserts `note_id` and `tag_id` and nothing else. No model stamps `tenant_id`, so the row reaches the policy with `tenant_id` NULL and is rejected (Postgres checks the policy before the NOT NULL constraint, so the error is 42501, not 23502). The fix is a column default, so the database stamps the row. The audit lists every NOT NULL `tenant_id` column that has no such default and no tenant-stamping model.

Test: [`TrapsTest`: rejects pivot rows written without a model unless tenant_id defaults to current_tenant_id()](tests/Feature/TrapsTest.php)

### 4. Permissive policies are OR'd together

A second policy added later "for the reports screen", say `FOR SELECT USING (true)`, does not sit beside `tenant_isolation`: Postgres ORs permissive policies, so every tenant can now read every row. The audit fails any tenant table with another PERMISSIVE policy. A RESTRICTIVE one is fine, because restrictive policies are ANDed and can only narrow access.

Tests: [`AuditCommandTest`: fails a table where another PERMISSIVE policy widens tenant_isolation](tests/Feature/AuditCommandTest.php), and step 7 of the demo.

### 5. With central rows, "no tenant" no longer means "no rows"

With the plain policy, code that runs by mistake with no tenant set sees nothing and deletes nothing. With central rows, the same mistake sees, and can delete, all the central rows. The app this came from had exactly this table (its platform audit log): an unconditional `DELETE` with the variable unset removed the whole log and left every tenant's rows intact. Guard such statements with `assertRunningAs()`.

Tests: [`CentralRowsTest`](tests/Feature/CentralRowsTest.php)

### Side channel: unique indexes across tenants

RLS hides rows, not constraint violations. With a unique index on `email` alone, a tenant inserting an email that another tenant already uses gets SQLSTATE 23505 for a row it cannot see, which tells it the row exists. Include `tenant_id` in unique indexes. The audit warns about any unique index on a tenant table that leaves it out.

Test: [`AuditCommandTest`: warns about a unique index that leaves out tenant_id](tests/Feature/AuditCommandTest.php)

## The audit command

```bash
php artisan rls:audit
```

It fails when:

1. The connecting role is a SUPERUSER or holds BYPASSRLS. Nothing else is checked in that case, because nothing else would mean anything.
2. A base table with a `tenant_id` column lacks a working `tenant_isolation` policy. Working means: row security enabled and forced, the policy PERMISSIVE and `FOR ALL`, and its USING and WITH CHECK actually the tenant rule (with or without central rows). The audit reads the expression, so a hand-edited `USING (true)` with the right name fails.
3. A tenant table has another PERMISSIVE policy ([trap 4](#4-permissive-policies-are-ord-together)).
4. A NOT NULL `tenant_id` column has no `DEFAULT current_tenant_id()` and no model stamping it (one using a trait listed in `rls.audit.tenant_traits`, found under `rls.audit.model_paths`).

It warns, and fails only with `--strict`, when:

- No index starts with `tenant_id`, so every policy check scans the table.
- A unique index leaves out `tenant_id` (the side channel above).

Options: `--database=` to audit another connection, `--ignore=*` to skip tables (on top of `rls.audit.ignored_tables`), `--strict`. Run it in CI and in your deploy pipeline against the real production role.

## Stale tenants on reused connections

PHP-FPM usually opens a new database connection per request, but queue workers (Horizon), Octane and connection poolers reuse one connection for many jobs or requests. A session variable set for one job survives into the next unless something resets it, and the next job then runs as the previous job's tenant.

What handles it here:

- `RlsContext` resets the variable in a `finally`, on success and on throw, and resets it itself rather than trusting a bootstrapper chain.
- The middleware runs every request in `within()` or `central()`.
- Queue propagation sets each job's own tenant and restores the worker's state after it.
- `HandRolledContextScanner` lets an architecture test ban hand-rolled `tenancy()->initialize($x)` calls and raw writes to `app.current_tenant_id`, so `RlsContext` stays the only way in:

```php
use LeoJeremy\PostgresRls\Testing\HandRolledContextScanner;

it('switches tenant only through RlsContext', function () {
    expect((new HandRolledContextScanner)->scan([app_path()]))->toBe([]);
});
```

### PgBouncer and other poolers

| Setup | Mode | Notes |
|---|---|---|
| Direct connections | `session` (default) | |
| Pooler in session mode | `session` | The server connection belongs to one client until it disconnects. |
| Pooler in **transaction** mode (PgBouncer `pool_mode = transaction`) | `transaction` | A plain `SET` would stay on a server connection that the next client then uses. |

In `transaction` mode (`RLS_MODE=transaction`), `within()` and `central()` open a transaction and use `SET LOCAL`, so the value dies at COMMIT or ROLLBACK on that server connection. The cost is that tenant work must happen inside `within()`: the stancl bootstrapper deliberately does nothing in this mode, queries outside `within()` see no tenant rows, jobs need the `RunAsDispatchingTenant` middleware, and the request middleware wraps each request in one transaction.

In `session` mode, keep `within()` and `central()` outside transactions you may roll back. A plain `SET` is transactional in Postgres, but PHP-side tenancy state is not, so a rollback after the block can leave the two disagreeing.

## Testing your own app

- **Run your tests as the app's real kind of role**: `NOSUPERUSER NOBYPASSRLS`, owning the tables. As a superuser every isolation test passes for nothing. The [CI workflow](.github/workflows/ci.yml) shows the pattern: the Postgres image's bootstrap user is always a superuser, so the workflow creates a plain role and connects as that. [`SuiteRoleTest`](tests/Feature/SuiteRoleTest.php) fails the suite if that ever changes.
- **Probe every protected table** with `CrossTenantProbe`. It finds every table with a `tenant_isolation` policy and forges an INSERT carrying another tenant's id, so a new table is covered with no test change. It passes only on SQLSTATE 42501, "violates row-level security policy":

  ```php
  use LeoJeremy\PostgresRls\Facades\Rls;
  use LeoJeremy\PostgresRls\Testing\CrossTenantProbe;

  it('rejects cross-tenant inserts on every protected table', function () {
      [$a, $b] = Tenant::factory()->count(2)->create();
      $probe = new CrossTenantProbe(DB::connection());

      Rls::within($a, function () use ($probe, $b) {
          foreach ($probe->protectedTables() as $table) {
              $result = $probe->insertAs($table, $b->id);
              expect($result->rejectedByRls())->toBeTrue($result->describe());
          }
      });
  });
  ```

  Each probe runs in its own transaction or savepoint and is always rolled back. If a column's type defeats the generic filler values, the result says so; supply one with `$probe->withFiller('table.column', $value)`.

## Fixes made while extracting this

The code comes from a private application. Pulling it out turned up these problems, each fixed here with a test:

- **The per-table isolation test passed on any SQL error.** It asserted `toThrow(QueryException::class, '')`, so a type-cast failure on a filler value, which happens before the policy is evaluated, counted as isolation. `CrossTenantProbe` requires SQLSTATE 42501. The regression test builds a table with a deliberately broken policy, shows the old assertion passing on it, and shows the probe catching it ([`PerTableIsolationTest`](tests/Feature/PerTableIsolationTest.php)).
- **`enable_rls_for()` failed on a second call** (`CREATE POLICY` with no `DROP POLICY IF EXISTS`). It now replaces the policy, and it takes a `regclass`, so schema-qualified names work and a missing table fails immediately ([`SchemaTest`](tests/Feature/SchemaTest.php)).
- **Only bigint tenant keys worked.** `current_tenant_id()` was hardcoded to bigint and the key was cast to `int` before being put into a `SET` statement. The type is now configurable (bigint, uuid, text), keys are validated in PHP, and the value is bound through `set_config()` ([`SchemaTest`: supports uuid tenant keys end to end](tests/Feature/SchemaTest.php), [`KeyTypeTest`](tests/Unit/KeyTypeTest.php)).
- **"The role is audited before the tables" was tested by comparing string positions in the command's source file.** The role verdict is now a method tested directly, and the ordering is tested by behaviour: an auditor whose table check throws if it runs ([`AuditCommandTest`](tests/Feature/AuditCommandTest.php)).
- **Transaction pooling was unsupported and undocumented.** Added `transaction` mode and the table above ([`TransactionModeTest`](tests/Feature/TransactionModeTest.php)).
- **The audit trusted a policy by its name** and reported views as unprotected tables. It now reads the policy's expression, command and type, and only looks at base tables.
- **The central-rows guard only knew one table.** The original app protected its platform log with a hand-written two-way policy and a one-off guard. Both are now general: `protect(..., allowCentral: true)` and `assertRunningAs()`.

## Bugs found while building this

Two more, caught by tests written for the new pieces:

- **A job returned from an arrow function was dispatched as the wrong tenant.** In `within($inner, fn () => SomeJob::dispatch())`, PHP destroys the returned `PendingDispatch`, and so dispatches the job, after `within()` has put the outer tenant back. The job ran as the **outer** tenant. `within()` and `central()` now destroy it inside the block. The test fails without the fix ([`QueueTest`: dispatches a job returned from an arrow function inside the block](tests/Feature/QueueTest.php)).
- **A read/write split needs the variable on both sessions.** The original set it with a statement, which only reaches the write connection, so reads on a replica would see nothing. An early version of this package used a SELECT, which only reaches the read connection, so writes failed the policy. It is now written to both ([`ReadWriteSplitTest`](tests/Feature/ReadWriteSplitTest.php)).

## Demo

```bash
docker compose up -d
```

```bash
composer install
```

```bash
php artisan rls:demo
```

The demo migrates a fresh database as the app role, seeds two tenants, and then shows:

1. `DB::table('notes')->get()` with no `where` returns only the current tenant's notes.
2. With no tenant set, it returns nothing.
3. A forged insert for the other tenant is rejected with SQLSTATE 42501.
4. A job queued as one tenant, then run by a `queue:work` worker with no tenant set, runs as that tenant.
5. `rls:audit` passes as the app role.
6. One extra PERMISSIVE policy lets a tenant read everything, and the audit names it.
7. `rls:audit --database=pgsql_superuser` fails with the SUPERUSER message, against the same tables.

Each step checks its own claim, and the command exits non-zero if the database does not behave as described.

Compose publishes Postgres on port 54320 (override with `RLS_DB_PORT`). To use an existing Postgres instead, run [`docker/postgres/init.sql`](docker/postgres/init.sql) against it as a superuser and set `DB_PORT`, `DB_SUPERUSER_USERNAME` and `DB_SUPERUSER_PASSWORD` in your environment.

## Running the tests

```bash
docker compose up -d
```

```bash
composer test
```

```bash
composer analyse
```

The suite runs against a real Postgres. The superuser trap tests run when `DB_SUPERUSER_USERNAME` is set (it is, by default, for the compose service) and are skipped otherwise. `composer analyse` runs PHPStan (Larastan) at level 8.

## Limitations

- **RLS here guards against application bugs, not SQL injection.** The session variable is set by the app's own connection, so an attacker who can run arbitrary SQL as the app role can change it.
- **Fixed names.** The tenant column is `tenant_id`, the policy is `tenant_isolation` and the session variable is `app.current_tenant_id`.
- **One key type per schema.** `current_tenant_id()` has one return type.
- **One schema per audit run** (`rls.audit.schema`, default `public`).
- **A lost connection drops the tenant.** If Laravel reconnects mid-block, the new session has no tenant, so the rest of the block fails closed (reads nothing, writes are rejected) rather than leaking.
- **Streamed responses** run their body after the middleware has returned, outside the tenant block, so they see no tenant rows. Wrap the stream body in `Rls::within()` yourself.
- **Views run with their owner's rights** unless they are created `WITH (security_invoker = true)`. A view owned by a superuser bypasses RLS on the tables it reads.
- **Partitions.** Policies on a partitioned table apply to queries through the parent, not to queries that name a partition directly.
- **Transaction mode needs `within()`** for all tenant work (see above).
- **Session mode and transactions**: keep context switches outside transactions you might roll back. With a read replica, a switch made inside an open transaction reaches only the write session.
- **Testing so far.** The suite, PHPStan and the demo pass locally on PostgreSQL 18 with PHP 8.3 + Laravel 12, PHP 8.4 + Laravel 13 and PHP 8.5 + Laravel 13. PostgreSQL 15 and 17 are covered by the CI matrix only.

## Origin

Extracted and generalised from a multi-tenant SaaS I built in 2026. That app is pre-launch and this has not been run at scale. The app-specific parts (its tenant model, command names and paths) were replaced with configuration and a small `TenantContext` interface. The middleware, queue propagation, model trait, read-replica handling and the audit's policy, widening and side-channel checks were built for this package.

## License

MIT. See [LICENSE](LICENSE).
