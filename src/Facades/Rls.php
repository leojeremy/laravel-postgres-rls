<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls\Facades;

use Illuminate\Support\Facades\Facade;
use LeoJeremy\PostgresRls\RlsContext;

/**
 * @method static mixed within(int|string|\Illuminate\Database\Eloquent\Model $tenant, \Closure $fn)
 * @method static mixed central(\Closure $fn)
 * @method static int|string|null currentKey()
 * @method static void assertRunningAs(int|string|\Illuminate\Database\Eloquent\Model|null $expected, string $operation)
 * @method static \LeoJeremy\PostgresRls\Mode mode()
 *
 * @see RlsContext
 */
final class Rls extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return RlsContext::class;
    }
}
