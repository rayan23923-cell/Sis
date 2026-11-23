<?php

namespace App\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * B-10 (FR-SEC-19): the database triggers the system relies on, in one place, for the daily check
 * (sis:check-triggers). Created by the migration 2026_11_21_000000_protect_locked_final_results (B-09).
 */
final class ProtectedTriggers
{
    /** name => [table, words its refusal message carries] */
    public const EXPECTED = [
        'final_results_locked_update' => ['final_results', 'a locked result cannot be changed'],
        'final_results_locked_delete' => ['final_results', 'only draft results can be removed'],
    ];

    /** @return list<string> the triggers on this connection's own database, by name */
    public static function present(?Connection $db = null): array
    {
        $db ??= DB::connection();
        $names = match ($db->getDriverName()) {
            'sqlite' => $db->table('sqlite_master')->where('type', 'trigger')->pluck('name'),
            default => $db->table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', $db->getDatabaseName())->pluck('TRIGGER_NAME'),
        };
        $names = $names->all();
        sort($names);

        return $names;
    }

    /** The migration that installs them (B-09). */
    public const MIGRATION = '2026_11_21_000000_protect_locked_final_results';

    /**
     * G6a: the triggers this database must have, by its migrations, whether or not this account can see them.
     * An account without the TRIGGER privilege (the application's, since B-13) sees none of them in
     * information_schema, and mariadb-dump run as it leaves them out: present() alone would then look fine.
     *
     * @return list<string>
     */
    public static function required(?Connection $db = null): array
    {
        $db ??= DB::connection();
        $ran = $db->getSchemaBuilder()->hasTable('migrations') && $db->table('migrations')->where('migration', self::MIGRATION)->exists();

        return $ran ? array_keys(self::EXPECTED) : [];
    }

    /** @return list<string> expected triggers that this database lacks */
    public static function missing(?Connection $db = null): array
    {
        return array_values(array_diff(array_keys(self::EXPECTED), self::present($db)));
    }
}
