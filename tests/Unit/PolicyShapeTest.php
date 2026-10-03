<?php

declare(strict_types=1);

use LeoJeremy\PostgresRls\Audit\PolicyShape;

it('recognises the tenant rule as Postgres deparses it', function (string $expression): void {
    expect(PolicyShape::isolates($expression))->toBeTrue();
})->with([
    'tenant only' => '(tenant_id = current_tenant_id())',
    'reversed' => '(current_tenant_id() = tenant_id)',
    'with a cast' => '((tenant_id)::text = current_tenant_id())',
    'with central rows' => '(((tenant_id IS NULL) AND (current_tenant_id() IS NULL)) OR (tenant_id = current_tenant_id()))',
]);

it('rejects anything else', function (?string $expression): void {
    expect(PolicyShape::isolates($expression))->toBeFalse();
})->with([
    'missing' => null,
    'allow all' => 'true',
    'wrong column' => '(owner_id = current_tenant_id())',
    'widened' => '((tenant_id = current_tenant_id()) OR true)',
    'central only' => '((tenant_id IS NULL) AND (current_tenant_id() IS NULL))',
    'any tenant' => '(tenant_id IS NOT NULL)',
]);
