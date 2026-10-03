<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls;

/**
 * How the tenant session variable is scoped.
 *
 * Session: a plain `SET`. It lives on the database connection until it is
 * changed, so it is only safe when one PHP process owns the server
 * connection for the whole request or job (direct connections, or a
 * pooler in session mode). RlsContext always resets it.
 *
 * Transaction: `SET LOCAL` inside a transaction that RlsContext opens. The
 * value dies with the transaction, so a pooler in transaction mode
 * (PgBouncer `pool_mode = transaction`) cannot hand it to another client.
 * The cost: tenant work only happens inside RlsContext::within().
 */
enum Mode: string
{
    case Session = 'session';
    case Transaction = 'transaction';

    public static function fromConfig(mixed $value): self
    {
        $mode = is_string($value) ? self::tryFrom(strtolower($value)) : null;

        if ($mode === null) {
            throw new \InvalidArgumentException(sprintf(
                'rls.mode must be "session" or "transaction", got [%s].',
                is_scalar($value) ? (string) $value : get_debug_type($value),
            ));
        }

        return $mode;
    }
}
