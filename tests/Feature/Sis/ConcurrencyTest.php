<?php

namespace Tests\Feature\Sis;

use App\Models\AcademicYear;
use App\Models\AdministrativeOrder;
use App\Models\AuditLog;
use App\Models\CourseOffering;
use App\Models\Enrollment;
use App\Models\FinalResult;
use App\Services\GradebookService;
use App\Services\StudentService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * B-06 (FR-CC-01) and B-07 (FR-CC-03): real PHP processes racing on MariaDB.
 * Each competitor loads its data first (as a request would), waits for one shared instant, then writes.
 */
#[Group('mariadb')]
class ConcurrencyTest extends TestCase
{
    use SisFixture;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mariadb') {
            $this->markTestSkipped('Needs real parallel processes on one database (DB_CONNECTION=mariadb).');
        }
        Artisan::call('migrate:fresh');
        $this->buildUniversity();
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    /**
     * @param  list<list<string|int>>  $competitors  action and arguments for each process
     * @return list<array{ok: bool, refused?: bool, error?: string}>
     */
    private function race(array $competitors): array
    {
        $env = ['APP_ENV' => 'testing', 'QUEUE_CONNECTION' => 'sync', 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array'];
        foreach (['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $k) {
            $env[$k] = (string) env($k);
        }
        $startAt = sprintf('%.3f', microtime(true) + 4 + count($competitors) * 0.15); // every process booted and loaded by then
        $procs = [];
        foreach ($competitors as $c) {
            $p = new Process([PHP_BINARY, base_path('tests/Support/race-worker.php'), $startAt, ...array_map('strval', $c)], base_path(), $env, null, 180);
            $p->start();
            $procs[] = $p;
        }
        $out = [];
        foreach ($procs as $p) {
            $p->wait();
            $line = json_decode(trim($p->getOutput()), true);
            $this->assertIsArray($line, 'worker crashed: '.$p->getOutput().$p->getErrorOutput());
            $out[] = $line;
        }

        return $out;
    }

    private function wins(array $out): int
    {
        return count(array_filter($out, fn ($r) => $r['ok']));
    }

    /** Every loser must get a clear refusal, never a database error or a crash. */
    private function assertCleanRefusals(array $out): void
    {
        foreach ($out as $r) {
            $this->assertTrue($r['ok'] || ($r['refused'] ?? false), 'not a clean refusal: '.($r['error'] ?? ''));
        }
    }

    private function toSubmitted(CourseOffering $o): void
    {
        $g = app(GradebookService::class);
        $c = $o->components()->where('exam_round', 1)->get()->keyBy('code');
        $rows = [];
        foreach ($o->enrollments()->orderBy('id')->get() as $e) {
            $rows[$e->id] = [$c['coursework']->id => ['mark' => 30], $c['final']->id => ['mark' => 40]];
        }
        $g->saveMarks($o, $o->teacher, $rows);
        $g->compute($o->fresh(), $o->teacher);
        $g->submit($o->fresh(), $o->teacher);
    }

    public function test_fifty_students_racing_for_the_last_seat_get_exactly_one_seat(): void
    {
        $o = $this->offeringA;
        $year = AcademicYear::first();
        $ids = [];
        for ($i = 0; $i < 50; $i++) {
            $ids[] = app(StudentService::class)->admit(['name_ar' => "متسابق {$i}", 'birth_date' => '2006-02-01', 'allow_duplicate' => true],
                $o->program, null, $year, $this->admin)->id;
        }
        $taken = $o->enrollments()->count();
        $o->update(['capacity' => $taken + 1]); // one seat left

        $out = $this->race(array_map(fn ($id) => ['enroll', $id, $o->id, $this->admin->id], $ids));

        $this->assertSame(1, $this->wins($out), json_encode(array_count_values(array_map(fn ($r) => $r['error'] ?? 'ok', $out)), JSON_UNESCAPED_UNICODE));
        $this->assertCleanRefusals($out);
        $this->assertSame($taken + 1, Enrollment::where('course_offering_id', $o->id)->count(), 'capacity exceeded');
    }

    public function test_one_student_enrolled_ten_times_at_once_is_enrolled_once(): void
    {
        $o = $this->offeringA;
        $student = app(StudentService::class)->admit(['name_ar' => 'طالب مكرر', 'birth_date' => '2006-03-01', 'allow_duplicate' => true],
            $o->program, null, AcademicYear::first(), $this->admin);

        $out = $this->race(array_fill(0, 10, ['enroll', $student->id, $o->id, $this->admin->id]));

        $this->assertSame(1, $this->wins($out));
        $this->assertCleanRefusals($out);
        $this->assertSame(1, Enrollment::where('student_id', $student->id)->count());
    }

    public function test_ten_simultaneous_approvals_approve_once(): void
    {
        $o = $this->offeringA;
        $this->toSubmitted($o);

        $out = $this->race(array_fill(0, 10, ['approve', $o->id, $this->committeeA->id]));

        $this->assertSame(1, $this->wins($out));
        $this->assertCleanRefusals($out);
        $this->assertSame('approved', $o->fresh()->status);
        $approvals = AuditLog::where('auditable_type', $o->getMorphClass())->where('auditable_id', $o->id)->get()
            ->filter(fn ($l) => ($l->new_values['status'] ?? null) === 'approved');
        $this->assertCount(1, $approvals, 'one approval in the audit trail');
    }

    public function test_approve_and_return_racing_leave_one_consistent_outcome(): void
    {
        $o = $this->offeringA;
        $this->toSubmitted($o);

        $out = $this->race([...array_fill(0, 5, ['approve', $o->id, $this->committeeA->id]), ...array_fill(0, 5, ['return', $o->id, $this->committeeA->id])]);

        $this->assertSame(1, $this->wins($out));
        $this->assertCleanRefusals($out);
        $status = $o->fresh()->status;
        $results = FinalResult::whereIn('enrollment_id', $o->enrollments()->select('id'))->pluck('status')->unique()->values()->all();
        // approved offering ⇔ approved results; returned offering ⇔ draft results
        $this->assertSame($status === 'approved' ? ['approved'] : ['draft'], $results, "offering {$status}");
    }

    public function test_ten_simultaneous_locks_lock_once(): void
    {
        $o = $this->offeringA;
        $this->toSubmitted($o);
        app(GradebookService::class)->approve($o->fresh(), $this->committeeA);

        $out = $this->race(array_fill(0, 10, ['lock', $o->id, $this->committeeA->id]));

        $this->assertSame(1, $this->wins($out));
        $this->assertCleanRefusals($out);
        $locks = AuditLog::where('auditable_type', $o->getMorphClass())->where('auditable_id', $o->id)->get()
            ->filter(fn ($l) => ($l->new_values['status'] ?? null) === 'locked');
        $this->assertCount(1, $locks);
    }

    public function test_five_simultaneous_corrections_of_one_result_make_one_new_version(): void
    {
        $o = $this->offeringA;
        $this->toSubmitted($o);
        $g = app(GradebookService::class);
        $g->approve($o->fresh(), $this->committeeA);
        $g->lock($o->fresh(), $this->committeeA);
        $result = FinalResult::orderBy('id')->first();
        $order = AdministrativeOrder::create(['number' => '5', 'issued_on' => now()->toDateString(), 'type' => 'result_correction', 'title' => 'ت', 'issued_by_authority' => 'ع']);
        $final = $o->components()->where('code', 'final')->first();

        $out = $this->race(array_map(fn ($m) => ['correct', $result->id, $order->id, $this->admin->id, $final->id, $m], [41, 42, 43, 44, 45]));

        $this->assertSame(1, $this->wins($out));
        $this->assertCleanRefusals($out);
        $versions = FinalResult::where('enrollment_id', $result->enrollment_id)->where('exam_round', $result->exam_round)->get();
        $this->assertCount(2, $versions);
        $this->assertSame(1, $versions->where('is_current', true)->count(), 'exactly one current version');
    }
}
