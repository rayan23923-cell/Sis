<?php

namespace Tests\Feature\Infra;

use App\Models\AdministrativeOrder;
use App\Models\Enrollment;
use App\Models\FinalResult;
use App\Models\User;
use App\Services\EnrollmentService;
use App\Support\ProtectedTriggers;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Sis\SisFixture;
use Tests\TestCase;

/**
 * G6a (B-13, FR-AUD-03) on MariaDB: the application runs on its own account (SIS_APP_DB_USERNAME), which
 * sis:db-grants gives per-table DML only, audit_logs append-only, and no DDL or TRIGGER. The test's own account
 * plays the maintenance account. Data is committed (grants and DDL), so each test starts from migrate:fresh.
 */
#[Group('mariadb')]
class DatabaseAccountsTest extends TestCase
{
    use SisFixture;

    private string $main;

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mariadb') {
            $this->markTestSkipped('Needs MariaDB (accounts and grants).');
        }
        $this->assertNotEmpty(env('SIS_APP_DB_USERNAME'), 'SIS_APP_DB_USERNAME / _PASSWORD must be set on MariaDB');
        $this->main = config('database.default');
        Artisan::call('migrate:fresh');
        $this->buildUniversity();
        config(['database.connections.app' => array_merge(config("database.connections.{$this->main}"), [
            'username' => env('SIS_APP_DB_USERNAME'), 'password' => env('SIS_APP_DB_PASSWORD'),
        ])]);
        $this->artisan('sis:db-grants', ['--user' => env('SIS_APP_DB_USERNAME')])->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->main ?? 'mariadb');
        config(['database.connections.maintenance' => null, 'database.app_connection' => null]);
        DB::purge('app');
        DB::purge('maintenance');
        if ($this->dir !== '') {
            File::deleteDirectory($this->dir);
        }
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    private function asApp(): void
    {
        DB::purge('app');
        DB::setDefaultConnection('app');
        $this->assertSame(env('SIS_APP_DB_USERNAME').'@%', DB::selectOne('select current_user() as u')->u);
    }

    private function assertRefused(callable $sql): void
    {
        try {
            $sql();
            $this->fail('the application account was allowed');
        } catch (QueryException $e) {
            $this->assertContains($e->errorInfo[1] ?? null, [1142, 1044, 1227, 1143], $e->getMessage());
        }
    }

    // T1
    public function test_the_application_works_on_its_own_account(): void
    {
        $audits = DB::table('audit_logs')->count();
        $this->asApp();
        $teacher = User::find($this->teacherA->id);
        $committee = User::find($this->committeeA->id);
        $o = $this->offeringA->id;

        $this->actingAs($teacher)->post(route('offerings.marks', $o), ['rows' => $this->marksFor($this->offeringA, [55, 20, 14, 17]), 'compute' => true])->assertSessionHas('success');
        $this->actingAs($teacher)->post(route('offerings.submit', $o))->assertSessionHas('success');
        $this->actingAs($committee)->post(route('offerings.decide', $o), ['action' => 'approve'])->assertSessionHas('success');
        $this->actingAs($committee)->post(route('offerings.decide', $o), ['action' => 'lock'])->assertSessionHas('success');

        $old = FinalResult::orderBy('enrollment_id')->skip(2)->first();
        $final = $this->offeringA->components()->where('code', 'final')->value('id');
        $order = AdministrativeOrder::create(['number' => '77', 'issued_on' => now()->toDateString(), 'type' => 'result_correction', 'title' => 'تصحيح', 'issued_by_authority' => 'العمادة']);
        $this->actingAs($committee)->post(route('offerings.correct', [$o, $old->id]), [
            'order_id' => $order->id, 'reason' => 'خطأ في الجمع', 'marks' => [$final => ['mark' => 26]],
        ])->assertSessionHas('success');
        $this->assertFalse($old->fresh()->is_current);

        $enrollment = Enrollment::where('course_offering_id', $this->offeringB->id)->firstOrFail();
        app(EnrollmentService::class)->withdraw($enrollment, User::find($this->admin->id), 'انسحاب');
        $this->assertSame('withdrawn', $enrollment->fresh()->status);

        $this->assertGreaterThan($audits, DB::table('audit_logs')->count(), 'the audit trail is written by the application account');
    }

    // T2 + T3
    public function test_the_application_account_cannot_change_the_schema_the_triggers_or_the_audit_trail(): void
    {
        $this->asApp();
        $this->assertRefused(fn () => DB::statement('DROP TABLE users'));
        $this->assertRefused(fn () => DB::statement('ALTER TABLE final_results ADD COLUMN x INT'));
        $this->assertRefused(fn () => DB::statement('CREATE TABLE g6a_probe (id INT)'));
        $this->assertRefused(fn () => DB::unprepared('DROP TRIGGER final_results_locked_update'));
        $this->assertRefused(fn () => DB::unprepared('CREATE TRIGGER g6a_probe BEFORE INSERT ON users FOR EACH ROW SET @x = 1'));
        $this->assertSame([], ProtectedTriggers::present(), 'it cannot even see them');

        $id = DB::table('audit_logs')->insertGetId(['action' => 'probe', 'created_at' => now()]);
        $this->assertRefused(fn () => DB::table('audit_logs')->where('id', $id)->update(['action' => 'changed']));
        $this->assertRefused(fn () => DB::table('audit_logs')->where('id', $id)->delete());

        DB::setDefaultConnection($this->main);
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertSame(array_keys(ProtectedTriggers::EXPECTED), array_values(array_intersect(array_keys(ProtectedTriggers::EXPECTED), ProtectedTriggers::present())));
        $this->assertSame('probe', DB::table('audit_logs')->where('id', $id)->value('action'));
    }

    // T4
    public function test_a_table_a_later_migration_adds_is_granted_by_the_next_run(): void
    {
        // MariaDB keeps a table's grants after the table is dropped, so an earlier run's must go first
        try {
            DB::statement('REVOKE ALL PRIVILEGES ON `'.DB::getDatabaseName()."`.`g6a_probe` FROM '".env('SIS_APP_DB_USERNAME')."'@'%'");
        } catch (QueryException) {
        }
        Schema::create('g6a_probe', fn ($t) => $t->id());
        $this->asApp();
        $this->assertRefused(fn () => DB::table('g6a_probe')->insert(['id' => 1]));

        DB::setDefaultConnection($this->main);
        $this->artisan('sis:db-grants', ['--user' => env('SIS_APP_DB_USERNAME')])->assertExitCode(0);
        $this->asApp();
        DB::table('g6a_probe')->insert(['id' => 1]);
        DB::table('g6a_probe')->where('id', 1)->update(['id' => 2]);
        $this->assertSame(2, DB::table('g6a_probe')->value('id'));
    }

    public function test_it_refuses_to_narrow_the_account_it_runs_on_or_root(): void
    {
        $this->artisan('sis:db-grants', ['--user' => config("database.connections.{$this->main}.username")])->assertExitCode(1);
        $this->artisan('sis:db-grants', ['--user' => 'root'])->assertExitCode(1);
    }

    // T5
    public function test_backups_and_the_trigger_check_run_on_the_maintenance_account_and_refuse_without_it(): void
    {
        $this->dir = sys_get_temp_dir().'/sis-g6a-'.uniqid();
        config(['backup.path' => $this->dir]);
        $this->asApp();

        // without the maintenance account: loud, never a backup that silently lacks the triggers
        $this->artisan('backup:run', ['--force' => true])->expectsOutputToContain('cannot see the triggers')->assertExitCode(1);
        $this->assertSame([], glob($this->dir.'/*.zip'));
        $this->artisan('sis:check-triggers')->expectsOutputToContain('final_results_locked_update: missing')->assertExitCode(1);

        // with it, as the panel and the scheduler run them
        config(['database.app_connection' => 'app', 'database.connections.maintenance' => config("database.connections.{$this->main}")]);
        $this->artisan('backup:run', ['--force' => true])->assertExitCode(0);
        $zip = glob($this->dir.'/*.zip')[0];
        $z = new \ZipArchive;
        $z->open($zip);
        $this->assertMatchesRegularExpression('/TRIGGER\s+`?final_results_locked_update`?\s+BEFORE/', $z->getFromName('database.sql'));
        $z->close();
        $this->artisan('sis:check-triggers')->assertExitCode(0);
        $this->assertSame('app', DB::getDefaultConnection(), 'the application connection is back afterwards');
    }

    // the staging case: triggers made by the application account stop every write once it loses TRIGGER
    public function test_triggers_made_by_the_application_account_are_made_again_by_the_maintenance_account(): void
    {
        $user = env('SIS_APP_DB_USERNAME');
        // as Docker's entrypoint gives it: on the escaped name, a separate grant entry from the plain one
        DB::statement('GRANT ALL PRIVILEGES ON `'.addcslashes(DB::getDatabaseName(), '_%')."`.* TO '{$user}'@'%'");
        DB::setDefaultConnection('app');
        DB::purge('app');
        (require database_path('migrations/'.ProtectedTriggers::MIGRATION.'.php'))->up(); // definer: the app account
        DB::setDefaultConnection($this->main);
        $this->artisan('sis:db-grants', ['--user' => $user])->assertExitCode(0);

        $this->asApp();
        $this->assertRefused(fn () => DB::statement('CREATE TABLE g6a_probe (id INT)'));
        $draft = $this->draftResultId();
        try {
            DB::table('final_results')->where('id', $draft)->update(['updated_at' => now()]);
            $this->fail('expected the orphaned definer to stop the write');
        } catch (QueryException $e) {
            $this->assertStringContainsString('TRIGGER', $e->getMessage());
        }

        DB::setDefaultConnection($this->main);
        (require database_path('migrations/2026_11_23_000000_recreate_locked_result_triggers_on_maintenance_account.php'))->up();
        $this->asApp();
        $this->assertSame(1, DB::table('final_results')->where('id', $draft)->update(['updated_at' => now()->addMinute()]));
    }

    private function draftResultId(): int
    {
        $o = $this->offeringA;
        DB::setDefaultConnection($this->main);
        $this->actingAs($this->teacherA)->post(route('offerings.marks', $o), ['rows' => $this->marksFor($o, [55, 20, 14, 17]), 'compute' => true]);
        $id = (int) FinalResult::where('status', 'draft')->value('id');
        $this->asApp();

        return $id;
    }
}
