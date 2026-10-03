<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use LeoJeremy\PostgresRls\Contracts\TenantContext;
use LeoJeremy\PostgresRls\RlsContext;
use LeoJeremy\PostgresRls\Stancl\RlsBootstrapper;
use LeoJeremy\PostgresRls\Stancl\StanclTenantContext;
use LeoJeremy\PostgresRls\Tests\Fixtures\StanclTenant;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\TenancyServiceProvider;
use Workbench\App\Models\Note;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'tenancy.tenant_model' => StanclTenant::class,
        'tenancy.id_generator' => null,
        'tenancy.bootstrappers' => [RlsBootstrapper::class],
        'tenancy.database.central_connection' => 'pgsql',
    ]);

    $this->app->register(TenancyServiceProvider::class);

    // What an app's own TenancyServiceProvider wires up.
    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);

    $this->app->singleton(TenantContext::class, StanclTenantContext::class);
    $this->app->forgetInstance(RlsContext::class);
});

afterEach(function (): void {
    if (tenancy()->initialized) {
        tenancy()->end();
    }
});

it('sets the session variable when stancl initializes a tenant, and clears it on end', function (): void {
    $tenant = StanclTenant::create(['name' => 'Alpha']);

    tenancy()->initialize($tenant);
    expect($this->guc())->toBe((string) $tenant->id);

    tenancy()->end();
    expect($this->guc())->toBe('');
});

it('keeps tenancy() and the policies on the same tenant inside RlsContext', function (): void {
    $alpha = StanclTenant::create(['name' => 'Alpha']);
    $bravo = StanclTenant::create(['name' => 'Bravo']);

    $this->rls()->within($alpha->id, fn () => Note::create(['title' => 'alpha note']));

    $seen = $this->rls()->within($bravo->id, fn (): array => [
        'tenancy' => tenant()->getTenantKey(),
        'guc' => $this->guc(),
        'notes' => Note::count(),
    ]);

    expect($seen)->toBe(['tenancy' => $bravo->id, 'guc' => (string) $bravo->id, 'notes' => 0])
        ->and(tenancy()->initialized)->toBeFalse()
        ->and($this->guc())->toBe('');
});

it('restores the outer stancl tenant after a nested block, even one that throws', function (): void {
    $outer = StanclTenant::create(['name' => 'Outer']);
    $inner = StanclTenant::create(['name' => 'Inner']);

    tenancy()->initialize($outer);

    try {
        $this->rls()->within($inner->id, fn (): never => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
    }

    expect(tenant()->getTenantKey())->toBe($outer->id)
        ->and($this->guc())->toBe((string) $outer->id);

    $central = $this->rls()->central(fn (): array => [tenancy()->initialized, $this->guc()]);

    expect($central)->toBe([false, ''])
        ->and(tenant()->getTenantKey())->toBe($outer->id)
        ->and($this->guc())->toBe((string) $outer->id);
});

it('does not write a session-level value in transaction mode', function (): void {
    config(['rls.mode' => 'transaction']);
    $this->app->forgetInstance(RlsContext::class);
    $tenant = StanclTenant::create(['name' => 'Alpha']);

    // A plain SET here would outlive the request on a transaction-pooled
    // connection. Instead nothing is set: tenant tables read as empty
    // until the work runs inside RlsContext::within().
    tenancy()->initialize($tenant);
    expect($this->guc())->toBe('');

    expect($this->rls()->within($tenant->id, fn (): string => $this->guc()))->toBe((string) $tenant->id);
});
