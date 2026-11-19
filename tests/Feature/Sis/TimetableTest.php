<?php

namespace Tests\Feature\Sis;

use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Room;
use App\Models\TimetableSlot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimetableTest extends TestCase
{
    use RefreshDatabase, SisFixture;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildUniversity();
        $this->room = Room::create(['college_id' => $this->offeringB->collegeId(), 'code' => 'R1', 'name_ar' => 'قاعة 1', 'capacity' => 2, 'type' => 'lecture']);
    }

    private function slot(CourseOffering $o, array $over = [])
    {
        return $this->actingAs($this->registrarB)->post(route('timetable.store'), $over + [
            'course_offering_id' => $o->id, 'day' => 1, 'starts_at' => '08:30', 'ends_at' => '10:30', 'room_id' => $this->room->id, 'kind' => 'lecture',
        ]);
    }

    public function test_places_a_class_and_refuses_room_teacher_and_cohort_clashes(): void
    {
        // the room is smaller than the section: allowed, with a warning
        $this->slot($this->offeringB)->assertSessionHas('success')->assertSessionHas('warning');
        $this->assertSame(1, TimetableSlot::count());

        // a second course of the same cohort, same teacher, same room and overlapping time
        $course = Course::create(['department_id' => $this->offeringB->course->department_id, 'code' => 'CB2', 'name_ar' => 'مادة ب2', 'credit_hours' => 2]);
        $other = CourseOffering::create(['course_id' => $course->id, 'academic_term_id' => $this->offeringB->academic_term_id, 'program_id' => $this->offeringB->program_id,
            'stage' => 1, 'teacher_id' => $this->offeringB->teacher_id]);
        $this->slot($other, ['starts_at' => '09:30', 'ends_at' => '11:00'])->assertSessionHas('error');
        $this->assertCount(3, session('problems'));   // room, teacher, cohort
        $this->assertSame(1, TimetableSlot::count());

        // back to back is fine
        $this->slot($other, ['starts_at' => '10:30', 'ends_at' => '12:00'])->assertSessionHas('success');
        $this->slot($other, ['starts_at' => '12:00', 'ends_at' => '11:00'])->assertSessionHas('error');
    }

    public function test_only_the_college_can_edit_and_everyone_sees_their_own_week(): void
    {
        $this->actingAs($this->teacherA)->post(route('timetable.store'), [
            'course_offering_id' => $this->offeringB->id, 'day' => 1, 'starts_at' => '08:30', 'ends_at' => '10:30', 'kind' => 'lecture',
        ])->assertForbidden();
        $this->actingAs($this->registrarB)->post(route('timetable.store'), [
            'course_offering_id' => $this->offeringA->id, 'day' => 1, 'starts_at' => '08:30', 'ends_at' => '10:30', 'kind' => 'lecture',
        ])->assertForbidden();

        $this->slot($this->offeringB);
        $q = ['program' => $this->offeringB->program_id, 'stage' => 1, 'section' => 'A', 'shift' => 'morning'];
        $this->actingAs($this->registrarB)->get(route('timetable.index', $q))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Timetable/Index')->has('slots', 1)->where('canEdit', true)->where('offerings.0.placed', 2));
        $this->actingAs($this->registrarB)->get(route('timetable.pdf', $q))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $teacher = $this->offeringB->teacher;
        $this->actingAs($teacher)->get(route('dashboard'))->assertInertia(fn ($p) => $p->has('myTimetable', 1));
        $this->actingAs($teacher)->get(route('timetable.mine'))->assertOk();
    }

    public function test_rooms_are_managed_per_college(): void
    {
        $this->actingAs($this->registrarB)->post(route('rooms.store'), ['college_id' => $this->offeringB->collegeId(), 'code' => 'LAB1', 'name_ar' => 'مختبر', 'capacity' => 30, 'type' => 'lab', 'is_active' => true])->assertSessionHas('success');
        $this->actingAs($this->registrarB)->post(route('rooms.store'), ['college_id' => $this->offeringA->collegeId(), 'code' => 'X', 'name_ar' => 'x', 'capacity' => 30, 'type' => 'lab'])->assertForbidden();
        $this->actingAs($this->registrarB)->get(route('rooms.index'))->assertOk()->assertInertia(fn ($p) => $p->has('rooms', 2));
        $this->actingAs($this->teacherA)->get(route('rooms.index'))->assertForbidden();
    }
}
