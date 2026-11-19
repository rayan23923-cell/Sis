<?php

namespace Tests\Feature\Sis;

use App\Models\AcademicYear;
use App\Models\AdministrativeOrder;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollegeIsolationTest extends TestCase
{
    use RefreshDatabase, SisFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildUniversity(3);
    }

    public function test_college_staff_only_see_their_college_students(): void
    {
        $visible = Student::visibleTo($this->registrarB)->pluck('name_ar');
        $this->assertCount(3, $visible);
        $this->assertTrue($visible->every(fn ($n) => str_contains($n, 'B')));

        $studentA = Student::where('name_ar', 'like', '%A%')->first();
        $this->actingAs($this->registrarB)->get(route('students.show', $studentA))->assertForbidden();
        $this->actingAs($this->admin)->get(route('students.show', $studentA))->assertOk();
    }

    public function test_committee_cannot_open_or_approve_another_colleges_offering(): void
    {
        $this->actingAs($this->committeeA)->get(route('offerings.show', $this->offeringB))->assertForbidden();
        $this->actingAs($this->committeeA)->post(route('offerings.decide', $this->offeringB), ['action' => 'approve'])->assertForbidden();
    }

    public function test_teacher_sees_only_their_own_courses(): void
    {
        $this->actingAs($this->teacherA)->get(route('offerings.show', $this->offeringA))->assertOk();
        $this->actingAs($this->teacherA)->get(route('offerings.show', $this->offeringB))->assertForbidden();
        $this->actingAs($this->teacherA)->get(route('students.index'))->assertForbidden();
    }

    public function test_status_change_requires_matching_order_and_is_recorded(): void
    {
        $s = Student::visibleTo($this->registrarB)->first();
        $order = AdministrativeOrder::create(['number' => '5', 'issued_on' => '2026-01-10', 'type' => 'admission', 'title' => 'x', 'issued_by_authority' => 'y']);
        $this->actingAs($this->registrarB)->post(route('students.status', $s), ['to' => 'postponed', 'order_id' => $order->id])->assertSessionHas('error');
        $this->assertSame('active', $s->fresh()->status);

        $order2 = AdministrativeOrder::create(['number' => '6', 'issued_on' => '2026-01-10', 'type' => 'postponement', 'title' => 'تأجيل', 'issued_by_authority' => 'y']);
        $this->actingAs($this->registrarB)->post(route('students.status', $s), ['to' => 'postponed', 'order_id' => $order2->id])->assertSessionHas('success');
        $this->assertSame('postponed', $s->fresh()->status);
        $this->assertSame($order2->id, $s->statusChanges()->reorder()->latest('id')->first()->administrative_order_id);
    }

    public function test_duplicate_person_is_blocked(): void
    {
        $existing = Student::first();
        $program = $existing->currentProgram->program;
        $this->actingAs($this->admin)->post(route('students.store'), [
            'name_ar' => $existing->name_ar, 'mother_name_ar' => 'أم', 'birth_date' => $existing->birth_date->toDateString(), 'gender' => 'm',
            'program_id' => $program->id, 'year_id' => AcademicYear::first()->id, 'stage' => 1,
        ])->assertSessionHas('error');
    }

    public function test_university_wide_staff_see_every_offering(): void
    {
        $registrar = $this->user('reg-all@t', 'registrar');
        $this->actingAs($registrar)->get(route('offerings.index'))->assertInertia(fn ($p) => $p->has('offerings.data', 2));
        $this->actingAs($this->admin)->get(route('offerings.index'))->assertInertia(fn ($p) => $p->has('offerings.data', 2));
        $this->actingAs($this->teacherA)->get(route('offerings.index'))->assertInertia(fn ($p) => $p->has('offerings.data', 1));
    }
}
