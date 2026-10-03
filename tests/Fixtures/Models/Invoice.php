<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

final class Invoice extends Model
{
    use StampsTenant;
}
