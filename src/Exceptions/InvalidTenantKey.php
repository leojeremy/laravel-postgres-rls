<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls\Exceptions;

use LeoJeremy\PostgresRls\KeyType;

final class InvalidTenantKey extends \InvalidArgumentException
{
    public static function for(int|string $key, KeyType $type): self
    {
        return new self(sprintf(
            'Tenant key [%s] is not a valid %s. Check rls.key_type matches your tenant_id columns.',
            $key,
            $type->value,
        ));
    }
}
