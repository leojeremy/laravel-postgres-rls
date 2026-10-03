<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/** No tenant trait: its table must rely on DEFAULT current_tenant_id(). */
final class Receipt extends Model {}
