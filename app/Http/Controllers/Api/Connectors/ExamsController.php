<?php

namespace App\Http\Controllers\Api\Connectors;

use App\Http\Controllers\Controller;
use App\Integrations\Connectors\Catalog;
use App\Integrations\Connectors\ExamMarksInbox;
use App\Models\AcademicTerm;
use App\Models\CourseOffering;
use App\Services\GradebookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the exams system may do in SIS (see docs/CONNECTORS.md). Each route names its tool; the
 * "connector" middleware refuses a tool the admin switched off.
 */
class ExamsController extends Controller
{
    public function __construct(private readonly GradebookService $gradebook) {}

    public function manifest(Request $request): JsonResponse
    {
        $c = $request->attributes->get('connector');

        return response()->json([
            'system' => 'sis', 'connector' => 'exams', 'version' => 1,
            'university' => config('sis.university_name'),
            'tools' => collect(Catalog::get('exams')['tools'])->map(fn ($t, $k) => ['key' => $k, 'name' => $t['name'], 'enabled' => $c->toolOn($k)])->values(),
        ]);
    }

    /** Course offerings of the current year (or of one term), with their assessment components. */
    public function offerings(Request $request): JsonResponse
    {
        $f = $request->validate(['term_id' => ['nullable', 'integer'], 'updated_since' => ['nullable', 'date']]);
        $terms = isset($f['term_id'])
            ? AcademicTerm::whereKey($f['term_id'])->pluck('id')
            : AcademicTerm::whereHas('year', fn ($y) => $y->where('is_current', true))->pluck('id');

        $rows = CourseOffering::whereIn('academic_term_id', $terms)
            ->when($f['updated_since'] ?? null, fn ($q, $d) => $q->where('updated_at', '>=', $d))
            ->with(['course:id,code,name_ar,name_en,department_id', 'course.department:id,name_ar,college_id', 'course.department.college:id,code,name_ar',
                'term:id,code,name_ar,academic_year_id', 'term.year:id,name', 'teacher:id,name', 'components'])
            ->withCount(['enrollments' => fn ($e) => $e->where('status', '!=', 'withdrawn')])
            ->orderBy('id')->get()
            ->map(fn (CourseOffering $o) => [
                'id' => $o->id,
                'course' => ['code' => $o->course->code, 'name_ar' => $o->course->name_ar, 'name_en' => $o->course->name_en],
                'college' => ['code' => $o->course->department->college->code, 'name_ar' => $o->course->department->college->name_ar],
                'department' => $o->course->department->name_ar,
                'term' => ['id' => $o->term->id, 'code' => $o->term->code, 'name_ar' => $o->term->name_ar, 'year' => $o->term->year->name],
                'stage' => $o->stage, 'section' => $o->section, 'shift' => $o->shift,
                'teacher' => $o->teacher?->name,
                'status' => $o->status, 'current_round' => $o->current_round, 'is_evaluative' => (bool) $o->is_evaluative,
                'students' => $o->enrollments_count,
                'components' => $o->components->sortBy(['exam_round', 'sort'])->values()->map(fn ($c) => [
                    'code' => $c->code, 'name_ar' => $c->name_ar, 'max_mark' => (float) $c->max_mark, 'exam_round' => $c->exam_round,
                    'accepts_marks' => $o->status === 'open' && $c->exam_round === $o->current_round,
                ]),
            ]);

        return response()->json(['data' => $rows]);
    }

    /** The students who sit the current round of one offering: everyone in round 1, only the resits later. */
    public function students(int $offering): JsonResponse
    {
        // bound by hand, after the key check, so a caller without a key cannot probe which ids exist
        $offering = CourseOffering::findOrFail($offering);
        $round = $offering->current_round;

        return response()->json([
            'offering_id' => $offering->id, 'round' => $round,
            'data' => $this->gradebook->eligible($offering, $round)->map(fn ($e) => [
                'university_number' => (string) $e->student->university_number,
                'name_ar' => $e->student->name_ar,
                'shift' => $e->student->study_shift,
                'attempt' => $e->attempt_no,
            ])->values(),
        ]);
    }

    public function marks(Request $request, int $offering, ExamMarksInbox $inbox): JsonResponse
    {
        $offering = CourseOffering::findOrFail($offering);
        $data = $request->validate([
            'component' => ['required', 'string', 'max:40'],
            'reference' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:\-]+$/'],
            'marks' => ['required', 'array', 'min:1', 'max:2000'],
            'marks.*.university_number' => ['required', 'string', 'max:30'],
            'marks.*.mark' => ['nullable', 'numeric'],
            'marks.*.absent' => ['nullable', 'boolean'],
        ]);
        ['batch' => $batch, 'created' => $created] = $inbox->receive($offering, $data['component'], $data['reference'], $data['marks']);

        return response()->json([
            'batch_id' => $batch->id, 'status' => $batch->status, 'accepted' => count($batch->rows), 'unknown' => $batch->unknown ?? [],
            'message' => 'Marks received. They enter the gradebook after the course teacher accepts them.',
        ], $created ? 202 : 200);
    }

    public function batch(Request $request, int $batch): JsonResponse
    {
        $b = \App\Models\ConnectorMarkBatch::where('connector', 'exams')->findOrFail($batch);

        return response()->json(['batch_id' => $b->id, 'reference' => $b->reference, 'status' => $b->status, 'note' => $b->note, 'reviewed_at' => $b->reviewed_at?->toIso8601String()]);
    }
}
