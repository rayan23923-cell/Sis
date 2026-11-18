<?php

namespace Tests\Feature\Sis;

use App\Models\AcademicYear;
use App\Models\AssessmentMark;
use App\Models\Program;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class DocumentsAndImportTest extends TestCase
{
    use RefreshDatabase, SisFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildUniversity();
    }

    private function xlsx(array $rows): UploadedFile
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray($rows, null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'f.xlsx', null, null, true);
    }

    public function test_pdf_documents_render_for_authorised_users(): void
    {
        $student = $this->offeringA->enrollments()->first()->student;
        $this->actingAs($this->admin)->get(route('students.transcript', $student))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($this->teacherA)->get(route('offerings.sheet', $this->offeringA))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($this->registrarB)->get(route('progression.pdf', ['program' => $this->offeringB->program_id, 'stage' => 1, 'year' => AcademicYear::first()->id]))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_pdf_documents_respect_college_scope(): void
    {
        $student = $this->offeringA->enrollments()->first()->student;
        $this->actingAs($this->registrarB)->get(route('students.transcript', $student))->assertForbidden();
        $this->actingAs($this->registrarB)->get(route('offerings.sheet', $this->offeringA))->assertForbidden();
    }

    public function test_marks_template_round_trips_through_import(): void
    {
        $res = $this->actingAs($this->teacherA)->get(route('offerings.marks.template', $this->offeringA))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 't').'.xlsx';
        file_put_contents($path, $res->streamedContent());
        $rows = IOFactory::load($path)->getSheet(0)->toArray();
        $this->assertSame('الرقم الجامعي', $rows[0][0]);
        $this->assertCount(5, $rows); // header + 4 students

        foreach (array_keys($rows) as $i) {
            if ($i > 0) {
                $rows[$i][2] = 30;           // coursework
                $rows[$i][3] = $i === 1 ? 'غ' : '٤٥'; // final, Arabic digits accepted
            }
        }
        $this->actingAs($this->teacherA)->post(route('offerings.marks.import', $this->offeringA), ['file' => $this->xlsx($rows)])
            ->assertSessionHas('success');
        $this->assertSame(8, AssessmentMark::count());
        $this->assertSame(1, AssessmentMark::where('is_absent', true)->count());
        $this->assertSame(3, AssessmentMark::where('mark', 45)->count());
    }

    public function test_marks_import_rejects_whole_file_on_bad_row(): void
    {
        $c = $this->offeringA->components()->where('exam_round', 1)->orderBy('sort')->get();
        $numbers = $this->offeringA->enrollments()->with('student')->get()->pluck('student.university_number');
        $rows = [['الرقم الجامعي', 'الاسم', "x [{$c[0]->code}]", "y [{$c[1]->code}]"],
            [$numbers[0], '', 30, 50], [$numbers[1], '', 30, 75], ['999', '', 1, 1]];

        $this->actingAs($this->teacherA)->post(route('offerings.marks.import', $this->offeringA), ['file' => $this->xlsx($rows)])
            ->assertSessionHas('error')->assertSessionHas('problems', fn ($p) => count($p) === 2);
        $this->assertSame(0, AssessmentMark::count());
    }

    public function test_only_the_teacher_imports_marks(): void
    {
        $this->actingAs($this->committeeA)->post(route('offerings.marks.import', $this->offeringA), ['file' => $this->xlsx([['a']])])->assertForbidden();
    }

    public function test_students_import_previews_then_admits(): void
    {
        $existing = Student::first();
        $program = Program::find($this->offeringB->program_id);
        $rows = [
            ['الاسم الرباعي', 'اسم الأم', 'تاريخ الميلاد', 'الجنس', 'الرقم الوطني'],
            ['أحمد علي حسين كاظم', 'فاطمة جاسم', '2006-02-01', 'ذكر', '199900001'],
            ['سارة محمد جواد علي', 'زينب كريم', '15/03/2006', 'أنثى', ''],
            ['', 'بلا اسم', '2006-01-01', 'ذكر', ''],
            [$existing->name_ar, $existing->mother_name_ar ?? 'أم الطالب', $existing->birth_date->toDateString(), 'ذكر', ''],
        ];
        $before = Student::count();
        $res = $this->actingAs($this->registrarB)->post(route('students.import.preview'), [
            'file' => $this->xlsx($rows), 'program_id' => $program->id, 'year_id' => AcademicYear::first()->id, 'stage' => 1,
        ])->assertRedirect();
        parse_str(parse_url($res->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->assertSame($before, Student::count(), 'preview must not save');

        $this->actingAs($this->registrarB)->get(route('students.import', ['token' => $q['token']]))
            ->assertInertia(fn ($p) => $p->component('Students/Import')
                ->where('preview.rows.0.state', 'ok')->where('preview.rows.1.state', 'ok')->where('preview.rows.1.birth_date', '2006-03-15')
                ->where('preview.rows.2.state', 'error')->where('preview.rows.3.state', 'duplicate'));

        // another user cannot use the token
        $this->actingAs($this->admin)->post(route('students.import.commit'), ['token' => $q['token']])->assertSessionHas('error');

        $this->actingAs($this->registrarB)->post(route('students.import.commit'), ['token' => $q['token']])->assertRedirect(route('students.index'));
        $this->assertSame($before + 2, Student::count());
        $this->assertSame(2, Student::whereHas('currentProgram', fn ($p) => $p->where('program_id', $program->id))->whereIn('name_ar', ['أحمد علي حسين كاظم', 'سارة محمد جواد علي'])->count());
    }

    public function test_students_import_limited_to_own_college(): void
    {
        $this->actingAs($this->registrarB)->post(route('students.import.preview'), [
            'file' => $this->xlsx([['الاسم الرباعي', 'اسم الأم', 'تاريخ الميلاد', 'الجنس'], ['a', 'b', '2006-01-01', 'ذكر']]),
            'program_id' => $this->offeringA->program_id, 'year_id' => AcademicYear::first()->id, 'stage' => 1,
        ])->assertNotFound();
    }
}
