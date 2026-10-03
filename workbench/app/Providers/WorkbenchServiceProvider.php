<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use Workbench\App\Console\Commands\DemoCommand;

final class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $config = $this->app['config'];

        // A second connection to the same database as a superuser, used by
        // the demo to show what the audit says about such a role.
        $config->set('database.connections.pgsql_superuser', array_merge(
            $config->get('database.connections.pgsql'),
            [
                'username' => env('DB_SUPERUSER_USERNAME', 'postgres'),
                'password' => env('DB_SUPERUSER_PASSWORD', ''),
            ],
        ));

        $config->set('rls.audit.model_paths', [__DIR__.'/../Models']);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom([
            __DIR__.'/../../../database/migrations',
            __DIR__.'/../../database/migrations',
        ]);

        if ($this->app->runningInConsole()) {
            $this->commands([DemoCommand::class]);
        }
    }
}
