<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls;

use Illuminate\Database\Connection;
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
 *
 * With a read/write split the connection holds two PDOs, two database
 * sessions, and Laravel sends SELECTs to the read one. The value is
 * written to both, or reads and writes inside one block would run as
 * different tenants. The write session is the one read back.
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
        $connection = $this->connection();
        $sql = sprintf('SELECT set_config(?, ?, %s)', $local ? 'true' : 'false');

        $connection->select($sql, [self::SETTING, $key], false);

        if ($this->hasSeparateReadSession($connection)) {
            $connection->select($sql, [self::SETTING, $key], true);
        }
    }

    public function clear(bool $local = false): void
    {
        $this->set('', $local);
    }

    /** The key in scope on this connection, or null when none is. */
    public function current(): ?string
    {
        // `true` = missing_ok: an unset variable reads as null, not an error.
        $value = $this->connection()->scalar('SELECT current_setting(?, true)', [self::SETTING], false);

        return $value === null || $value === '' ? null : (string) $value;
    }

    public function connection(): ConnectionInterface
    {
        // Resolved on every call, never cached: Octane and queue workers
        // can purge and reconnect between requests.
        return $this->db->connection($this->connection);
    }

    private function hasSeparateReadSession(ConnectionInterface $connection): bool
    {
        // Inside a transaction Laravel routes every query to the write PDO,
        // so the read session cannot be reached from here. Switching tenant
        // inside a transaction is already unsafe for other reasons (see
        // RlsContext), and transaction mode reads from the write PDO.
        return $connection instanceof Connection
            && $connection->getRawReadPdo() !== null
            && $connection->transactionLevel() === 0;
    }
}
