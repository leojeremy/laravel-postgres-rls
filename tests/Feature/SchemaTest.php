<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LeoJeremy\PostgresRls\Exceptions\InvalidTenantKey;
use LeoJeremy\PostgresRls\KeyType;
use LeoJeremy\PostgresRls\RlsContext;
use LeoJeremy\PostgresRls\RlsSchema;
use LeoJeremy\PostgresRls\Testing\CrossTenantProbe;

uses(RefreshDatabase::class);

function policiesOn(string $table): array
{
    return DB::select('SELECT policyname FROM pg_policies WHERE tablename = ?', [$table]);
}

it('makes enable_rls_for() safe to call twice', function (): void {
    DB::statement('CREATE TABLE widgets (id bigserial PRIMARY KEY, tenant_id bigint NOT NULL)');

    DB::select("SELECT enable_rls_for('widgets')");
    // The original function ran CREATE POLICY unconditionally, so this
    // second call failed with "policy already exists".
    DB::select("SELECT enable_rls_for('widgets')");

    $flags = DB::selectOne("SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = 'widgets'");

    expect(policiesOn('widgets'))->toHaveCount(1)
        ->and($flags->relrowsecurity)->toBeTrue()
        ->and($flags->relforcerowsecurity)->toBeTrue();
});

it('replaces a drifted policy when re-run', function (): void {
    DB::statement('CREATE TABLE widgets (id bigserial PRIMARY KEY, tenant_id bigint NOT NULL)');
    DB::statement('ALTER TABLE widgets ENABLE ROW LEVEL SECURITY');
    DB::statement('CREATE POLICY tenant_isolation ON widgets USING (true) WITH CHECK (true)');

    RlsSchema::on(DB::connection())->enable('widgets');

    $policy = DB::selectOne("SELECT qual, with_check FROM pg_policies WHERE tablename = 'widgets'");

    expect($policy->qual)->toContain('current_tenant_id()')
        ->and($policy->with_check)->toContain('current_tenant_id()');
});

it('fails fast on a table that does not exist', function (): void {
    expect(fn () => RlsSchema::on(DB::connection())->enable('no_such_table'))
        ->toThrow(QueryException::class, 'does not exist');
});

it('re-installs the functions without error', function (): void {
    RlsSchema::on(DB::connection())->install(KeyType::Bigint);

    expect(DB::scalar("SELECT pg_get_function_result('current_tenant_id'::regproc)"))->toBe('bigint');
});

/**
 * UUID tenant keys. current_tenant_id() is one function per schema, and
 * this database already has the bigint one with policies depending on it,
 * so the uuid variant is installed into a scratch schema placed first on
 * the search_path. All of it is rolled back with the test's transaction.
 */
it('supports uuid tenant keys end to end', function (): void {
    DB::statement('CREATE SCHEMA uuid_app');
    DB::statement('SET LOCAL search_path TO uuid_app, public');

    $rls = RlsSchema::on(DB::connection());
    $rls->install(KeyType::Uuid);

    DB::statement('CREATE TABLE uuid_app.docs (id bigserial PRIMARY KEY, tenant_id uuid NOT NULL, body text NOT NULL)');
    $rls->protect('uuid_app.docs');

    config(['rls.key_type' => 'uuid']);
    app()->forgetInstance(RlsContext::class);

    $alpha = '0b7e7a52-7a0b-4c55-9a5d-3f2e1d0c9b8a';
    $bravo = 'f4c1d2e3-0000-4aaa-8bbb-123456789abc';

    $this->rls()->within($alpha, fn () => DB::insert('INSERT INTO uuid_app.docs (body) VALUES (?)', ['alpha doc']));

    expect(DB::scalar("SELECT pg_get_function_result('uuid_app.current_tenant_id'::regproc)"))->toBe('uuid')
        ->and($this->rls()->within($alpha, fn () => DB::table('uuid_app.docs')->pluck('tenant_id')->all()))->toBe([$alpha])
        ->and($this->rls()->within($bravo, fn () => DB::table('uuid_app.docs')->count()))->toBe(0);

    $this->rls()->within($alpha, function () use ($bravo): void {
        $result = (new CrossTenantProbe(DB::connection(), 'uuid_app'))->insertAs('docs', $bravo);

        expect($result->rejectedByRls())->toBeTrue($result->describe());
    });
});

it('rejects a key of the wrong type before it reaches the database', function (): void {
    expect(fn () => $this->rls()->within('not-a-number', fn () => null))
        ->toThrow(InvalidTenantKey::class);

    expect($this->guc())->toBe('');
});
