<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls\Eloquent;

use Illuminate\Database\Eloquent\Model;
use LeoJeremy\PostgresRls\KeyType;
use LeoJeremy\PostgresRls\RlsContext;

/**
 * Fills tenant_id from the tenant in scope when a model is created.
 *
 * The column's DEFAULT current_tenant_id() already stamps the row in the
 * database, but Eloquent never reads that value back, so without this
 * `$model->tenant_id` is null after create(). With no tenant in scope it
 * leaves the attribute alone, and the policy rejects the row.
 */
trait StampsTenantId
{
    public static function bootStampsTenantId(): void
    {
        static::creating(static function (Model $model): void {
            if ($model->getAttribute('tenant_id') !== null) {
                return;
            }

            $key = app(RlsContext::class)->currentKey();

            if ($key !== null) {
                $model->setAttribute('tenant_id', app(KeyType::class) === KeyType::Bigint ? (int) $key : $key);
            }
        });
    }
}
