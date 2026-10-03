<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls\Http;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use LeoJeremy\PostgresRls\Contracts\TenantResolver;

final class ClosureTenantResolver implements TenantResolver
{
    /** @param  Closure(Request): (int|string|Model|null)  $resolver */
    public function __construct(private readonly Closure $resolver) {}

    public function resolve(Request $request): int|string|Model|null
    {
        return ($this->resolver)($request);
    }
}
