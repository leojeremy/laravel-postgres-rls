<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use LeoJeremy\PostgresRls\Http\ScopeToTenant;
use LeoJeremy\PostgresRls\TenantSession;
use Workbench\App\Models\Note;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Route::middleware('rls.tenant')->get('/notes', fn () => [
        'tenant' => DB::scalar("SELECT current_setting('app.current_tenant_id', true)"),
        'titles' => DB::table('notes')->pluck('title')->all(),
    ]);
    Route::middleware('rls.tenant')->get('/boom', fn () => throw new RuntimeException('boom'));
});

it('scopes the request to the tenant the resolver returns', function (): void {
    [$alpha, $bravo] = $this->twoTenants();
    $this->rls()->within($alpha, fn () => Note::create(['title' => 'alpha note']));
    $this->rls()->within($bravo, fn () => Note::create(['title' => 'bravo note']));

    ScopeToTenant::resolveTenantUsing(fn (Request $request) => $request->header('X-Tenant'));

    $this->getJson('/notes', ['X-Tenant' => (string) $alpha->id])
        ->assertOk()
        ->assertExactJson(['tenant' => (string) $alpha->id, 'titles' => ['alpha note']]);

    expect($this->guc())->toBe('');
});

it('runs a request with no tenant centrally, even on a connection left dirty', function (): void {
    [$alpha] = $this->twoTenants();
    $this->rls()->within($alpha, fn () => Note::create(['title' => 'alpha note']));

    ScopeToTenant::resolveTenantUsing(fn () => null);

    // A long-lived worker (Octane) that an earlier request left as Alpha.
    app(TenantSession::class)->set((string) $alpha->id);

    $this->getJson('/notes')->assertExactJson(['tenant' => '', 'titles' => []]);

    app(TenantSession::class)->clear();
});

it('resets the tenant when the request throws', function (): void {
    ScopeToTenant::resolveTenantUsing(fn () => 5);

    $this->get('/boom')->assertServerError();

    expect($this->guc())->toBe('');
});

it('accepts a tenant model from the resolver', function (): void {
    [$alpha] = $this->twoTenants();
    ScopeToTenant::resolveTenantUsing(fn () => $alpha);

    $this->getJson('/notes')->assertJsonPath('tenant', (string) $alpha->id);
});

it('says how to fix a missing resolver', function (): void {
    $this->withoutExceptionHandling();

    expect(fn () => $this->get('/notes'))
        ->toThrow(LogicException::class, 'ScopeToTenant::resolveTenantUsing()');
});

it('runs a streamed body outside the tenant block, as the README warns', function (): void {
    Route::middleware('rls.tenant')->get('/stream', fn () => response()->stream(function (): void {
        echo DB::scalar("SELECT current_setting('app.current_tenant_id', true)");
    }));
    ScopeToTenant::resolveTenantUsing(fn () => 5);

    expect($this->get('/stream')->streamedContent())->toBe('');
});
