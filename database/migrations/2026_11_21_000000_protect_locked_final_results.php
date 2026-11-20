<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * B-09 (FR-SEC-08): the database itself refuses to change a locked result, as a second line behind the
 * FinalResult model's own guard: a direct SQL statement, a script or a bug cannot edit one either.
 *
 * - UPDATE of a locked row: refused, except setting is_current from 1 to 0 with every other column unchanged
 *   (updated_at aside). That is what a correction does to the version it supersedes (ADR-005).
 * - DELETE of a row that is not a draft: refused (the model's rule: only drafts can be removed).
 * - INSERT and every SELECT: untouched.
 *
 * No data is read or changed. down() drops the triggers and nothing else.
 */
return new class extends Migration
{
    /** Every column that must stay as it is on a locked row (all but is_current and updated_at). */
    private const FROZEN = [
        'id', 'enrollment_id', 'exam_round', 'version', 'total_mark', 'rounded_mark', 'grade_code', 'grade_name_ar',
        'grade_points', 'passed', 'outcome', 'credits_earned', 'policy_snapshot', 'input_hash', 'status',
        'supersedes_id', 'administrative_order_id', 'computed_by', 'approved_by', 'approved_at', 'locked_at', 'created_at',
    ];

    private const UPDATE_MESSAGE = 'final_results: a locked result cannot be changed; issue a correction with an administrative order';

    private const DELETE_MESSAGE = 'final_results: only draft results can be removed';

    public function up(): void
    {
        $this->drop();
        match (DB::getDriverName()) {
            'mariadb', 'mysql' => $this->mariadb(),
            'sqlite' => $this->sqlite(),
            default => throw new RuntimeException('B-09: no locked-result triggers for driver '.DB::getDriverName()),
        };
    }

    public function down(): void
    {
        $this->drop();
    }

    private function drop(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS final_results_locked_update');
        DB::unprepared('DROP TRIGGER IF EXISTS final_results_locked_delete');
    }

    private function mariadb(): void
    {
        $same = implode(' AND ', array_map(fn ($c) => "NEW.`{$c}` <=> OLD.`{$c}`", self::FROZEN));
        DB::unprepared(<<<SQL
            CREATE TRIGGER final_results_locked_update BEFORE UPDATE ON final_results FOR EACH ROW
            BEGIN
                IF OLD.status = 'locked' AND NOT (OLD.is_current = 1 AND NEW.is_current = 0 AND {$same}) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$this->quote(self::UPDATE_MESSAGE)}';
                END IF;
            END
            SQL);
        DB::unprepared(<<<SQL
            CREATE TRIGGER final_results_locked_delete BEFORE DELETE ON final_results FOR EACH ROW
            BEGIN
                IF OLD.status <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$this->quote(self::DELETE_MESSAGE)}';
                END IF;
            END
            SQL);
    }

    /** The same rules for the SQLite the tests run on. */
    private function sqlite(): void
    {
        $same = implode(' AND ', array_map(fn ($c) => "NEW.\"{$c}\" IS OLD.\"{$c}\"", self::FROZEN));
        DB::unprepared(<<<SQL
            CREATE TRIGGER final_results_locked_update BEFORE UPDATE ON final_results FOR EACH ROW
            WHEN OLD.status = 'locked' AND NOT (OLD.is_current = 1 AND NEW.is_current = 0 AND {$same})
            BEGIN SELECT RAISE(ABORT, '{$this->quote(self::UPDATE_MESSAGE)}'); END
            SQL);
        DB::unprepared(<<<SQL
            CREATE TRIGGER final_results_locked_delete BEFORE DELETE ON final_results FOR EACH ROW
            WHEN OLD.status <> 'draft'
            BEGIN SELECT RAISE(ABORT, '{$this->quote(self::DELETE_MESSAGE)}'); END
            SQL);
    }

    private function quote(string $s): string
    {
        return str_replace("'", "''", $s);
    }
};
