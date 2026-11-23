<?php

namespace App\Console\Commands;

use App\Console\Concerns\UsesMaintenanceAccount;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * G6a (B-13, FR-AUD-03): gives the application account what it needs and nothing more, table by table, after
 * every migration. MariaDB cannot take one table out of a database-wide grant, so the grants are per table:
 *
 * - every table: SELECT, INSERT, UPDATE, DELETE;
 * - audit_logs: SELECT, INSERT only (the trail is written, never changed);
 * - nothing else: no CREATE/ALTER/DROP, no TRIGGER (it can neither see nor drop the triggers), no GRANT.
 *
 * Any database-wide grant the account had (Docker gives its MARIADB_USER ALL on the database) is revoked last,
 * after the table grants are in place, so a running application never loses access in between.
 * Runs on the maintenance account.
 */
class DatabaseGrants extends Command
{
    use UsesMaintenanceAccount;

    protected $signature = 'sis:db-grants {--user= : The application account (default: DB_USERNAME)} {--host=% : Its host part}';

    protected $description = 'Grant the application database account its per-table privileges (after every migration).';

    /** Tables the application may read and add to but never change. */
    public const APPEND_ONLY = ['audit_logs'];

    public function handle(): int
    {
        // the application's account is the one on the application's own connection
        $user = (string) ($this->option('user') ?: config('database.connections.'.config('database.app_connection', config('database.default')).'.username'));

        return $this->onMaintenance(function () use ($user) {
            $db = DB::connection();
            if (! in_array($db->getDriverName(), ['mariadb', 'mysql'], true)) {
                $this->info('Not MariaDB: nothing to grant.');

                return self::SUCCESS;
            }
            $host = (string) $this->option('host');
            if ($user === '' || $user === 'root' || $user === $db->getConfig('username')) {
                $this->error("Refused: '{$user}' is not a separate application account.");

                return self::FAILURE;
            }

            $schema = $db->getDatabaseName();
            $account = $db->getPdo()->quote($user).'@'.$db->getPdo()->quote($host);
            $q = fn (string $name) => '`'.str_replace('`', '``', $name).'`';
            $tables = collect($db->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"))
                ->map(fn ($row) => array_values((array) $row)[0])->all();

            foreach ($tables as $table) {
                $append = in_array($table, self::APPEND_ONLY, true);
                $db->statement('GRANT '.($append ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE, DELETE')." ON {$q($schema)}.{$q($table)} TO {$account}");
                if ($append) {
                    $this->revoke("REVOKE UPDATE, DELETE ON {$q($schema)}.{$q($table)} FROM {$account}");
                }
            }
            $this->revoke("REVOKE ALL PRIVILEGES ON {$q($schema)}.* FROM {$account}");
            $this->revoke("REVOKE GRANT OPTION ON {$q($schema)}.* FROM {$account}");

            $this->info(sprintf('%s@%s: SELECT, INSERT, UPDATE, DELETE on %d tables; %s: SELECT, INSERT only; no database-wide privileges.',
                $user, $host, count($tables) - count(array_intersect($tables, self::APPEND_ONLY)), implode(', ', self::APPEND_ONLY)));

            return self::SUCCESS;
        });
    }

    /** A revoke of something the account never had is not an error (1141, 1147). */
    private function revoke(string $sql): void
    {
        try {
            DB::statement($sql);
        } catch (QueryException $e) {
            if (! in_array($e->errorInfo[1] ?? null, [1141, 1147], true)) {
                throw $e;
            }
        }
    }
}
