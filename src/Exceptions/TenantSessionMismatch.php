<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls\Exceptions;

/**
 * The database session is not running as the tenant a statement was about
 * to be scoped to. Nothing about the request is wrong: the process is in a
 * state it was never supposed to reach, so this is a RuntimeException.
 */
final class TenantSessionMismatch extends \RuntimeException
{
    public static function during(string $operation, ?string $expected, ?string $actual): self
    {
        return new self(sprintf(
            'Refusing to %s: the database session is running as %s, not %s.',
            $operation,
            $actual === null ? 'no tenant' : "tenant {$actual}",
            $expected === null ? 'no tenant (central)' : "tenant {$expected}",
        ));
    }
}
