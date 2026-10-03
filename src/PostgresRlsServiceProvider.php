<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use LeoJeremy\PostgresRls\Audit\ModelTableResolver;
use LeoJeremy\PostgresRls\Audit\PostgresAuditor;
use LeoJeremy\PostgresRls\Console\AuditCommand;
use LeoJeremy\PostgresRls\Contracts\Auditor;
use LeoJeremy\PostgresRls\Contracts\TenantContext;
use LeoJeremy\PostgresRls\Http\ScopeToTenant;
use LeoJeremy\PostgresRls\Queue\TenantPropagation;

final class PostgresRlsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rls.php', 'rls');

        $this->app->bind(KeyType::class, fn (Application $app): KeyType => KeyType::fromConfig($app->make('config')->get('rls.key_type')));
        $this->app->bind(Mode::class, fn (Application $app): Mode => Mode::fromConfig($app->make('config')->get('rls.mode')));

        $this->app->singleton(TenantSession::class, fn (Application $app): TenantSession => new TenantSession(
            $app->make('db'),
            $app->make('config')->get('rls.connection'),
        ));

        // Apps on stancl/tenancy rebind this to Stancl\StanclTenantContext.
        $this->app->singletonIf(TenantContext::class, GucTenantContext::class);

        $this->app->singleton(RlsContext::class);
        $this->app->singleton(TenantPropagation::class);

        $this->app->bind(Auditor::class, fn (Application $app, array $params): Auditor => new PostgresAuditor(
            $params['connection'] ?? $app->make('db')->connection($app->make('config')->get('rls.connection')),
            new ModelTableResolver(
                array_values((array) $app->make('config')->get('rls.audit.model_paths', [])),
                array_values((array) $app->make('config')->get('rls.audit.tenant_traits', [])),
            ),
            (string) $app->make('config')->get('rls.audit.schema', 'public'),
        ));
    }

    public function boot(): void
    {
        $this->callAfterResolving('router', function (Router $router): void {
            $router->aliasMiddleware('rls.tenant', ScopeToTenant::class);
        });

        if ((bool) $this->app->make('config')->get('rls.queue.propagate', true)) {
            $this->propagateTenantToQueuedJobs();
        }

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([__DIR__.'/../config/rls.php' => config_path('rls.php')], 'rls-config');
        $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'rls-migrations');

        $this->commands([AuditCommand::class]);
    }

    private function propagateTenantToQueuedJobs(): void
    {
        // A static hook on the Queue class: it resolves the context at
        // dispatch time rather than capturing this application instance.
        Queue::createPayloadUsing(static fn (): array => TenantPropagation::payload());

        $events = $this->app->make(Dispatcher::class);
        $events->listen(JobProcessing::class, fn (JobProcessing $e) => $this->app->make(TenantPropagation::class)->starting($e));
        $events->listen(JobProcessed::class, fn (JobProcessed $e) => $this->app->make(TenantPropagation::class)->finished($e));
        $events->listen(JobExceptionOccurred::class, fn (JobExceptionOccurred $e) => $this->app->make(TenantPropagation::class)->finished($e));
    }
}
