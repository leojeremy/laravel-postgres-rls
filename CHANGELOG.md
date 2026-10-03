# Changelog

## Unreleased

First release.

- `current_tenant_id()` and `enable_rls_for()` SQL functions, for bigint, uuid or text tenant keys. `enable_rls_for()` is idempotent and supports central (NULL-tenant) rows.
- `RlsSchema` migration helpers: `protect()`, `enable()`, `disable()`, `defaultTenantColumn()`, `install()`, `uninstall()`.
- `RlsContext` and the `Rls` facade: `within()`, `central()`, `currentKey()`, `assertRunningAs()`. Session mode, and transaction mode (`SET LOCAL`) for transaction-pooling PgBouncer.
- Tenant set on both sessions of a read/write split connection.
- `rls.tenant` middleware with a pluggable tenant resolver.
- Queued jobs carry the dispatching tenant; `RunAsDispatchingTenant` job middleware for transaction mode.
- `StampsTenantId` model trait.
- stancl/tenancy adapter: `StanclTenantContext` and `RlsBootstrapper`.
- `rls:audit`: role check first, then policy presence, FORCE, expression, command and type, widening PERMISSIVE policies, unstamped NOT NULL columns; warnings for missing tenant_id indexes and unique indexes across tenants; `--strict`, `--database`, `--ignore`.
- Testing helpers: `CrossTenantProbe` (asserts SQLSTATE 42501, always rolls back) and `HandRolledContextScanner`.
- Runnable demo (`php artisan rls:demo`) and a Docker Compose Postgres with a non-superuser app role.
