<?php

declare(strict_types=1);

use LeoJeremy\PostgresRls\Audit\RoleStatus;

/**
 * The role check as a plain method, tested directly. The test suite cannot
 * grant its own role BYPASSRLS (it is deliberately not allowed to), and a
 * test that needed a superuser to run would be skipped exactly where CI
 * runs, so the decision itself is unit-tested here and the real roles are
 * covered in Feature/AuditCommandTest when superuser credentials exist.
 */
it('passes a role that is neither superuser nor BYPASSRLS', function (): void {
    expect((new RoleStatus('rls_app', superuser: false, bypassRls: false))->problem())->toBeNull();
});

it('fails a SUPERUSER role', function (): void {
    expect((new RoleStatus('postgres', superuser: true, bypassRls: false))->problem())
        ->toContain('[postgres] is a SUPERUSER')
        ->toContain('NOT in effect');
});

it('fails a BYPASSRLS role', function (): void {
    expect((new RoleStatus('deployer', superuser: false, bypassRls: true))->problem())
        ->toContain('[deployer] holds BYPASSRLS');
});

it('names SUPERUSER when the role is both', function (): void {
    expect((new RoleStatus('postgres', superuser: true, bypassRls: true))->problem())
        ->toContain('is a SUPERUSER');
});
