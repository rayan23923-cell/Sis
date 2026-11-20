<?php

namespace Tests\Feature\Sis;

use App\Models\AcademicPolicy;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\AuditLog;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase, SisFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildUniversity();
    }

    private function lecture(string $date, array $statuses, int $period = 1, float $hours = 2)
    {
        return $this->actingAs($this->teacherA)->post(route('offerings.attendance.store', $this->offeringA), [
            'held_on' => $date, 'period' => $period, 'hours' => $hours, 'kind' => 'lecture', 'statuses' => $statuses,
        ]);
    }

    public function test_teacher_records_lectures_and_percentages_follow(): void
    {
        $ids = $this->offeringA->enrollments()->orderBy('id')->pluck('id');
        $first = $ids[0];

        for ($d = 1; $d <= 10; $d++) {
            $this->lecture(sprintf('2025-10-%02d', $d), $d <= 2 ? [$first => 'absent', $ids[1] => 'excused'] : [])->assertSessionHas('success');
        }
        $this->assertSame(10, AttendanceSession::count());
        // only non-present students are listed, everyone else is stored as present
        $this->assertSame(40, AttendanceRecord::count());

        $this->actingAs($this->teacherA)->get(route('offerings.attendance', $this->offeringA))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Offerings/Attendance')
                ->where('students', fn ($rows) => collect($rows)->firstWhere('enrollment_id', $first)['percent'] == 20
                    && collect($rows)->firstWhere('enrollment_id', $first)['level'] === 2
                    && collect($rows)->firstWhere('enrollment_id', $ids[1])['percent'] == 0)  // excused does not count by default
                ->where('policy.official', false)->where('policy.limit', null));

        // gradebook shows the percentage next to the student
        $this->actingAs($this->teacherA)->get(route('offerings.show', $this->offeringA))
            ->assertInertia(fn ($p) => $p->where("attendance.{$first}.percent", 20));
    }

    /** B-01 (FR-AR-18): a past year's attendance is assessed by that year's policy, not by the one in force today. */
    public function test_attendance_uses_the_policy_of_the_offerings_year_not_today(): void
    {
        $ids = $this->offeringA->enrollments()->orderBy('id')->pluck('id');
        for ($d = 1; $d <= 10; $d++) {
            $this->lecture(sprintf('2025-10-%02d', $d), $d <= 2 ? [$ids[1] => 'excused'] : [])->assertSessionHas('success');
        }
        // a later version, in force only from the next academic year, that also counts excused absence
        $current = AcademicPolicy::where('type', 'attendance')->orderByDesc('version')->firstOrFail();
        AcademicPolicy::create([
            'type' => 'attendance', 'name_ar' => 'نسخة السنة التالية', 'version' => $current->version + 1, 'executor' => $current->executor,
            'parameters' => ['count_excused' => true] + $current->parameters, 'study_system' => $current->study_system,
            'scope_type' => 'university', 'effective_from' => '2026-09-01', 'is_official' => false, 'status' => 'approved', 'approved_at' => now(),
        ]);
        $this->travelTo('2027-01-15'); // "today" is well inside the new version's period

        $this->actingAs($this->teacherA)->get(route('offerings.attendance', $this->offeringA))->assertOk()
            ->assertInertia(fn ($p) => $p->where('students', fn ($rows) => collect($rows)->firstWhere('enrollment_id', $ids[1])['percent'] == 0)
                ->where('policy.count_excused', false));

        // recomputing the historical register (an edit of a 2025 lecture, made "today") still uses that year's policy
        $session = AttendanceSession::orderBy('held_on')->first();
        $this->actingAs($this->teacherA)->put(route('offerings.attendance.update', [$this->offeringA, $session]), [
            'held_on' => $session->held_on->toDateString(), 'period' => $session->period, 'hours' => $session->hours, 'kind' => 'lecture',
            'statuses' => [$ids[1] => 'excused', $ids[2] => 'excused'],
        ])->assertSessionHas('success');
        $this->actingAs($this->teacherA)->get(route('offerings.attendance', $this->offeringA))->assertOk()
            ->assertInertia(fn ($p) => $p->where('students', fn ($rows) => collect($rows)->firstWhere('enrollment_id', $ids[1])['percent'] == 0
                && collect($rows)->firstWhere('enrollment_id', $ids[2])['percent'] == 0));
        // the gradebook page shows the same figures
        $this->actingAs($this->teacherA)->get(route('offerings.show', $this->offeringA))
            ->assertInertia(fn ($p) => $p->where("attendance.{$ids[1]}.percent", 0));
    }

    public function test_duplicate_period_future_date_and_strangers_are_refused(): void
    {
        $this->lecture('2025-10-01', [])->assertSessionHas('success');
        $this->lecture('2025-10-01', [])->assertSessionHas('error');
        $this->lecture(now()->addDay()->toDateString(), [])->assertSessionHas('error');

        $other = $this->offeringB->enrollments()->first()->id;
        $this->lecture('2025-10-02', [$other => 'absent'])->assertSessionHas('error');
        $this->assertSame(1, AttendanceSession::count());

        // a teacher of another college cannot take this register; a college registrar of B cannot either
        $this->actingAs($this->registrarB)->post(route('offerings.attendance.store', $this->offeringA), ['held_on' => '2025-10-03', 'period' => 1, 'hours' => 2, 'kind' => 'lecture'])->assertForbidden();
    }

    public function test_editing_a_register_is_audited_and_locked_offering_is_frozen(): void
    {
        $this->lecture('2025-10-01', [])->assertSessionHas('success');
        $session = AttendanceSession::first();
        $id = $this->offeringA->enrollments()->first()->id;

        $this->actingAs($this->teacherA)->put(route('offerings.attendance.update', [$this->offeringA, $session]), [
            'held_on' => '2025-10-01', 'period' => 1, 'hours' => 2, 'kind' => 'lecture', 'statuses' => [$id => 'excused'],
        ])->assertSessionHas('success');
        $this->assertSame('excused', AttendanceRecord::where('enrollment_id', $id)->value('status'));
        $this->assertTrue(AuditLog::where('auditable_type', $session->getMorphClass())->where('auditable_id', $session->id)->where('action', 'updated')
            ->get()->contains(fn ($l) => ($l->new_values['records'][$id] ?? null) === 'excused'));

        $this->offeringA->update(['status' => 'locked']);
        $this->actingAs($this->teacherA)->delete(route('offerings.attendance.destroy', [$this->offeringA, $session]))->assertSessionHas('error');
        $this->assertSame(1, AttendanceSession::count());
    }

    public function test_student_sees_own_absence_in_portal(): void
    {
        $enrollment = $this->offeringA->enrollments()->first();
        $this->lecture('2025-10-01', [$enrollment->id => 'absent']);
        $this->lecture('2025-10-02', []);

        $student = Student::find($enrollment->student_id);
        app(StudentAccountService::class)->issue($student, $this->admin);
        $user = User::find($student->fresh()->user_id);
        $user->update(['must_change_password' => false]);

        $this->actingAs($user)->get(route('portal.home'))->assertOk()
            ->assertInertia(fn ($p) => $p->where('current.0.absence.percent', 50)->where('current.0.absence.absent', 1));
        $this->actingAs($this->admin)->get(route('students.show', $student))
            ->assertInertia(fn ($p) => $p->where('courses.0.absence.percent', 50));
    }
}
