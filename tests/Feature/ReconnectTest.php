<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * No RefreshDatabase: reconnecting would drop its wrapping transaction.
 */
it('fails closed when the connection is replaced inside a block', function (): void {
    $seen = $this->rls()->within(5, function (): array {
        $before = $this->guc();
        DB::reconnect();

        return [$before, $this->guc()];
    });

    // A new session starts with no tenant, so every policy matches nothing:
    // no rows, rather than another tenant's rows.
    expect($seen)->toBe(['5', ''])
        ->and($this->guc())->toBe('');
});
