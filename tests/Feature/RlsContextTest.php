<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * RlsContext's reason to exist: the session variable must never survive
 * past the closure on a reused connection, or a later job or request runs
 * as a stale tenant. These pin that on the happy path, the throw path and
 * when nested.
 */
it('runs the closure inside the tenant context', function (): void {
    expect($this->rls()->within(7, fn (): string => $this->guc()))->toBe('7');
});

it('clears the session variable after the closure returns', function (): void {
    expect($this->guc())->toBe('');

    $this->rls()->within(7, fn (): null => null);

    expect($this->guc())->toBe('')
        ->and($this->rls()->currentKey())->toBeNull();
});

it('clears the session variable even when the closure throws', function (): void {
    expect(fn () => $this->rls()->within(7, function (): never {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class, 'boom');

    expect($this->guc())->toBe('');
});

it('restores the outer tenant when nested', function (): void {
    $seen = $this->rls()->within(1, fn (): array => [
        'outer' => $this->guc(),
        'inner' => $this->rls()->within(2, fn (): string => $this->guc()),
        'after' => $this->guc(),
    ]);

    expect($seen)->toBe(['outer' => '1', 'inner' => '2', 'after' => '1'])
        ->and($this->guc())->toBe('');
});

it('restores the outer tenant when the nested closure throws', function (): void {
    $after = $this->rls()->within(1, function (): string {
        try {
            $this->rls()->within(2, fn (): never => throw new RuntimeException('inner'));
        } catch (RuntimeException) {
        }

        return $this->guc();
    });

    expect($after)->toBe('1');
});

it('clears the session variable for the duration of a central block', function (): void {
    $seen = $this->rls()->within(3, fn (): array => [
        'central' => $this->rls()->central(fn (): string => $this->guc()),
        'after' => $this->guc(),
    ]);

    expect($seen)->toBe(['central' => '', 'after' => '3']);
});

it('restores the tenant even when the central closure throws', function (): void {
    $after = $this->rls()->within(3, function (): string {
        try {
            $this->rls()->central(fn (): never => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
        }

        return $this->guc();
    });

    expect($after)->toBe('3');
});

it('passes straight through when no tenant was ever set', function (): void {
    expect($this->rls()->central(fn (): string => $this->guc().'|ran'))->toBe('|ran')
        ->and($this->guc())->toBe('');
});

it('nests a tenant block inside a central block and unwinds both', function (): void {
    $seen = $this->rls()->within(1, fn (): array => $this->rls()->central(fn (): array => [
        'central' => $this->guc(),
        'inner' => $this->rls()->within(2, fn (): string => $this->guc()),
        'afterInner' => $this->guc(),
    ]) + ['afterCentral' => $this->guc()]);

    expect($seen)->toBe(['central' => '', 'inner' => '2', 'afterInner' => '', 'afterCentral' => '1']);
});

it('nests a central block inside a central block', function (): void {
    $seen = $this->rls()->within(4, fn (): array => [
        'inner' => $this->rls()->central(fn (): string => $this->rls()->central(fn (): string => $this->guc())),
        'after' => $this->guc(),
    ]);

    expect($seen)->toBe(['inner' => '', 'after' => '4']);
});
