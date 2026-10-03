<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls\Audit;

/**
 * The connecting role's attributes that decide whether any policy applies.
 */
final readonly class RoleStatus
{
    public function __construct(
        public string $name,
        public bool $superuser,
        public bool $bypassRls,
    ) {}

    /**
     * Why policies do not apply to this role, or null when they do.
     *
     * A SUPERUSER or BYPASSRLS role skips every policy on every table:
     * Postgres does not consult them at all, FORCE included. So this check
     * outranks everything the table audit can say.
     */
    public function problem(): ?string
    {
        if (! $this->superuser && ! $this->bypassRls) {
            return null;
        }

        $why = $this->superuser ? 'is a SUPERUSER' : 'holds BYPASSRLS';

        return "The connecting role [{$this->name}] {$why}, so every row-level security policy on this database is ignored. "
            .'Tenant isolation is NOT in effect, whatever the table audit would have said.';
    }
}
