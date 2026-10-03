<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls\Tests\Fixtures;

use Stancl\Tenancy\Database\Models\Tenant;

/** stancl's tenant model on the workbench `tenants` table. */
final class StanclTenant extends Tenant
{
    public static function getCustomColumns(): array
    {
        return ['id', 'name', 'created_at', 'updated_at'];
    }
}
