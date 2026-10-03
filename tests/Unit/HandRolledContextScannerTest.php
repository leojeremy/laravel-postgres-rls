<?php

declare(strict_types=1);

use LeoJeremy\PostgresRls\Testing\HandRolledContextScanner;

$fixtures = dirname(__DIR__).'/Fixtures/HandRolled';

it('flags a hand-rolled tenancy()->initialize() pair', function () use ($fixtures): void {
    expect((new HandRolledContextScanner)->matches(file_get_contents("{$fixtures}/HandRolledTenancyInit.php")))->toBeTrue();
});

it('flags a raw write to the session variable', function () use ($fixtures): void {
    expect((new HandRolledContextScanner)->matches(file_get_contents("{$fixtures}/RawSessionWrite.php")))->toBeTrue();
});

it('ignores the initialized guard and anything inside comments', function () use ($fixtures): void {
    expect((new HandRolledContextScanner)->matches(file_get_contents("{$fixtures}/GuardOnly.php")))->toBeFalse();
});

it('reports offending files and honours the allow-list', function () use ($fixtures): void {
    $scanner = new HandRolledContextScanner;

    expect($scanner->scan([$fixtures]))->toBe([
        realpath("{$fixtures}/HandRolledTenancyInit.php"),
        realpath("{$fixtures}/RawSessionWrite.php"),
    ]);

    expect($scanner->scan([$fixtures], [
        "{$fixtures}/HandRolledTenancyInit.php",
        "{$fixtures}/RawSessionWrite.php",
    ]))->toBe([]);
});

it('finds no hand-rolled context switch in this package outside the sanctioned files', function (): void {
    $src = dirname(__DIR__, 2).'/src';

    // StanclTenantContext is the adapter RlsContext drives; it is the one
    // place allowed to call tenancy()->initialize() with a variable.
    expect((new HandRolledContextScanner)->scan([$src], ["{$src}/Stancl/StanclTenantContext.php"]))->toBe([]);
});
