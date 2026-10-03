<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls\Testing;

final readonly class ProbeResult
{
    /** SQLSTATE insufficient_privilege, raised for a row-level security violation. */
    public const RLS_VIOLATION = '42501';

    public function __construct(
        public string $table,
        public bool $accepted,
        public ?string $sqlState = null,
        public ?string $message = null,
    ) {}

    /**
     * True only when Postgres rejected the row because of a row-level
     * security policy. Any other error (a bad filler value, NOT NULL, a
     * foreign key) means RLS was never shown to work for this table.
     */
    public function rejectedByRls(): bool
    {
        return ! $this->accepted
            && $this->sqlState === self::RLS_VIOLATION
            && str_contains((string) $this->message, 'violates row-level security policy');
    }

    public function describe(): string
    {
        if ($this->accepted) {
            return "Table {$this->table} ACCEPTED a cross-tenant INSERT.";
        }

        if ($this->rejectedByRls()) {
            return "Table {$this->table} rejected the cross-tenant INSERT by row-level security.";
        }

        return "Table {$this->table} rejected the INSERT for a reason other than RLS "
            ."(SQLSTATE {$this->sqlState}), so isolation is unproven: {$this->message} "
            .'Give the column a filler with CrossTenantProbe::withFiller().';
    }
}
