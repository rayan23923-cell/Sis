<?php

namespace Tests\Feature\Infra;

use App\Models\FinalResult;
use App\Services\DatabaseBackupService;
use App\Services\GradebookService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Sis\SisFixture;
use Tests\TestCase;
use ZipArchive;

/**
 * B-09: the locked-result triggers survive a backup and a restore into a separate database by another account
 * (the restore account is not the one that created them), and still refuse changes there. The rollback and
 * re-run of the migration is checked on MariaDB here too. Data is committed, as the dump runs in its own process.
 *
 * Needs a second database and an account limited to it: SIS_RESTORE_DB_DATABASE / _USERNAME / _PASSWORD
 * (CI creates them; locally see docs/TESTING_DATABASES.md).
 */
#[Group('mariadb')]
class LockedResultsBackupRestoreTest extends TestCase
{
    use SisFixture;

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mariadb') {
            $this->markTestSkipped('Needs MariaDB (mariadb-dump, triggers, a second account).');
        }
        // never a silent skip on MariaDB: the restore target is part of the test environment
        $this->assertNotEmpty(env('SIS_RESTORE_DB_DATABASE'), 'SIS_RESTORE_DB_DATABASE / _USERNAME / _PASSWORD must be set on MariaDB');
        Artisan::call('migrate:fresh');
        $this->buildUniversity();
        $this->dir = sys_get_temp_dir().'/sis-b09-'.uniqid();
        config(['backup.path' => $this->dir]);
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

    private function triggersIn(string $connection): array
    {
        return DB::connection($connection)->table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::connection($connection)->getDatabaseName())->where('EVENT_OBJECT_TABLE', 'final_results')
            ->orderBy('TRIGGER_NAME')->pluck('DEFINER', 'TRIGGER_NAME')->all();
    }

    public function test_a_backup_restored_by_another_account_into_another_database_keeps_the_triggers_working(): void
    {
        $this->lockOfferingA();
        $locked = FinalResult::where('status', 'locked')->count();
        $this->assertSame(4, $locked);

        $backup = app(DatabaseBackupService::class)->create('b09');
        $path = $this->dir.'/'.$backup['filename'];
        $zip = new ZipArchive;
        $zip->open($path);
        $dump = $zip->getFromName('database.sql');
        $zip->close();
        $this->assertStringContainsString('TRIGGER final_results_locked_update', $dump, 'the backup was made by mariadb-dump with the triggers');
        $this->assertStringContainsString('DEFINER=`'.env('DB_USERNAME').'`', $dump, 'the dump names the creating account');

        // restore into the second database as the second account (no SET USER privilege)
        $main = config('database.default');
        config(['database.connections.restore' => array_merge(config('database.connections.'.$main), [
            'database' => env('SIS_RESTORE_DB_DATABASE'), 'username' => env('SIS_RESTORE_DB_USERNAME'), 'password' => env('SIS_RESTORE_DB_PASSWORD'),
        ])]);
        config(['database.default' => 'restore']);
        try {
            app(DatabaseBackupService::class)->restore($path);
        } finally {
            config(['database.default' => $main]);
        }

        $restore = DB::connection('restore');
        $this->assertSame($locked, $restore->table('final_results')->where('status', 'locked')->count(), 'every locked result came back');
        $definers = $this->triggersIn('restore');
        $this->assertSame(['final_results_locked_delete', 'final_results_locked_update'], array_keys($definers));
        foreach ($definers as $definer) {
            $this->assertStringStartsWith(env('SIS_RESTORE_DB_USERNAME').'@', $definer, 'the restored triggers belong to the restoring account');
        }

        $id = $restore->table('final_results')->where('status', 'locked')->value('id');
        foreach ([fn () => $restore->table('final_results')->where('id', $id)->update(['rounded_mark' => 99]),
            fn () => $restore->table('final_results')->where('id', $id)->delete()] as $write) {
            try {
                $write();
                $this->fail('the restored database accepted a write to a locked result');
            } catch (QueryException $e) {
                $this->assertSame('45000', $e->errorInfo[0] ?? null, $e->getMessage());
            }
        }
        $this->assertSame($this->triggersIn($main) !== [], true, 'the source database keeps its own triggers');
    }

    public function test_the_migration_rolls_back_and_forward_on_mariadb_without_touching_data(): void
    {
        $this->lockOfferingA();
        $before = DB::table('final_results')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $migration = require database_path('migrations/2026_11_21_000000_protect_locked_final_results.php');

        $migration->down();
        $this->assertSame([], $this->triggersIn(config('database.default')));
        $migration->up();
        $this->assertCount(2, $this->triggersIn(config('database.default')));
        $migration->up(); // running it again is harmless
        $this->assertCount(2, $this->triggersIn(config('database.default')));

        $this->assertSame($before, DB::table('final_results')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
    }
}
