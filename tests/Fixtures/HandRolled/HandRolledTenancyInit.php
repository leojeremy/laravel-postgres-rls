<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls\Tests\Fixtures\HandRolled;

/**
 * Known-bad sample: a hand-rolled tenancy()->initialize() … end() pair of
 * exactly the kind the scanner must reject.
 */
final class HandRolledTenancyInit
{
    public function run(object $tenant): void
    {
        tenancy()->initialize($tenant);

        try {
            // ... tenant-scoped work ...
        } finally {
            tenancy()->end();
        }
    }
}
