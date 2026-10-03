<?php

declare(strict_types=1);

use LeoJeremy\PostgresRls\Exceptions\InvalidTenantKey;
use LeoJeremy\PostgresRls\KeyType;
use LeoJeremy\PostgresRls\Mode;

it('accepts integer keys for bigint and rejects anything else', function (): void {
    expect(KeyType::Bigint->normalise(42))->toBe('42')
        ->and(KeyType::Bigint->normalise('9223372036854775807'))->toBe('9223372036854775807');

    foreach (['', 'abc', '1; DROP TABLE notes', '1.5', '9223372036854775808'] as $bad) {
        expect(fn () => KeyType::Bigint->normalise($bad))->toThrow(InvalidTenantKey::class);
    }
});

it('accepts uuids, lower-cased, and rejects malformed ones', function (): void {
    expect(KeyType::Uuid->normalise('0B7E7A52-7A0B-4C55-9A5D-3F2E1D0C9B8A'))
        ->toBe('0b7e7a52-7a0b-4c55-9a5d-3f2e1d0c9b8a');

    expect(fn () => KeyType::Uuid->normalise('not-a-uuid'))->toThrow(InvalidTenantKey::class)
        ->and(fn () => KeyType::Uuid->normalise(7))->toThrow(InvalidTenantKey::class);
});

it('accepts any non-empty text key, because an empty one means "no tenant"', function (): void {
    expect(KeyType::Text->normalise("o'brien"))->toBe("o'brien");

    expect(fn () => KeyType::Text->normalise(''))->toThrow(InvalidTenantKey::class);
});

it('validates config values', function (): void {
    expect(KeyType::fromConfig('UUID'))->toBe(KeyType::Uuid)
        ->and(Mode::fromConfig('transaction'))->toBe(Mode::Transaction);

    expect(fn () => KeyType::fromConfig('int'))->toThrow(InvalidArgumentException::class, 'rls.key_type')
        ->and(fn () => Mode::fromConfig('pooled'))->toThrow(InvalidArgumentException::class, 'rls.mode');
});
