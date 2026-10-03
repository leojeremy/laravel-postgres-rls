<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Reads and writes the `app.current_tenant_id` session variable that
 * `current_tenant_id()` and every `tenant_isolation` policy read.
 *
 * It uses `set_config()` rather than `SET`: `SET` cannot take a bound
 * parameter, so a non-integer key would have to be interpolated into SQL.
 * `set_config(name, value, is_local)` is an ordinary function call, so the
 * key is bound like any other value, and `is_local = true` is `SET LOCAL`.
 */
final class TenantSession
{
    public const SETTING = 'app.current_tenant_id';

    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly ?string $connection = null,
    ) {}

    public function set(string $key, bool $local = false): void
    {
        $this->connection()->select(
            sprintf('SELECT set_config(?, ?, %s)', $local ? 'true' : 'false'),
            [self::SETTING, $key],
        );
    }

    public function clear(bool $local = false): void
    {
        $this->set('', $local);
    }

    /** The key in scope on this connection, or null when none is. */
    public function current(): ?string
    {
        // `true` = missing_ok: an unset variable reads as null, not an error.
        $value = $this->connection()->scalar('SELECT current_setting(?, true)', [self::SETTING]);

        return $value === null || $value === '' ? null : (string) $value;
    }

    public function connection(): ConnectionInterface
    {
        // Resolved on every call, never cached: Octane and queue workers
        // can purge and reconnect between requests.
        return $this->db->connection($this->connection);
    }
}
