<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls\Contracts;

use LeoJeremy\PostgresRls\Audit\RoleStatus;
use LeoJeremy\PostgresRls\Audit\TableReport;

interface Auditor
{
    public function role(): RoleStatus;

    /** @param  list<string>  $ignoredTables */
    public function tables(array $ignoredTables = []): TableReport;
}
