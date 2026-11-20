<?php

namespace Tests\Feature\Sis;

use App\Models\AcademicPolicy;
use App\Models\FinalResult;
use App\Services\GradebookService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * R-4 on the real database, as it runs on a server: `sis:audit-policy-dates` in its own process, inside a MariaDB
 * READ ONLY transaction, over committed data.
 */
#[Group('mariadb')]
class AuditPolicyDatesProcessTest extends TestCase
{
    use SisFixture;

    private string $out = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mariadb') {
            $this->markTestSkipped('Runs on MariaDB only (DB_CONNECTION=mariadb).');
        }
        Artisan::call('migrate:fresh');
        $this->buildUniversity();
        $this->out = storage_path('framework/testing-audit-process-'.getmypid().'.csv');
    }

    protected function tearDown(): void
    {
        if ($this->out !== '') {
            @unlink($this->out);
            @unlink($this->out.'.meta.json');
        }
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_the_database_itself_refuses_writes_in_a_read_only_transaction(): void
    {
        DB::unprepared('START TRANSACTION READ ONLY');
        try {
            DB::table('academic_years')->update(['is_current' => false]);
            $this->fail('MariaDB accepted a write inside START TRANSACTION READ ONLY');
        } catch (QueryException $e) {
            $this->assertStringContainsString('READ ONLY', strtoupper($e->getMessage()));
        } finally {
            DB::unprepared('ROLLBACK');
        }
    }

    public function test_the_command_runs_read_only_in_its_own_process(): void
    {
        $o = $this->offeringA;
        $g = app(GradebookService::class);
        $c = $o->components()->where('exam_round', 1)->get()->keyBy('code');
        $rows = [];
        foreach ($o->enrollments()->orderBy('id')->get() as $e) {
            $rows[$e->id] = [$c['coursework']->id => ['mark' => 30], $c['final']->id => ['mark' => 40]];
        }
        $g->saveMarks($o, $o->teacher, $rows);
        $g->compute($o->fresh(), $o->teacher);
        $g->submit($o->fresh(), $o->teacher);
        $g->approve($o->fresh(), $this->admin);

        // one result stored with a version that was not in force in its year, written before the lock as results
        // computed before PR #2 were (once locked, the database refuses it: B-09)
        $cur = AcademicPolicy::where('type', 'grade_scale')->where('study_system', 'bologna')->orderByDesc('version')->firstOrFail();
        $next = AcademicPolicy::create(['type' => 'grade_scale', 'name_ar' => 'نسخة', 'version' => $cur->version + 1, 'executor' => $cur->executor,
            'parameters' => $cur->parameters, 'study_system' => 'bologna', 'scope_type' => 'university', 'effective_from' => '2026-09-01',
            'is_official' => false, 'status' => 'approved', 'approved_at' => now()]);
        $r = FinalResult::orderBy('id')->first();
        $snap = $r->policy_snapshot;
        $snap['grade_scale'] = $next->toRule()->snapshot();
        DB::table('final_results')->where('id', $r->id)->update(['policy_snapshot' => json_encode($snap)]);
        $g->lock($o->fresh(), $this->admin);

        $fingerprint = fn () => md5(json_encode([DB::table('final_results')->orderBy('id')->get(), DB::table('academic_policies')->orderBy('id')->get()]));
        $before = $fingerprint();

        $env = ['APP_ENV' => 'testing'];
        foreach (['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $k) {
            $env[$k] = (string) env($k);
        }
        $p = new Process([PHP_BINARY, base_path('artisan'), 'sis:audit-policy-dates', '--output='.$this->out], base_path(), $env, null, 120);
        $p->run();
        $this->assertTrue($p->isSuccessful(), $p->getOutput().$p->getErrorOutput());

        $meta = json_decode(file_get_contents($this->out.'.meta.json'), true);
        $this->assertTrue($meta['read_only']['database_read_only_transaction'], 'on MariaDB the whole run is one READ ONLY transaction');
        $this->assertSame(1, $meta['results_by_classification']['suspicious']);
        $this->assertSame($before, $fingerprint());
    }
}
