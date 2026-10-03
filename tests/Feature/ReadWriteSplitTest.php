<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LeoJeremy\PostgresRls\Contracts\TenantContext;
use LeoJeremy\PostgresRls\RlsContext;
use LeoJeremy\PostgresRls\TenantSession;

uses(RefreshDatabase::class);

/**
 * A connection with a read/write split has two PDOs, and Laravel sends
 * SELECTs to the read one. The tenant has to be set on both, or reads and
 * writes inside the same block run as different tenants.
 *
 * Both "hosts" here are the same server through different PDOs, which is
 * all the split is from Laravel's side.
 */
beforeEach(function (): void {
    $base = config('database.connections.pgsql');

    config(['database.connections.pgsql_split' => array_merge($base, [
        'read' => ['host' => [$base['host']]],
        'write' => ['host' => [$base['host']]],
        'sticky' => false,
    ])]);
    config(['rls.connection' => 'pgsql_split']);

    $this->app->forgetInstance(TenantSession::class);
    $this->app->forgetInstance(RlsContext::class);
    $this->app->forgetInstance(TenantContext::class);
});

afterEach(function (): void {
    DB::purge('pgsql_split');
});

function gucOn(string $pdo): ?string
{
    // Third argument: false = the write PDO, true = the read PDO.
    return DB::connection('pgsql_split')
        ->selectOne("SELECT current_setting('app.current_tenant_id', true) AS v", [], $pdo === 'read')->v;
}

it('sets the tenant on both the read and the write connection', function (): void {
    $split = DB::connection('pgsql_split');

    $seen = $this->rls()->within(42, fn (): array => [
        'read' => gucOn('read'),
        'write' => gucOn('write'),
        'separate' => $split->getReadPdo() !== $split->getPdo(),
    ]);

    expect($seen)->toBe(['read' => '42', 'write' => '42', 'separate' => true]);
});

it('clears both connections after the block', function (): void {
    $this->rls()->within(42, fn () => null);

    expect(gucOn('read'))->toBe('')
        ->and(gucOn('write'))->toBe('');
});
