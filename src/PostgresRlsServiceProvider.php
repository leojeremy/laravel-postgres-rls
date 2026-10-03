<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use LeoJeremy\PostgresRls\Audit\ModelTableResolver;
use LeoJeremy\PostgresRls\Audit\PostgresAuditor;
use LeoJeremy\PostgresRls\Console\AuditCommand;
use LeoJeremy\PostgresRls\Contracts\Auditor;
use LeoJeremy\PostgresRls\Contracts\TenantContext;

final class PostgresRlsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rls.php', 'rls');

        $this->app->bind(KeyType::class, fn (Application $app): KeyType => KeyType::fromConfig($app['config']->get('rls.key_type')));
        $this->app->bind(Mode::class, fn (Application $app): Mode => Mode::fromConfig($app['config']->get('rls.mode')));

        $this->app->singleton(TenantSession::class, fn (Application $app): TenantSession => new TenantSession(
            $app['db'],
            $app['config']->get('rls.connection'),
        ));

        // Apps on stancl/tenancy rebind this to Stancl\StanclTenantContext.
        $this->app->singletonIf(TenantContext::class, GucTenantContext::class);

        $this->app->singleton(RlsContext::class);

        $this->app->bind(Auditor::class, fn (Application $app, array $params): Auditor => new PostgresAuditor(
            $params['connection'] ?? $app['db']->connection($app['config']->get('rls.connection')),
            new ModelTableResolver(
                array_values((array) $app['config']->get('rls.audit.model_paths', [])),
                array_values((array) $app['config']->get('rls.audit.tenant_traits', [])),
            ),
            (string) $app['config']->get('rls.audit.schema', 'public'),
        ));
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([__DIR__.'/../config/rls.php' => config_path('rls.php')], 'rls-config');
        $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'rls-migrations');

        $this->commands([AuditCommand::class]);
    }
}
