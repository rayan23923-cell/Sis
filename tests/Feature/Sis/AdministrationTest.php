<?php

namespace Tests\Feature\Sis;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CurriculumVersion;
use App\Models\Department;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdministrationTest extends TestCase
{
    use RefreshDatabase, SisFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildUniversity(2);
    }

    public function test_admin_creates_a_scoped_account(): void
    {
        $dept = Department::first();
        $this->actingAs($this->admin)->post(route('users.store'), [
            'name' => 'تدريسي جديد', 'email' => 'new@t', 'password' => 'a-long-password', 'active' => true,
            'roles' => [['role' => 'teacher', 'department_id' => $dept->id]],
        ])->assertSessionHas('success');
        $u = User::where('email', 'new@t')->firstOrFail();
        $this->assertSame(['teacher'], $u->roles());
        $this->assertSame([$dept->id], $u->departmentScope());
    }

    public function test_college_role_needs_a_college(): void
    {
        $this->actingAs($this->admin)->post(route('users.store'), [
            'name' => 'x', 'email' => 'x@t', 'password' => 'a-long-password', 'roles' => [['role' => 'dean']],
        ])->assertSessionHasErrors('roles.0.college_id');
    }

    public function test_only_admin_manages_users_and_cannot_lock_themselves_out(): void
    {
        $this->actingAs($this->committeeA)->get(route('users.index'))->assertForbidden();
        $this->actingAs($this->admin)->put(route('users.update', $this->admin), [
            'name' => 'a', 'email' => $this->admin->email, 'active' => false, 'roles' => [['role' => 'super_admin']],
        ])->assertSessionHasErrors('roles');
    }

    public function test_deactivated_user_is_signed_out(): void
    {
        $this->teacherA->update(['is_active' => false]);
        $this->actingAs($this->teacherA->fresh())->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_curriculum_lifecycle(): void
    {
        $dept = Department::first();
        $this->actingAs($this->admin)->post(route('programs.store'), [
            'department_id' => $dept->id, 'code' => 'NEW', 'name_ar' => 'برنامج جديد', 'study_system' => 'bologna', 'degree' => 'bachelor', 'duration_years' => 4,
        ])->assertSessionHas('success');
        $cv = CurriculumVersion::whereHas('program', fn ($p) => $p->where('code', 'NEW'))->firstOrFail();
        $this->assertSame('draft', $cv->status);

        $course = Course::where('department_id', $dept->id)->first();
        $this->actingAs($this->admin)->post(route('curricula.courses.store', $cv), ['course_id' => $course->id, 'stage' => 1, 'term_no' => 1])->assertSessionHas('success');
        $this->actingAs($this->admin)->post(route('curricula.approve', $cv), ['approval_reference' => 'كتاب 12'])->assertSessionHas('success');
        $this->assertSame('approved', $cv->fresh()->status);

        $this->actingAs($this->admin)->post(route('curricula.courses.store', $cv), ['course_id' => Course::latest('id')->first()->id, 'stage' => 2])->assertSessionHas('error');

        $this->actingAs($this->admin)->post(route('curricula.version', $cv))->assertRedirect();
        $v2 = CurriculumVersion::where('program_id', $cv->program_id)->where('version', 2)->firstOrFail();
        $this->assertSame('draft', $v2->status);
        $this->assertSame(1, $v2->courses()->count());
    }

    public function test_study_system_is_frozen_once_students_exist(): void
    {
        $p = Program::whereHas('studentPrograms')->first();
        $this->actingAs($this->admin)->put(route('programs.update', $p), [
            'department_id' => $p->department_id, 'code' => $p->code, 'name_ar' => $p->name_ar,
            'study_system' => $p->study_system === 'annual' ? 'bologna' : 'annual', 'degree' => 'bachelor', 'duration_years' => 4,
        ])->assertSessionHas('error');
    }

    public function test_new_year_with_terms_and_registrar_only(): void
    {
        $this->actingAs($this->committeeA)->post(route('years.store'), ['name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31', 'terms' => 'both'])->assertForbidden();
        $this->actingAs($this->admin)->post(route('years.store'), ['name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31', 'terms' => 'both'])->assertSessionHas('success');
        $y = AcademicYear::where('name', '2026-2027')->firstOrFail();
        $this->assertSame(3, $y->terms()->count());
        $this->actingAs($this->admin)->post(route('years.current', $y));
        $this->assertSame(1, AcademicYear::where('is_current', true)->count());
        $this->assertTrue($y->fresh()->is_current);
    }
}
