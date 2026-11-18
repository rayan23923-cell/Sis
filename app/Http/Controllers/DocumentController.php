<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\CourseOffering;
use App\Models\IssuedDocument;
use App\Models\Program;
use App\Models\ProgressionDecision;
use App\Models\Student;
use App\Services\Documents\Pdf;
use App\Services\Documents\TranscriptService;
use App\Services\GradebookService;
use App\Services\PolicyResolver;
use App\Services\ProgressionService;
use App\Services\WorkflowException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/** Printable PDF documents. Every document carries who printed it and when. */
class DocumentController extends Controller
{
    public function __construct(private readonly Pdf $pdf) {}

    public function transcript(Request $request, Student $student, TranscriptService $transcripts): Response
    {
        $user = $request->user();
        $own = $student->user_id !== null && $student->user_id === $user->id;
        if (! $own) {
            Gate::authorize('view-students');
            abort_unless(Student::visibleTo($user)->whereKey($student->id)->exists(), 403);
        }
        $data = $transcripts->build($student);
        $doc = $this->issue('transcript', $student, $data, $request);

        return $this->pdf->response('pdf.transcript', $data + ['doc' => $doc] + $this->base($request, 'كشف الدرجات', $data['program']?->department->college->name_ar, $doc->code),
            'transcript-'.$student->university_number.'.pdf');
    }

    /** Graduation document: only for a student whose graduation was recorded by an order. */
    public function graduation(Request $request, Student $student, TranscriptService $transcripts): Response
    {
        $user = $request->user();
        $own = $student->user_id !== null && $student->user_id === $user->id;
        if (! $own) {
            Gate::authorize('view-students');
            abort_unless(Student::visibleTo($user)->whereKey($student->id)->exists(), 403);
        }
        if ($student->status !== 'graduated') {
            throw new WorkflowException('وثيقة التخرج تصدر للطالب المتخرج فقط.');
        }
        $data = $transcripts->build($student);
        $change = $student->statusChanges()->where('to_status', 'graduated')->latest('effective_on')->with('order')->first();
        $data['graduated_on'] = $change?->effective_on?->toDateString();
        $data['graduation_order'] = $change?->order?->reference();
        $doc = $this->issue('graduation', $student, $data, $request);

        return $this->pdf->response('pdf.graduation', $data + ['doc' => $doc] + $this->base($request, 'وثيقة تخرج', $data['program']?->department->college->name_ar, $doc->code),
            'graduation-'.$student->university_number.'.pdf');
    }

    /** Records the printed document so its QR code can be verified later. */
    private function issue(string $type, Student $student, array $data, Request $request): IssuedDocument
    {
        $snapshot = [
            'name' => $student->name_ar, 'name_en' => $student->name_en, 'number' => $student->university_number,
            'college' => $data['program']?->department->college->name_ar, 'department' => $data['program']?->department->name_ar,
            'program' => $data['program']?->name_ar, 'status' => $data['status'],
            'years' => collect($data['years'])->map(fn ($y) => ['year' => $y['name'], 'gpa' => $y['gpa'], 'credits' => $y['credits'], 'decision' => $y['decision'], 'courses' => count($y['rows'])])->all(),
            'graduated_on' => $data['graduated_on'] ?? null,
        ];

        return IssuedDocument::create(['type' => $type, 'student_id' => $student->id, 'snapshot' => $snapshot, 'official' => $data['official'], 'issued_by' => $request->user()->id]);
    }

    public function gradeSheet(Request $request, CourseOffering $offering, GradebookService $gradebook): Response
    {
        Gate::authorize('view-offering', $offering);
        $offering->load(['course.department.college', 'term.year', 'teacher:id,name', 'program']);
        $round = max(1, min((int) $request->integer('round', $offering->current_round), $offering->current_round));
        $components = $gradebook->componentsForRound($offering, $round);
        $official = true;
        $rows = $gradebook->eligible($offering, $round)->map(function ($e) use ($round, $components, &$official) {
            $marks = $e->marks->keyBy('assessment_component_id');
            $r = $e->results->first(fn ($x) => $x->exam_round === $round && $x->is_current);
            if ($r && ! $r->isOfficial()) {
                $official = false;
            }

            return [
                'number' => $e->student->university_number, 'name' => $e->student->name_ar, 'carried' => $e->is_carried,
                'marks' => $components->mapWithKeys(fn ($c) => [$c->id => $marks->get($c->id)?->is_absent ? 'غ' : ($marks->get($c->id)?->mark !== null ? (float) $marks->get($c->id)->mark : '—')])->all(),
                'total' => $r ? (float) $r->total_mark : null, 'rounded' => $r ? (float) $r->rounded_mark : null, 'grade' => $r?->grade_name_ar,
                'outcome_code' => $r?->outcome, 'outcome' => $r ? (TranscriptService::OUTCOMES[$r->outcome] ?? $r->outcome) : null, 'failed' => $r && ! $r->passed,
            ];
        })->sortBy('name')->values();
        $outcomes = $rows->countBy(fn ($r) => $r['outcome_code'] ?? 'none');

        return $this->pdf->response('pdf.grade-sheet', [
            'o' => $offering, 'round' => $round, 'components' => $components, 'rows' => $rows->all(),
            'statusLabel' => CourseOffering::STATUSES[$offering->status] ?? $offering->status, 'official' => $official,
            'summary' => ['pass' => $outcomes->get('pass', 0), 'fail' => $outcomes->get('fail', 0) + $outcomes->get('absent', 0), 'resit' => $outcomes->get('resit', 0), 'none' => $outcomes->get('none', 0)],
        ] + $this->base($request, 'قائمة درجات', $offering->course->department->college->name_ar.' — '.$offering->course->department->name_ar, $offering->course->code.'-'.$offering->section),
            'grades-'.$offering->course->code.'-'.$offering->section.'-r'.$round.'.pdf', $components->count() > 3);
    }

    public function yearResults(Request $request, ProgressionService $progression, PolicyResolver $policies): Response
    {
        Gate::authorize('view-progression');
        $data = $request->validate(['program' => ['required', 'exists:programs,id'], 'stage' => ['required', 'integer', 'min:1'], 'year' => ['required', 'exists:academic_years,id']]);
        $program = Program::visibleTo($request->user())->with('department.college')->findOrFail($data['program']);
        $year = AcademicYear::findOrFail($data['year']);
        $rows = $progression->preview($program, (int) $data['stage'], $year);

        return $this->pdf->response('pdf.year-results', [
            'program' => $program, 'stage' => (int) $data['stage'], 'year' => $year, 'rows' => $rows->all(),
            'decisions' => ProgressionDecision::DECISIONS, 'summary' => $rows->countBy(fn ($r) => $r['decision'] ?? 'pending')->all(),
            'official' => $policies->forProgram($program, $year->ends_on)->allOfficial(),
        ] + $this->base($request, 'نتائج نهاية السنة', $program->department->college->name_ar.' — '.$program->department->name_ar, $year->name),
            'year-results-'.$program->code.'-'.$data['stage'].'.pdf', false);
    }

    private function base(Request $request, string $title, ?string $unit, ?string $ref): array
    {
        return [
            'title' => $title, 'unit' => $unit, 'ref' => $ref,
            'university' => config('sis.university_name'),
            'printedBy' => $request->user()->name,
            'printedAt' => now()->timezone('Asia/Baghdad')->format('Y-m-d H:i'),
        ];
    }
}
