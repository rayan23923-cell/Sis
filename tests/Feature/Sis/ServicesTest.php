<?php

namespace Tests\Feature\Sis;

use App\Models\AcademicYear;
use App\Models\AdministrativeOrder;
use App\Models\CurriculumVersion;
use App\Models\IssuedDocument;
use App\Models\Program;
use App\Models\StudentProgram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicesTest extends TestCase
{
    use RefreshDatabase, SisFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildUniversity();
    }

    private function order(string $type): AdministrativeOrder
    {
        return AdministrativeOrder::create(['number' => (string) random_int(1, 99999), 'issued_on' => now()->toDateString(), 'type' => $type, 'title' => 't', 'issued_by_authority' => 'x']);
    }

    public function test_program_change_keeps_number_and_history(): void
    {
        $student = $this->offeringA->enrollments()->first()->student;
        $from = $student->currentProgram->program;
        $target = Program::create(['department_id' => $from->department_id, 'code' => 'PX', 'name_ar' => 'برنامج آخر', 'study_system' => 'bologna']);
        CurriculumVersion::create(['program_id' => $target->id, 'version' => 1, 'name' => 'v1', 'status' => 'approved']);
        $number = $student->university_number;

        $this->actingAs($this->admin)->post(route('students.status', $student), ['to' => 'program_change', 'program_id' => $target->id, 'stage' => 2, 'order_id' => $this->order('postponement')->id])
            ->assertSessionHas('error');
        $this->actingAs($this->admin)->post(route('students.status', $student), ['to' => 'program_change', 'program_id' => $target->id, 'stage' => 2, 'order_id' => $this->order('program_change')->id])
            ->assertSessionHas('success');

        $student->refresh();
        $this->assertSame($number, $student->university_number);
        $this->assertSame($target->id, $student->currentProgram->program_id);
        $this->assertSame(2, $student->currentProgram->stage);
        $this->assertSame('program_change', StudentProgram::where('student_id', $student->id)->where('program_id', $from->id)->value('end_reason'));
        $this->assertSame('active', $student->status);
    }

    public function test_transcript_is_verifiable_and_revocable(): void
    {
        $student = $this->offeringA->enrollments()->first()->student;
        $this->actingAs($this->admin)->get(route('students.transcript', $student))->assertOk();
        $doc = IssuedDocument::firstOrFail();
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $doc->code);

        auth()->logout();
        $this->get(route('verify', strtolower(str_replace('-', '', $doc->code))))
            ->assertInertia(fn ($p) => $p->component('Verify')->where('document.valid', true)->where('document.data.number', $student->university_number));
        $this->get(route('verify', 'AAAA-BBBB-CCCC'))->assertInertia(fn ($p) => $p->where('document', null));

        $this->actingAs($this->registrarB)->post(route('documents.revoke', $doc), ['reason' => 'x'])->assertForbidden();
        $this->actingAs($this->admin)->post(route('documents.revoke', $doc), ['reason' => 'صدرت بالخطأ'])->assertSessionHas('success');
        $this->get(route('verify', $doc->code))->assertInertia(fn ($p) => $p->where('document.valid', false));

        $this->expectException(\LogicException::class);
        $doc->fresh()->update(['snapshot' => ['name' => 'x']]);
    }

    public function test_graduation_document_only_for_graduates(): void
    {
        $student = $this->offeringA->enrollments()->first()->student;
        $this->actingAs($this->admin)->get(route('students.graduation', $student))->assertSessionHas('error');
        $this->actingAs($this->admin)->post(route('students.status', $student), ['to' => 'graduated', 'order_id' => $this->order('other')->id]);
        $this->actingAs($this->admin)->get(route('students.graduation', $student))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertSame('graduation', IssuedDocument::latest('id')->value('type'));
    }

    public function test_reports_are_scoped_and_exportable(): void
    {
        $year = AcademicYear::first()->id;
        $this->actingAs($this->admin)->get(route('reports.index', ['year' => $year]))
            ->assertInertia(fn ($p) => $p->component('Reports/Index')->where('tables.0.total.6', 8)); // 2 colleges × 4 students
        $this->actingAs($this->registrarB)->get(route('reports.index', ['year' => $year]))
            ->assertInertia(fn ($p) => $p->has('tables.0.rows', 1)->where('tables.0.rows.0.6', 4));
        $this->actingAs($this->registrarB)->get(route('reports.export', ['table' => 'admissions', 'year' => $year]))->assertOk();
        $this->actingAs($this->teacherA)->get(route('reports.index'))->assertForbidden();
    }
}
