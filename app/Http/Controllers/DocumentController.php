<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\CourseOffering;
use App\Models\Program;
use App\Models\ProgressionDecision;
use App\Models\Student;
use App\Services\Documents\DocumentIssuer;
use App\Services\Documents\Pdf;
use App\Services\Documents\TranscriptService;
use App\Services\GradebookService;
use App\Services\PolicyResolver;
use App\Services\ProgressionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/** Printable PDF documents. Every document carries who printed it and when. */
class DocumentController extends Controller
{
    public function __construct(private readonly Pdf $pdf) {}

    public function transcript(Request $request, Student $student, DocumentIssuer $issuer): Response
    {
        return $this->direct('transcript', $request, $student, $issuer);
    }

    /** Graduation document: only for a student whose graduation was recorded by an order. */
    public function graduation(Request $request, Student $student, DocumentIssuer $issuer): Response
    {
        return $this->direct('graduation', $request, $student, $issuer);
    }

    /** تأييد استمرار بالدراسة: printed by the registrar (students ask for it through a request). */
    public function enrollment(Request $request, Student $student, DocumentIssuer $issuer): Response
    {
        Gate::authorize('view-students');

        return $this->direct('enrollment', $request, $student, $issuer, $request->validate(['addressed_to' => ['nullable', 'string', 'max:150']]));
    }

    private function direct(string $type, Request $request, Student $student, DocumentIssuer $issuer, array $extra = []): Response
    {
        $user = $request->user();
        $own = $student->user_id !== null && $student->user_id === $user->id;
        if (! $own) {
            Gate::authorize('view-students');
            abort_unless(Student::visibleTo($user)->whereKey($student->id)->exists(), 403);
        }
        $out = $issuer->issue($type, $student, $user, $extra);

        return response($out['pdf'], 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$out['filename'].'"', 'Cache-Control' => 'private, no-store']);
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
