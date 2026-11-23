<?php

use Illuminate\Database\Migrations\Migration;

/**
 * G6a (B-13): a trigger runs with its DEFINER's privileges, and its definer must keep the TRIGGER privilege. The B-09
 * triggers were created by whichever account migrated then: the application's own on staging, which G6a leaves
 * without TRIGGER, so every write to final_results would start failing. They are created again here by the account
 * that migrates from now on (the maintenance account), with the same rules. No data is read or changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        (require __DIR__.'/2026_11_21_000000_protect_locked_final_results.php')->up(); // drops, then creates
    }

    public function down(): void
    {
        // the triggers stay: B-09's own down() is what removes them
    }
};
