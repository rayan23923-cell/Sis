<?php

namespace Tests\Feature\Infra;

use App\Models\FinalResult;
use App\Notifications\Notice;
use App\Services\DatabaseBackupService;
use App\Services\GradebookService;
use App\Support\ProtectedTriggers;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Sis\SisFixture;
use Tests\TestCase;
use ZipArchive;

/**
 * B-10 (FR-BC-07, FR-SEC-19) on MariaDB: every backup carries the triggers whichever way it was made, or fails;
 * a restore checks they came back; the daily check sees a missing one. Data is committed (the dump runs in its own
 * process, and DROP TRIGGER commits), so each test starts from migrate:fresh.
 */
#[Group('mariadb')]
class BackupTriggersTest extends TestCase
{
    use SisFixture;

    private string $dir = '';

    private const NOWHERE = ['/nonexistent/sis-b10-tool'];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mariadb') {
            $this->markTestSkipped('Needs MariaDB (mariadb-dump, triggers, a second account).');
        }
        $this->assertNotEmpty(env('SIS_RESTORE_DB_DATABASE'), 'SIS_RESTORE_DB_DATABASE / _USERNAME / _PASSWORD must be set on MariaDB');
        Artisan::call('migrate:fresh');
        $this->buildUniversity();
        $this->dir = sys_get_temp_dir().'/sis-b10-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        config(['backup.path' => $this->dir, 'backup.keep' => 0]);
        config(['database.connections.restore' => array_merge(config('database.connections.'.config('database.default')), [
            'database' => env('SIS_RESTORE_DB_DATABASE'), 'username' => env('SIS_RESTORE_DB_USERNAME'), 'password' => env('SIS_RESTORE_DB_PASSWORD'),
        ])]);
        $this->lockOfferingA();
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            File::deleteDirectory($this->dir);
        }
        DB::purge('restore');
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    private function lockOfferingA(): void
    {
        $g = app(GradebookService::class);
        $o = $this->offeringA;
        $g->saveMarks($o, $o->teacher, $this->marksFor($o, [55, 20, 14, 17]));
        $g->compute($o->fresh(), $o->teacher);
        $g->submit($o->fresh(), $o->teacher);
        $g->approve($o->fresh(), $this->committeeA);
        $g->lock($o->fresh(), $this->committeeA);
    }

    private function backup(): string
    {
        return $this->dir.'/'.app(DatabaseBackupService::class)->create('b10')['filename'];
    }

    private function dumpOf(string $zip): string
    {
        $z = new ZipArchive;
        $z->open($zip);
        $sql = $z->getFromName('database.sql');
        $z->close();

        return $sql;
    }

    private function rewriteDump(string $zip, callable $change): void
    {
        $z = new ZipArchive;
        $z->open($zip);
        $z->addFromString('database.sql', $change($z->getFromName('database.sql')));
        $z->close();
    }

    /** Restores as the second account into the second database (no SET USER, not the trigger's creator). */
    private function restoreElsewhere(string $zip): void
    {
        $main = config('database.default');
        config(['database.default' => 'restore']);
        try {
            app(DatabaseBackupService::class)->restore($zip);
        } finally {
            config(['database.default' => $main]);
        }
    }

    private function assertRestoredAndEnforced(): void
    {
        $db = DB::connection('restore');
        $this->assertSame(4, $db->table('final_results')->where('status', 'locked')->count(), 'every locked result came back');
        $this->assertSame(array_keys(ProtectedTriggers::EXPECTED), array_values(array_intersect(array_keys(ProtectedTriggers::EXPECTED), ProtectedTriggers::present($db))));
        $id = $db->table('final_results')->where('status', 'locked')->value('id');
        foreach ([fn () => $db->table('final_results')->where('id', $id)->update(['rounded_mark' => 99]),
            fn () => $db->table('final_results')->where('id', $id)->delete()] as $write) {
            try {
                $write();
                $this->fail('the restored database accepted a write to a locked result');
            } catch (QueryException $e) {
                $this->assertSame('45000', $e->errorInfo[0] ?? null, $e->getMessage());
            }
        }
    }

    // T1 + T2
    public function test_the_php_fallback_writes_the_triggers_and_says_so_and_its_backup_restores_them(): void
    {
        Log::spy();
        config(['backup.tools.dump' => self::NOWHERE]);
        $zip = $this->backup();
        $sql = $this->dumpOf($zip);

        $this->assertStringStartsWith('-- SIS backup: php', $sql);
        $this->assertMatchesRegularExpression('/TRIGGER\s+`?final_results_locked_update`?\s+BEFORE UPDATE/i', $sql);
        $this->assertMatchesRegularExpression('/TRIGGER\s+`?final_results_locked_delete`?\s+BEFORE DELETE/i', $sql);
        $this->assertStringNotContainsString('DEFINER', $sql, 'the restoring account owns the triggers (D-G4-4)');
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'PHP dump is used'))->once();

        $this->restoreElsewhere($zip);
        $this->assertRestoredAndEnforced();
    }

    // T2, without the mariadb client: the PHP importer reads the DELIMITER blocks
    public function test_a_php_fallback_backup_restores_through_pdo_too(): void
    {
        config(['backup.tools.dump' => self::NOWHERE, 'backup.tools.client' => self::NOWHERE]);
        $this->restoreElsewhere($this->backup());
        $this->assertRestoredAndEnforced();
    }

    // T3
    public function test_a_mariadb_dump_backup_restores_through_pdo_with_its_triggers(): void
    {
        $zip = $this->backup();
        $this->assertStringContainsString('DELIMITER ;;', $this->dumpOf($zip), 'made by mariadb-dump');

        config(['backup.tools.client' => self::NOWHERE]);
        $this->restoreElsewhere($zip);
        $this->assertRestoredAndEnforced();
    }

    // T4
    public function test_a_dump_that_leaves_out_a_trigger_fails_and_is_not_kept(): void
    {
        $tool = $this->dir.'/dump-without-triggers.sh';
        file_put_contents($tool, "#!/bin/sh\nexec mariadb-dump \"\$@\" --skip-triggers\n"); // last, so it wins over --triggers
        chmod($tool, 0755);
        config(['backup.tools.dump' => [$tool]]);

        try {
            $this->backup();
            $this->fail('a backup without the triggers was accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('final_results_locked_delete, final_results_locked_update', $e->getMessage());
        }
        $this->assertSame([], glob($this->dir.'/*.{zip,sql}', GLOB_BRACE), 'no file is left that looks like a backup');
        $this->artisan('backup:run', ['--force' => true])->assertExitCode(1);
    }

    // T5
    public function test_a_restore_that_does_not_bring_a_trigger_back_fails(): void
    {
        $zip = $this->backup();
        // a version comment no server runs: the import passes over it in silence
        $this->rewriteDump($zip, function ($sql) {
            $out = preg_replace('/^\/\*!50003 CREATE\*\/ (\/\*!50017 [^\n]*?\*\/ )?\/\*!50003 (TRIGGER `?final_results_locked_delete)/m', '/*!99999 CREATE*/ $1/*!99999 $2', $sql, 1, $n);
            $this->assertSame(1, $n, 'the delete trigger was found in the dump');

            return $out;
        });

        try {
            $this->restoreElsewhere($zip);
            $this->fail('a restore without a trigger was accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('these triggers are missing: final_results_locked_delete', $e->getMessage());
        }
    }

    // T6
    public function test_a_backup_from_before_b09_restores_and_migrate_puts_the_triggers_back(): void
    {
        $migration = '2026_11_21_000000_protect_locked_final_results';
        (require database_path("migrations/{$migration}.php"))->down();
        DB::table('migrations')->where('migration', $migration)->delete();
        $zip = $this->backup();
        $this->assertDoesNotMatchRegularExpression('/TRIGGER\s+`?final_results_locked/i', $this->dumpOf($zip));

        $this->restoreElsewhere($zip);
        $this->assertSame([], ProtectedTriggers::present(DB::connection('restore')));
        Artisan::call('migrate', ['--database' => 'restore', '--force' => true]);
        $this->assertRestoredAndEnforced();
    }

    // T7 on MariaDB (the SQLite half is in CheckTriggersTest)
    public function test_the_daily_check_finds_a_removed_trigger_and_tells_the_admins(): void
    {
        Notification::fake();
        $before = DB::table('final_results')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $this->artisan('sis:check-triggers')->assertExitCode(0);
        $this->assertSame($before, DB::table('final_results')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), 'trying the triggers changed nothing');

        DB::unprepared('DROP TRIGGER final_results_locked_update');
        Log::spy();
        $this->artisan('sis:check-triggers')->expectsOutputToContain('final_results_locked_update: missing')->assertExitCode(1);
        Log::shouldHaveReceived('critical')->once();
        Notification::assertSentTo($this->admin, Notice::class);
    }

    /**
     * T8. The PHP dump only: merging a mariadb-dump backup on MariaDB already failed before G5, with or without
     * triggers (G5-F1: the dump's first SET is glued to its header comment and skipped, so the last SET reads NULL).
     */
    public function test_merge_still_adds_only_the_missing_rows_from_a_backup_with_triggers(): void
    {
        foreach (['php' => self::NOWHERE] as $label => $tools) {
            config(['backup.tools.dump' => $tools]);
            $zip = $this->backup();
            $restore = DB::connection('restore');
            $restore->getSchemaBuilder()->dropAllTables();

            $main = config('database.default');
            config(['database.default' => 'restore']);
            try {
                $result = app(DatabaseBackupService::class)->merge($zip, ['final_results']);
            } finally {
                config(['database.default' => $main]);
            }
            $this->assertSame([], $result['errors'], $label);
            $this->assertSame(FinalResult::count(), $result['added']['final_results'] ?? 0, $label);
            $this->assertSame(FinalResult::count(), $restore->table('final_results')->count(), $label);
        }
    }
}
