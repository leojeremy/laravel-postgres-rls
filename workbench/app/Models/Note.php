<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use LeoJeremy\PostgresRls\Eloquent\StampsTenantId;

/**
 * The database stamps tenant_id (DEFAULT current_tenant_id()) and the
 * policy rejects any other value. StampsTenantId only fills the attribute
 * in PHP too, so $note->tenant_id is set after create().
 */
final class Note extends Model
{
    use StampsTenantId;

    protected $guarded = [];

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }
}
