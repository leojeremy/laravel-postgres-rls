<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use LeoJeremy\PostgresRls\Mode;
use LeoJeremy\PostgresRls\RlsContext;
use LeoJeremy\PostgresRls\TenantSession;

/**
 * Transaction mode, for PgBouncer in transaction pooling. These tests do
 * NOT use RefreshDatabase: they need real top-level transactions, because
 * "the value dies at COMMIT" is the property under test.
 */
beforeEach(function (): void {
    config(['rls.mode' => 'transaction']);
    app()->forgetInstance(RlsContext::class);
    app(TenantSession::class)->clear();
});

it('scopes SET LOCAL to the transaction', function (): void {
    $session = app(TenantSession::class);

    DB::transaction(function () use ($session): void {
        $session->set('9', local: true);
        expect($session->current())->toBe('9');
    });

    // Nothing was reset by hand: COMMIT discarded it. On a pooled server
    // connection, the next client cannot inherit it.
    expect($session->current())->toBeNull();
});

it('shows that a plain SET outlives the transaction', function (): void {
    $session = app(TenantSession::class);

    DB::transaction(fn () => $session->set('9'));

    expect($session->current())->toBe('9');

    $session->clear();
});

it('opens a transaction for each block and sets the tenant locally', function (): void {
    expect($this->rls()->mode())->toBe(Mode::Transaction);

    $seen = $this->rls()->within(5, fn (): array => [
        'guc' => $this->guc(),
        'level' => DB::transactionLevel(),
    ]);

    expect($seen['guc'])->toBe('5')
        ->and($seen['level'])->toBe(1)
        ->and(DB::transactionLevel())->toBe(0)
        ->and($this->guc())->toBe('');
});

it('restores the outer tenant inside one transaction when nested', function (): void {
    $seen = $this->rls()->within(1, fn (): array => [
        'inner' => $this->rls()->within(2, fn (): string => $this->guc()),
        'after' => $this->guc(),
        'central' => $this->rls()->central(fn (): string => $this->guc()),
        'afterCentral' => $this->guc(),
    ]);

    expect($seen)->toBe(['inner' => '2', 'after' => '1', 'central' => '', 'afterCentral' => '1'])
        ->and($this->guc())->toBe('');
});

it('rolls the block back, and leaves no tenant behind, when the closure throws', function (): void {
    DB::statement('CREATE TEMP TABLE IF NOT EXISTS probe_rows (n int)');

    expect(fn () => $this->rls()->within(1, function (): never {
        DB::insert('INSERT INTO probe_rows VALUES (1)');

        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class);

    expect(DB::scalar('SELECT count(*) FROM probe_rows'))->toBe(0)
        ->and($this->guc())->toBe('');
});
