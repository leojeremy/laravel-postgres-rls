<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls;

use LeoJeremy\PostgresRls\Exceptions\InvalidTenantKey;

/**
 * The SQL type of the tenant key. It decides what `current_tenant_id()`
 * returns, so it must match the type of every `tenant_id` column.
 */
enum KeyType: string
{
    case Bigint = 'bigint';
    case Uuid = 'uuid';
    case Text = 'text';

    public static function fromConfig(mixed $value): self
    {
        $type = is_string($value) ? self::tryFrom(strtolower($value)) : null;

        if ($type === null) {
            throw new \InvalidArgumentException(sprintf(
                'rls.key_type must be one of [%s], got [%s].',
                implode(', ', array_column(self::cases(), 'value')),
                is_scalar($value) ? (string) $value : get_debug_type($value),
            ));
        }

        return $type;
    }

    /**
     * Validate a tenant key and return the string written to the session
     * variable. Rejecting a bad key here gives a clear error in PHP instead
     * of a cast failure inside the first policy check that reads it.
     */
    public function normalise(int|string $key): string
    {
        $value = (string) $key;

        $valid = match ($this) {
            self::Bigint => filter_var($value, FILTER_VALIDATE_INT) !== false,
            self::Uuid => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1,
            self::Text => $value !== '' && ! str_contains($value, "\0"),
        };

        if (! $valid) {
            throw InvalidTenantKey::for($key, $this);
        }

        return $this === self::Uuid ? strtolower($value) : $value;
    }
}
