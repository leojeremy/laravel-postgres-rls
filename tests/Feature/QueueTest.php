<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use LeoJeremy\PostgresRls\Queue\TenantPropagation;
use LeoJeremy\PostgresRls\RlsContext;
use LeoJeremy\PostgresRls\TenantSession;
use LeoJeremy\PostgresRls\Tests\Fixtures\Jobs\RecordTenantJob;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    RecordTenantJob::$seen = [];
});

it('runs a job as the tenant that dispatched it', function (): void {
    config(['queue.default' => 'sync']);

    $this->rls()->within(5, function (): void {
        RecordTenantJob::dispatch();

        // A sync job runs inline; the dispatching block keeps its tenant.
        expect($this->guc())->toBe('5');
    });

    RecordTenantJob::dispatch();

    expect(RecordTenantJob::$seen)->toBe(['5', ''])
        ->and($this->guc())->toBe('');
});

/**
 * What a queue worker (Horizon) actually does: one long-lived process, one
 * connection, jobs for many tenants in a row. A job must never inherit the
 * previous job's tenant, on success or on failure.
 */
it('gives each job on a worker its own tenant, and never the previous one', function (): void {
    config([
        'queue.default' => 'database',
        'queue.connections.database.retry_after' => 90,
        'queue.failed.driver' => 'null',
    ]);

    $this->rls()->within(1, fn () => RecordTenantJob::dispatch(fail: true));
    $this->rls()->within(2, fn () => RecordTenantJob::dispatch());
    RecordTenantJob::dispatch();

    // A stale value left on the worker's connection by something else.
    app(TenantSession::class)->set('99');

    foreach (range(1, 3) as $_) {
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1]);
    }

    expect(RecordTenantJob::$seen)->toBe(['1', '2', ''])
        // The worker's own state is put back after every job, failed or not.
        ->and($this->guc())->toBe('99');

    app(TenantSession::class)->clear();
});

/**
 * `fn () => Job::dispatch()` returns a PendingDispatch, which dispatches
 * when it is destroyed. Left to PHP, that is after within() has restored
 * the outer tenant, so the job would carry the OUTER tenant.
 */
it('dispatches a job returned from an arrow function inside the block', function (): void {
    config(['queue.default' => 'sync']);

    $this->rls()->within(1, function (): void {
        $this->rls()->within(2, fn () => RecordTenantJob::dispatch());
        $this->rls()->central(fn () => RecordTenantJob::dispatch());
    });

    expect(RecordTenantJob::$seen)->toBe(['2', '']);
});

it('runs jobs inside within() in transaction mode, through the job middleware', function (): void {
    config(['queue.default' => 'sync', 'rls.mode' => 'transaction']);
    $this->app->forgetInstance(RlsContext::class);
    $this->app->forgetInstance(TenantPropagation::class);

    $this->rls()->within(7, fn () => RecordTenantJob::dispatch(withMiddleware: true));
    RecordTenantJob::dispatch(withMiddleware: true);

    expect(RecordTenantJob::$seen)->toBe(['7', ''])
        ->and($this->guc())->toBe('');
});
