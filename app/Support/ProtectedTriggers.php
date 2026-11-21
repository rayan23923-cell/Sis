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

    /** @return list<string> expected triggers that this database lacks */
    public static function missing(?Connection $db = null): array
    {
        return array_values(array_diff(array_keys(self::EXPECTED), self::present($db)));
    }
}
