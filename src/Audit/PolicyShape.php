<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls\Audit;

/**
 * Recognises the policy expressions enable_rls_for() writes, as Postgres
 * deparses them in pg_policies, so a policy with the right name but a
 * hand-edited rule (USING (true), a different column) is not taken on
 * trust.
 */
final class PolicyShape
{
    private const TENANT_ONLY = 'tenant_id=current_tenant_id';

    private const WITH_CENTRAL = 'tenant_idisnullandcurrent_tenant_idisnullortenant_id=current_tenant_id';

    public static function isolates(?string $expression): bool
    {
        if ($expression === null) {
            return false;
        }

        return in_array(self::normalise($expression), [
            self::TENANT_ONLY,
            self::WITH_CENTRAL,
            'current_tenant_id=tenant_id',
            'tenant_idisnullandcurrent_tenant_idisnullorcurrent_tenant_id=tenant_id',
        ], true);
    }

    public static function normalise(string $expression): string
    {
        // Drop casts Postgres adds for compatible types, e.g. (tenant_id)::text.
        $expression = (string) preg_replace('/::[a-z_]+(?: varying| precision| (?:with|without) time zone)?/i', '', $expression);

        return strtolower((string) preg_replace('/[\s()"]+/', '', $expression));
    }
}
