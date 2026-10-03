<?php

declare(strict_types=1);

use LeoJeremy\PostgresRls\Contracts\Auditor;

/**
 * Guards the premise of every other feature test. If the suite connected as
 * a SUPERUSER or BYPASSRLS role, every isolation test would pass for
 * nothing, because no policy would ever be consulted.
 */
it('runs as a role that row-level security applies to', function (): void {
    $role = app(Auditor::class)->role();

    expect($role->superuser)->toBeFalse("The suite connects as [{$role->name}], a SUPERUSER. Use a NOSUPERUSER NOBYPASSRLS role (see docker/postgres/init.sql).")
        ->and($role->bypassRls)->toBeFalse("The suite connects as [{$role->name}], which holds BYPASSRLS.");
});
