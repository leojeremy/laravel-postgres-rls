<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls\Tests;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LeoJeremy\PostgresRls\PostgresRlsServiceProvider;
use LeoJeremy\PostgresRls\RlsContext;
use Orchestra\Testbench\TestCase as Orchestra;
use Workbench\App\Models\Tenant;
use Workbench\App\Providers\WorkbenchServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [PostgresRlsServiceProvider::class, WorkbenchServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'pgsql');
    }

    protected function rls(): RlsContext
    {
        return $this->app->make(RlsContext::class);
    }

    /** @return array{0: Tenant, 1: Tenant} */
    protected function twoTenants(): array
    {
        return [Tenant::create(['name' => 'Alpha']), Tenant::create(['name' => 'Bravo'])];
    }

    /** Reads the session variable on the default connection ('' when unset). */
    protected function guc(): string
    {
        return (string) DB::scalar("SELECT current_setting('app.current_tenant_id', true)");
    }

    protected function superuser(): Connection
    {
        if ((string) env('DB_SUPERUSER_USERNAME') === '') {
            $this->markTestSkipped('Set DB_SUPERUSER_USERNAME to run the superuser trap tests.');
        }

        return DB::connection('pgsql_superuser');
    }
}
