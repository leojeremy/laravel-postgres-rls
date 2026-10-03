<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** Works out which tenant a request belongs to, for the ScopeToTenant middleware. */
interface TenantResolver
{
    /** The tenant (key or model), or null for a central request. */
    public function resolve(Request $request): int|string|Model|null;
}
