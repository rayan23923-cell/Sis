<?php

namespace Tests\Feature\Sis;

use App\AcademicRules\CourseResultCalculator;
use App\AcademicRules\Data\ComponentMark;
use App\Http\Controllers\OfferingAdminController;
use App\Models\AcademicYear;
use App\Models\AdministrativeOrder;
use App\Models\AuditLog;
use App\Models\CourseOffering;
use App\Models\Enrollment;
use App\Models\FinalResult;
use App\Services\EnrollmentService;
use App\Services\GradebookService;
use App\Services\StudentService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Request;
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

    /** F-G3-1 under concurrency: ten simultaneous submissions submit once, with one audit row. */
    public function test_ten_simultaneous_submissions_submit_once(): void
    {
        $o = $this->offeringA;
        $g = app(GradebookService::class);
        $c = $o->components()->where('exam_round', 1)->get()->keyBy('code');
        $g->saveMarks($o, $o->teacher, collect($o->enrollments()->pluck('id'))->mapWithKeys(fn ($id) => [$id => [$c['coursework']->id => ['mark' => 30], $c['final']->id => ['mark' => 40]]])->all());
        $g->compute($o->fresh(), $o->teacher);
        $before = AuditLog::where('auditable_type', CourseOffering::class)->count();

        $out = $this->race(array_fill(0, 10, ['submit', $o->id, $o->teacher->id]));

        $this->assertSame(1, $this->wins($out));
        $this->assertCleanRefusals($out);
        $this->assertSame('submitted', $o->fresh()->status);
        $this->assertSame($before + 1, AuditLog::where('auditable_type', CourseOffering::class)->count());
    }

    /**
     * F-G3-1 under concurrency: marks saved while others press submit. Whatever the order, an offering that ends up
     * submitted has drafts that match its marks: a save that got in first makes the submit refuse; a submit that got
     * in first makes the saves refuse. Three rounds, so both orders occur.
     */
    public function test_marks_saved_during_submission_never_let_a_stale_draft_through(): void
    {
        $o = $this->offeringA;
        $g = app(GradebookService::class);
        $c = $o->components()->where('exam_round', 1)->get()->keyBy('code');
        $ids = $o->enrollments()->orderBy('id')->pluck('id')->all();
        $g->saveMarks($o, $o->teacher, collect($ids)->mapWithKeys(fn ($id) => [$id => [$c['coursework']->id => ['mark' => 30], $c['final']->id => ['mark' => 40]]])->all());
        $outcomes = [];

        foreach ([0, 1, 2] as $round) {
            $o->refresh();
            if ($o->status === 'submitted') {
                $g->returnToTeacher($o, $this->committeeA, 'جولة اختبار');
            }
            $g->compute($o->fresh(), $o->teacher);
            $competitors = [];
            foreach ($ids as $k => $id) {
                $competitors[] = ['save', $o->id, $o->teacher->id, $id, $c['final']->id, 41 + $round * 5 + $k];
                $competitors[] = ['submit', $o->id, $o->teacher->id];
            }
            $out = $this->race($competitors);
            $this->assertCleanRefusals($out);
            $o->refresh();
            $outcomes[] = $o->status;
            if ($o->status === 'submitted') {
                $components = $g->componentsForRound($o, 1);
                foreach ($o->enrollments()->with('marks')->get() as $e) {
                    $marks = $e->marks->keyBy('assessment_component_id');
                    $hash = CourseResultCalculator::hash($components->map(fn ($c) => new ComponentMark(
                        $c->code, (float) $c->weight, (float) $c->max_mark, $marks->get($c->id)?->mark, (bool) $marks->get($c->id)?->is_absent,
                    ))->all(), (float) $o->course->credit_hours);
                    $draft = FinalResult::where('enrollment_id', $e->id)->where('is_current', true)->firstOrFail();
                    $this->assertSame($hash, $draft->input_hash, 'round '.$round.': a submitted draft does not match the marks of enrollment '.$e->id);
                }
            }
        }
        fwrite(STDERR, '[F-G3-1 race] statuses after each round: '.implode(', ', $outcomes)."\n");
    }

    /** O-1: withdrawing students while the committee approves never leaves a withdrawn student with an approved result. */
    public function test_withdrawals_racing_approval_leave_no_withdrawn_student_with_an_approved_result(): void
    {
        $o = $this->offeringA;
        $this->toSubmitted($o);
        $ids = $o->enrollments()->orderBy('id')->pluck('id')->all();
        $results = FinalResult::count();

        $out = $this->race([
            ...array_fill(0, 3, ['approve', $o->id, $this->committeeA->id]),
            ...array_map(fn ($id) => ['withdraw', $o->id, $id, $this->admin->id], array_slice($ids, 0, 3)),
        ]);

        $this->assertCleanRefusals($out);
        $withdrawn = Enrollment::whereIn('id', $ids)->where('status', 'withdrawn')->pluck('id');
        $this->assertSame(0, FinalResult::whereIn('enrollment_id', $withdrawn)->count(), 'a withdrawn student kept a result');
        $this->assertSame(0, FinalResult::where('status', 'approved')->whereIn('enrollment_id', $withdrawn)->count());
        $this->assertSame($results - $withdrawn->count(), FinalResult::count(), 'an approved result was deleted');
        $this->assertSame('approved', $o->fresh()->status);
    }

    /**
     * Start one competitor now (it does not wait for a shared instant) and confirm it is blocked: used to put a
     * process behind a lock this test holds, then release the lock and see what the waiting process does.
     */
    private function startBlocked(array $competitor): Process
    {
        $env = ['APP_ENV' => 'testing', 'QUEUE_CONNECTION' => 'sync', 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array'];
        foreach (['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $k) {
            $env[$k] = (string) env($k);
        }
        $p = new Process([PHP_BINARY, base_path('tests/Support/race-worker.php'), '0', ...array_map('strval', $competitor)], base_path(), $env, null, 120);
        $p->start();
        usleep(4_000_000); // booted and reached the lock
        $this->assertTrue($p->isRunning(), 'the competitor should be waiting on the lock: '.$p->getOutput().$p->getErrorOutput());

        return $p;
    }

    private function finish(Process $p): array
    {
        $p->wait();
        $line = json_decode(trim($p->getOutput()), true);
        $this->assertIsArray($line, 'worker crashed: '.$p->getOutput().$p->getErrorOutput());

        return $line;
    }

    /** O-1: a withdrawal that arrives while the approval is running waits for it, then is refused. */
    public function test_a_withdrawal_during_approval_waits_and_is_refused(): void
    {
        $o = $this->offeringA;
        $this->toSubmitted($o);
        $e = $o->enrollments()->orderBy('id')->first();

        DB::beginTransaction();
        app(GradebookService::class)->approve($o->fresh(), $this->committeeA); // holds the offering lock until commit
        $withdraw = $this->startBlocked(['withdraw', $o->id, $e->id, $this->admin->id]);
        DB::commit();
        $out = $this->finish($withdraw);

        $this->assertFalse($out['ok']);
        $this->assertTrue($out['refused'] ?? false, $out['error'] ?? '');
        $this->assertSame('enrolled', $e->fresh()->status);
        $this->assertSame('approved', FinalResult::where('enrollment_id', $e->id)->value('status'), 'the approved result is still there');
    }

    /** O-1: an approval that arrives while a withdrawal is running waits for it, then approves everyone else. */
    public function test_an_approval_during_a_withdrawal_waits_and_approves_the_others(): void
    {
        $o = $this->offeringA;
        $this->toSubmitted($o);
        $e = $o->enrollments()->orderBy('id')->first();
        $others = $o->enrollments()->where('id', '!=', $e->id)->count();

        DB::beginTransaction();
        app(EnrollmentService::class)->withdraw($e, $this->admin, 'انسحاب'); // holds the offering lock until commit
        $approve = $this->startBlocked(['approve', $o->id, $this->committeeA->id]);
        DB::commit();
        $out = $this->finish($approve);

        $this->assertTrue($out['ok'], $out['error'] ?? '');
        $this->assertSame('withdrawn', $e->fresh()->status);
        $this->assertSame(0, FinalResult::where('enrollment_id', $e->id)->count());
        $this->assertSame($others, FinalResult::where('status', 'approved')->count());
    }

    /** O-2: re-saving the components while the teacher saves the first marks: clean refusals, never a mark without its component. */
    public function test_components_saved_while_marks_are_entered_leave_no_mark_without_its_component(): void
    {
        $o = $this->offeringA;
        $c = $o->components()->where('exam_round', 1)->get()->keyBy('code');
        $ids = $o->enrollments()->orderBy('id')->pluck('id')->all();

        $out = $this->race([
            ...array_fill(0, 3, ['components', $o->id, $this->admin->id]),
            ...array_map(fn ($id) => ['save', $o->id, $o->teacher->id, $id, $c['final']->id, 40], array_slice($ids, 0, 3)),
        ]);

        $this->assertCleanRefusals($out);
        $this->assertSame(0, DB::table('assessment_marks')->whereNotIn('assessment_component_id', DB::table('assessment_components')->select('id'))->count(),
            'marks point at components that no longer exist');
    }

    /** O-2: a component change arriving while marks are being saved waits, then is refused with a clear message. */
    public function test_a_component_change_during_mark_entry_waits_and_is_refused(): void
    {
        $o = $this->offeringA;
        $c = $o->components()->where('exam_round', 1)->get()->keyBy('code');
        $components = $o->components()->orderBy('id')->pluck('id')->all();
        $e = $o->enrollments()->orderBy('id')->first();

        DB::beginTransaction();
        app(GradebookService::class)->saveMarks($o, $o->teacher, [$e->id => [$c['final']->id => ['mark' => 40]]]); // holds the offering lock
        $change = $this->startBlocked(['components', $o->id, $this->admin->id]);
        DB::commit();
        $out = $this->finish($change);

        $this->assertFalse($out['ok']);
        $this->assertTrue($out['refused'] ?? false, 'not a clean refusal: '.($out['error'] ?? ''));
        $this->assertSame($components, $o->components()->orderBy('id')->pluck('id')->all(), 'the components are unchanged');
        $this->assertSame(1, DB::table('assessment_marks')->where('enrollment_id', $e->id)->count());
    }

    /**
     * O-2: marks saved while a component change holds the lock wait for it, then go to the new components only.
     * The saved form still carries the old component ids, which saveMarks ignores (existing behaviour): no mark may
     * point at a removed component, and the new components are complete.
     */
    public function test_marks_saved_during_a_component_change_wait_and_leave_complete_components(): void
    {
        $o = $this->offeringA;
        $old = $o->components()->where('exam_round', 1)->get()->keyBy('code');
        $e = $o->enrollments()->orderBy('id')->first();
        $rows = $o->components()->orderBy('sort')->get()->map(fn ($c) => $c->only(['code', 'name_ar', 'weight', 'max_mark', 'exam_round']))->all();

        DB::beginTransaction();
        $this->actingAs($this->admin);
        $request = Request::create('/test', 'PUT', ['components' => $rows]);
        $request->setUserResolver(fn () => $this->admin);
        $request->setLaravelSession(app('session')->driver('array'));
        app()->instance('request', $request);
        app(OfferingAdminController::class)->components($request, $o); // replaces the components, holds the offering lock
        $save = $this->startBlocked(['save', $o->id, $o->teacher->id, $e->id, $old['final']->id, 40]);
        DB::commit();
        $out = $this->finish($save);

        $this->assertTrue($out['ok'] || ($out['refused'] ?? false), 'not a clean outcome: '.($out['error'] ?? ''));
        $this->assertSame(0, DB::table('assessment_marks')->whereNotIn('assessment_component_id', DB::table('assessment_components')->select('id'))->count());
        $now = $o->components()->orderBy('sort')->get();
        $this->assertSame(array_column($rows, 'code'), $now->pluck('code')->all(), 'all the new components are there');
        $this->assertEqualsWithDelta(100, $now->where('exam_round', 1)->sum('weight'), 0.001);
    }
}
