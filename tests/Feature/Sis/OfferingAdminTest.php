<?php

namespace Tests\Feature\Sis;

use App\Models\AcademicTerm;
use App\Models\CourseOffering;
use App\Models\CurriculumCourse;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfferingAdminTest extends TestCase
{
    use RefreshDatabase, SisFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildUniversity(3);
    }

    public function test_open_a_whole_stage_in_a_new_term_and_enroll_the_cohort(): void
    {
        $term = AcademicTerm::create(['academic_year_id' => AcademicTerm::first()->academic_year_id, 'code' => 'S2', 'name_ar' => 'الثاني']);
        // the fixture curriculum course has no term_no; make it a term-2 course
        CurriculumCourse::query()->update(['term_no' => 2]);
        $program = Program::where('code', 'PA')->first();
        $this->actingAs($this->admin)->post(route('offerings.store'), [
            'mode' => 'stage', 'academic_term_id' => $term->id, 'program_id' => $program->id, 'stage' => 1, 'section' => 'A', 'capacity' => 50, 'enroll' => true,
        ])->assertSessionHas('success');
        $o = CourseOffering::where('academic_term_id', $term->id)->firstOrFail();
        $this->assertSame(3, $o->enrollments()->count());
        $this->assertSame(3, $o->components()->count());
    }

    public function test_component_weights_must_total_100_and_lock_after_marks(): void
    {
        $o = $this->offeringA;
        $bad = [['code' => 'coursework', 'name_ar' => 'س', 'weight' => 30, 'max_mark' => 30, 'exam_round' => 1], ['code' => 'final', 'name_ar' => 'ن', 'weight' => 60, 'max_mark' => 60, 'exam_round' => 1]];
        $this->actingAs($this->admin)->put(route('offerings.components', $o), ['components' => $bad])->assertSessionHas('error');
        $this->assertSame(3, $o->components()->count(), 'a rejected change leaves the components as they were');

        $good = [['code' => 'coursework', 'name_ar' => 'س', 'weight' => 50, 'max_mark' => 50, 'exam_round' => 1], ['code' => 'final', 'name_ar' => 'ن', 'weight' => 50, 'max_mark' => 50, 'exam_round' => 1], ['code' => 'final_r2', 'name_ar' => 'د2', 'weight' => 50, 'max_mark' => 50, 'exam_round' => 2]];
        $this->actingAs($this->admin)->put(route('offerings.components', $o), ['components' => $good])->assertSessionHas('success');
        $this->assertEquals(50, $o->components()->where('code', 'coursework')->value('weight'));

        $cw = $o->components()->where('code', 'coursework')->first();
        $e = $o->enrollments()->first();
        $this->actingAs($this->teacherA)->post(route('offerings.marks', $o), ['rows' => [$e->id => [$cw->id => ['mark' => 40]]]]);
        $this->actingAs($this->admin)->put(route('offerings.components', $o), ['components' => $good])->assertSessionHas('error');
    }

    public function test_enroll_and_withdraw_one_student(): void
    {
        $o = $this->offeringA;
        $e = $o->enrollments()->first();
        $this->actingAs($this->admin)->post(route('offerings.withdraw', [$o, $e]), ['reason' => 'انسحاب'])->assertSessionHas('success');
        $this->assertSame('withdrawn', $e->fresh()->status);

        $other = Student::whereDoesntHave('enrollments', fn ($q) => $q->where('course_offering_id', $o->id))->where('name_ar', 'like', '%B%')->first();
        // a student of another program can still take the course as a carried/elective one
        $this->actingAs($this->admin)->post(route('offerings.students.store', $o), ['student_id' => $other->id, 'carried' => true])->assertSessionHas('success');
        $this->assertTrue(Enrollment::where('student_id', $other->id)->where('course_offering_id', $o->id)->value('is_carried'));
    }

    public function test_college_staff_cannot_manage_another_colleges_offering(): void
    {
        $this->actingAs($this->registrarB)->put(route('offerings.update', $this->offeringA), ['capacity' => 10, 'section' => 'A'])->assertForbidden();
    }
}
