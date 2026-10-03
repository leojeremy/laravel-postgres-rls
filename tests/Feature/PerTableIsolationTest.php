<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LeoJeremy\PostgresRls\Testing\CrossTenantProbe;

uses(RefreshDatabase::class);

/**
 * Auto-discovers every table with a tenant_id column and a tenant_isolation
 * policy, and forges an INSERT carrying another tenant's id into each. A new
 * protected table is covered with no test change.
 *
 * The assertion is the point: the row must be rejected BY ROW-LEVEL
 * SECURITY (SQLSTATE 42501). "Some QueryException" is not enough, because a
 * bad filler value fails before the policy is ever evaluated.
 */
it('rejects cross-tenant inserts on every RLS-protected table, by RLS', function (): void {
    [$alpha, $bravo] = $this->twoTenants();
    $probe = new CrossTenantProbe(DB::connection());

    $tables = $probe->protectedTables(config('rls.audit.ignored_tables', []));
    expect($tables)->toBe(['note_tag', 'notes', 'tags']);

    $this->rls()->within($alpha->id, function () use ($probe, $tables, $bravo): void {
        foreach ($tables as $table) {
            $result = $probe->insertAs($table, $bravo->id);

            expect($result->rejectedByRls())->toBeTrue($result->describe());
        }
    });
});

/**
 * Regression test for the original assertion,
 * `toThrow(QueryException::class, '')`, which any SQL error satisfied.
 *
 * The table below has a BROKEN policy (WITH CHECK (true) admits every row)
 * and a NOT NULL column whose type the generic filler 'x' cannot satisfy.
 * The old assertion passes on it, because the cast error is a
 * QueryException. The probe does not: it reports SQLSTATE 22P02, and once
 * the column gets a valid filler it shows the row was ACCEPTED.
 */
it('does not mistake an unrelated error for isolation', function (): void {
    [$alpha, $bravo] = $this->twoTenants();

    DB::statement("CREATE TYPE mood AS ENUM ('calm', 'busy')");
    DB::statement('CREATE TABLE leaky (id bigserial PRIMARY KEY, tenant_id bigint NOT NULL, mood mood NOT NULL)');
    DB::statement('ALTER TABLE leaky ENABLE ROW LEVEL SECURITY');
    DB::statement('ALTER TABLE leaky FORCE ROW LEVEL SECURITY');
    DB::statement('CREATE POLICY tenant_isolation ON leaky USING (tenant_id = current_tenant_id()) WITH CHECK (true)');

    $this->rls()->within($alpha->id, function () use ($bravo): void {
        // What the old test did: a forged insert with filler 'x'. It throws,
        // so the old assertion would have reported this table as isolated.
        expect(fn () => DB::transaction(fn () => DB::insert(
            'INSERT INTO leaky (tenant_id, mood) VALUES (?, ?)',
            [$bravo->id, 'x'],
        )))->toThrow(QueryException::class);

        $probe = new CrossTenantProbe(DB::connection());

        $unproven = $probe->insertAs('leaky', $bravo->id);
        expect($unproven->rejectedByRls())->toBeFalse()
            ->and($unproven->accepted)->toBeFalse()
            ->and($unproven->sqlState)->toBe('22P02')
            ->and($unproven->describe())->toContain('isolation is unproven');

        $leak = $probe->withFiller('leaky.mood', 'calm')->insertAs('leaky', $bravo->id);
        expect($leak->accepted)->toBeTrue()
            ->and($leak->describe())->toContain('ACCEPTED a cross-tenant INSERT');
    });

    // The accepted probe row was rolled back, not left behind: Bravo, who
    // the forged row claimed to belong to, sees nothing.
    expect($this->rls()->within($bravo->id, fn () => DB::table('leaky')->count()))->toBe(0);
});

it('probes tables with boolean, json and timestamp columns', function (): void {
    [$alpha, $bravo] = $this->twoTenants();

    DB::statement('CREATE TABLE settings (id bigserial PRIMARY KEY, tenant_id bigint NOT NULL, active boolean NOT NULL, prefs jsonb NOT NULL, seen_at timestamptz NOT NULL)');
    DB::statement("SELECT enable_rls_for('settings')");

    $this->rls()->within($alpha->id, function () use ($bravo): void {
        $result = (new CrossTenantProbe(DB::connection()))->insertAs('settings', $bravo->id);

        expect($result->rejectedByRls())->toBeTrue($result->describe());
    });
});
