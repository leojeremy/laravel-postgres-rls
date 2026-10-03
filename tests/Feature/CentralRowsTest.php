<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LeoJeremy\PostgresRls\Exceptions\TenantSessionMismatch;
use LeoJeremy\PostgresRls\RlsSchema;
use LeoJeremy\PostgresRls\Testing\CrossTenantProbe;

uses(RefreshDatabase::class);

/**
 * Central rows: a table that holds both tenant rows and rows that belong to
 * nobody (a platform audit log). Its policy is two-way: NULL-tenant rows
 * are visible only when no tenant is set.
 */
beforeEach(function (): void {
    DB::statement('CREATE TABLE audit_entries (id bigserial PRIMARY KEY, tenant_id bigint NULL, event text NOT NULL)');
    RlsSchema::on(DB::connection())->protect('audit_entries', allowCentral: true);

    [$this->alpha, $this->bravo] = $this->twoTenants();

    DB::insert("INSERT INTO audit_entries (event) VALUES ('platform: tenant created')");
    $this->rls()->within($this->alpha, fn () => DB::insert("INSERT INTO audit_entries (event) VALUES ('alpha: login')"));
    $this->rls()->within($this->bravo, fn () => DB::insert("INSERT INTO audit_entries (event) VALUES ('bravo: login')"));
});

it('gives each tenant its own rows and the central session the NULL-tenant rows', function (): void {
    $events = fn (): array => DB::table('audit_entries')->orderBy('id')->pluck('event')->all();

    expect($events())->toBe(['platform: tenant created'])
        ->and($this->rls()->within($this->alpha, $events))->toBe(['alpha: login'])
        ->and($this->rls()->within($this->bravo, $events))->toBe(['bravo: login']);
});

it("stops a tenant from writing a central row or another tenant's row", function (): void {
    $this->rls()->within($this->alpha, function (): void {
        $probe = new CrossTenantProbe(DB::connection());

        expect($probe->insertAs('audit_entries', $this->bravo->id)->rejectedByRls())->toBeTrue();

        // An explicit NULL tenant_id is a central row, which a tenant may not write.
        $state = null;
        try {
            DB::transaction(fn () => DB::insert("INSERT INTO audit_entries (tenant_id, event) VALUES (NULL, 'forged central')"));
        } catch (QueryException $e) {
            $state = $e->errorInfo[0];
        }
        expect($state)->toBe('42501');
    });
});

/**
 * The trap this table type brings. With a tenant-only policy, "no tenant"
 * matches nothing, so an unconditional DELETE run by mistake without a
 * tenant deletes nothing. With central rows, "no tenant" matches the
 * central rows, and the same mistake deletes all of them.
 */
it('lets an unconditional DELETE with no tenant set destroy the central rows', function (): void {
    $deleted = DB::delete('DELETE FROM audit_entries');

    expect($deleted)->toBe(1)
        ->and(DB::table('audit_entries')->count())->toBe(0)
        ->and($this->rls()->within($this->alpha, fn () => DB::table('audit_entries')->count()))->toBe(1);
});

it('guards a destructive sweep with assertRunningAs()', function (): void {
    $purge = function ($tenant): int {
        $this->rls()->assertRunningAs($tenant, 'purge tenant data');

        return DB::delete('DELETE FROM audit_entries');
    };

    // No tenant set: refused, the central rows survive.
    expect(fn () => $purge($this->alpha))
        ->toThrow(TenantSessionMismatch::class, 'Refusing to purge tenant data: the database session is running as no tenant, not tenant '.$this->alpha->id);
    expect(DB::table('audit_entries')->count())->toBe(1);

    // The wrong tenant set (a stale value, say): refused too.
    $this->rls()->within($this->bravo, function () use ($purge): void {
        expect(fn () => $purge($this->alpha))->toThrow(TenantSessionMismatch::class, "running as tenant {$this->bravo->id}");
    });

    // The right tenant: exactly that tenant's rows go.
    expect($this->rls()->within($this->alpha, fn () => $purge($this->alpha)))->toBe(1)
        ->and($this->rls()->within($this->bravo, fn () => DB::table('audit_entries')->count()))->toBe(1)
        ->and(DB::table('audit_entries')->count())->toBe(1);
});

it('asserts the central session with null', function (): void {
    $this->rls()->assertRunningAs(null, 'prune the platform log');

    $this->rls()->within($this->alpha, function (): void {
        expect(fn () => $this->rls()->assertRunningAs(null, 'prune the platform log'))
            ->toThrow(TenantSessionMismatch::class, 'not no tenant (central)');
    });
});

it('is accepted by the audit as isolated', function (): void {
    $this->artisan('rls:audit')->assertSuccessful();
});
