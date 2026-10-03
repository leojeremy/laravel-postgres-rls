<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * No tenant trait: tenant_id is filled by the column's
 * DEFAULT current_tenant_id(), and the policy rejects any other value.
 */
final class Note extends Model
{
    protected $guarded = [];

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }
}
