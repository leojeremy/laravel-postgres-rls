<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls\Tests\Fixtures\HandRolled;

use Illuminate\Support\Facades\DB;

/** Known-bad sample: writes the session variable directly. */
final class RawSessionWrite
{
    public function run(int $tenantId): void
    {
        DB::statement('SET app.current_tenant_id = '.$tenantId);
    }
}
