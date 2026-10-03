<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Workbench\App\Models\Note;

uses(RefreshDatabase::class);

it('blocks cross-tenant reads at the database, with no where clause in sight', function (): void {
    [$alpha, $bravo] = $this->twoTenants();

    $this->rls()->within($alpha->id, fn () => Note::create(['title' => 'alpha only']));

    expect($this->rls()->within($bravo->id, fn () => DB::table('notes')->count()))->toBe(0)
        ->and($this->rls()->within($alpha->id, fn () => Note::pluck('title')->all()))->toBe(['alpha only']);
});

it('stamps tenant_id from the column default, so models need no trait', function (): void {
    [$alpha] = $this->twoTenants();

    $note = $this->rls()->within($alpha->id, fn () => Note::create(['title' => 'stamped']));

    expect(Note::find($note->id))->toBeNull(); // outside the tenant: invisible

    $tenantId = $this->rls()->within($alpha->id, fn () => DB::table('notes')->value('tenant_id'));
    expect($tenantId)->toBe($alpha->id);
});

it('returns no rows at all when no tenant is in scope', function (): void {
    [$alpha] = $this->twoTenants();
    $this->rls()->within($alpha->id, fn () => Note::create(['title' => 'x']));

    expect(DB::table('notes')->count())->toBe(0);
});

it('rejects a cross-tenant write with SQLSTATE 42501, not just any error', function (): void {
    [$alpha, $bravo] = $this->twoTenants();

    $error = $this->rls()->within($alpha->id, function () use ($bravo): ?QueryException {
        try {
            // A savepoint, so the failed INSERT does not abort the test's
            // own transaction.
            DB::transaction(fn () => DB::table('notes')->insert(['tenant_id' => $bravo->id, 'title' => 'forbidden']));
        } catch (QueryException $e) {
            return $e;
        }

        return null;
    });

    expect($error)->not->toBeNull()
        ->and($error->errorInfo[0])->toBe('42501')
        ->and($error->getMessage())->toContain('new row violates row-level security policy for table "notes"');
});

it('rejects moving a row to another tenant with UPDATE', function (): void {
    [$alpha, $bravo] = $this->twoTenants();
    $this->rls()->within($alpha->id, fn () => Note::create(['title' => 'mine']));

    $state = $this->rls()->within($alpha->id, function () use ($bravo): ?string {
        try {
            DB::transaction(fn () => DB::table('notes')->update(['tenant_id' => $bravo->id]));
        } catch (QueryException $e) {
            return $e->errorInfo[0];
        }

        return null;
    });

    expect($state)->toBe('42501');
});
