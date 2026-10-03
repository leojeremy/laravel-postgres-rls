<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LeoJeremy\PostgresRls\Testing\CrossTenantProbe;
use Workbench\App\Models\Note;
use Workbench\App\Models\Tag;

uses(RefreshDatabase::class);

/**
 * The three ways row-level security looks switched on while it is off.
 */

// Trap 1 ---------------------------------------------------------------------

it('exempts the table owner when RLS is enabled but not FORCEd', function (): void {
    [$alpha, $bravo] = $this->twoTenants();

    // The suite's role owns every table it creates, like an app that runs
    // its own migrations. ENABLE alone does not apply policies to the owner.
    DB::statement('CREATE TABLE memos (id bigserial PRIMARY KEY, tenant_id bigint NOT NULL DEFAULT current_tenant_id(), body text NOT NULL)');
    DB::statement('ALTER TABLE memos ENABLE ROW LEVEL SECURITY');
    DB::statement('CREATE POLICY tenant_isolation ON memos USING (tenant_id = current_tenant_id()) WITH CHECK (tenant_id = current_tenant_id())');

    $probe = (new CrossTenantProbe(DB::connection()));

    $this->rls()->within($alpha->id, function () use ($probe, $bravo): void {
        expect($probe->insertAs('memos', $bravo->id)->accepted)->toBeTrue();

        DB::table('memos')->insert(['tenant_id' => $bravo->id, 'body' => 'planted']);
        expect(DB::table('memos')->count())->toBe(1); // Alpha reads Bravo's row
    });

    DB::statement('ALTER TABLE memos FORCE ROW LEVEL SECURITY');

    $this->rls()->within($alpha->id, function () use ($probe, $bravo): void {
        expect($probe->insertAs('memos', $bravo->id)->rejectedByRls())->toBeTrue()
            ->and(DB::table('memos')->count())->toBe(0);
    });
});

// Trap 2 ---------------------------------------------------------------------

it('lets a SUPERUSER or BYPASSRLS role skip every policy, FORCE included', function (): void {
    $super = $this->superuser();
    $super->beginTransaction();

    try {
        $alpha = $super->table('tenants')->insertGetId(['name' => 'Alpha']);
        $bravo = $super->table('tenants')->insertGetId(['name' => 'Bravo']);
        $super->table('notes')->insert([
            ['tenant_id' => $alpha, 'title' => 'alpha'],
            ['tenant_id' => $bravo, 'title' => 'bravo'],
        ]);

        $super->select("SELECT set_config('app.current_tenant_id', ?, true)", [(string) $alpha]);
        $countAs = fn (): int => (int) $super->scalar('SELECT count(*) FROM notes');

        // The superuser, scoped to Alpha, still sees both tenants' rows.
        expect($countAs())->toBe(2);

        $super->statement('CREATE ROLE rls_bypass_probe NOLOGIN NOSUPERUSER BYPASSRLS');
        $super->statement('GRANT SELECT ON notes TO rls_bypass_probe');
        $super->statement('SET LOCAL ROLE rls_bypass_probe');
        expect($countAs())->toBe(2);

        // The app role, same session variable, same table: one row.
        $super->statement('RESET ROLE');
        $super->statement('SET LOCAL ROLE '.$super->getQueryGrammar()->wrap((string) DB::scalar('SELECT current_user')));
        expect($countAs())->toBe(1);
    } finally {
        $super->rollBack();
    }
});

// Trap 3 ---------------------------------------------------------------------

it('rejects pivot rows written without a model unless tenant_id defaults to current_tenant_id()', function (): void {
    [$alpha] = $this->twoTenants();

    DB::statement('ALTER TABLE note_tag ALTER COLUMN tenant_id DROP DEFAULT');

    $error = $this->rls()->within($alpha->id, function (): ?QueryException {
        $note = Note::create(['title' => 'n']);
        $tag = Tag::create(['name' => 't']);

        try {
            // attach() inserts note_id and tag_id only: nothing stamps tenant_id.
            DB::transaction(fn () => $note->tags()->attach($tag));
        } catch (QueryException $e) {
            return $e;
        }

        return null;
    });

    // The row reaches the policy with tenant_id NULL, and NULL = <alpha> is
    // not true, so WITH CHECK rejects it. Postgres checks the policy before
    // the NOT NULL constraint, so this is 42501, not 23502.
    expect($error?->errorInfo[0])->toBe('42501');

    $this->artisan('rls:audit')->assertFailed()->expectsOutputToContain('note_tag');

    DB::statement('ALTER TABLE note_tag ALTER COLUMN tenant_id SET DEFAULT current_tenant_id()');

    $stamped = $this->rls()->within($alpha->id, function (): mixed {
        Note::first()->tags()->attach(Tag::first());

        return DB::table('note_tag')->value('tenant_id');
    });

    expect($stamped)->toBe($alpha->id);
    $this->artisan('rls:audit')->assertSuccessful();
});
