<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\College;
use App\Models\Course;
use App\Models\CurriculumVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class StructureController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $colleges = College::visibleTo($user)->orderBy('code')
            ->with(['departments' => fn ($d) => $d->visibleTo($user)->orderBy('code')
                ->withCount('courses')
                ->with(['programs' => fn ($p) => $p->withCount(['studentPrograms as students_count' => fn ($s) => $s->where('is_current', true)])
                    ->with(['curricula' => fn ($c) => $c->withCount('courses')->orderByDesc('version')])])])
            ->get();

        return Inertia::render('Structure/Index', [
            'colleges' => $colleges->map(fn ($c) => [
                'id' => $c->id, 'code' => $c->code, 'name' => $c->name_ar, 'name_en' => $c->name_en, 'active' => $c->is_active,
                'departments' => $c->departments->map(fn ($d) => [
                    'id' => $d->id, 'code' => $d->code, 'name' => $d->name_ar, 'name_en' => $d->name_en, 'college_id' => $d->college_id, 'active' => $d->is_active, 'courses' => $d->courses_count,
                    'programs' => $d->programs->map(fn ($p) => [
                        'id' => $p->id, 'code' => $p->code, 'name' => $p->name_ar, 'name_en' => $p->name_en, 'department_id' => $p->department_id, 'active' => $p->is_active, 'system' => $p->study_system,
                        'degree' => $p->degree, 'years' => $p->duration_years, 'students' => $p->students_count,
                        'curricula' => $p->curricula->map(fn (CurriculumVersion $v) => ['id' => $v->id, 'name' => $v->name, 'version' => $v->version, 'status' => $v->status, 'courses' => $v->courses_count]),
                    ]),
                ]),
            ]),
            'canManage' => Gate::allows('manage-structure'),
            'termStatuses' => AcademicTerm::STATUSES,
            'years' => AcademicYear::with('terms')->orderByDesc('starts_on')->get()->map(fn ($y) => [
                'id' => $y->id, 'name' => $y->name, 'current' => $y->is_current,
                'terms' => $y->terms->map(fn ($t) => ['id' => $t->id, 'code' => $t->code, 'name' => $t->name_ar, 'status' => $t->status, 'starts' => $t->starts_on?->toDateString(), 'ends' => $t->ends_on?->toDateString()]),
            ]),
        ]);
    }

    public function curriculum(Request $request, CurriculumVersion $curriculum): Response
    {
        $curriculum->load(['program.department.college', 'courses.course', 'courses.prerequisites']);
        abort_unless($request->user()->collegeScope() === null || $request->user()->canSeeCollege($curriculum->program->department->college_id), 403);

        return Inertia::render('Structure/Curriculum', [
            'curriculum' => [
                'id' => $curriculum->id, 'name' => $curriculum->name, 'version' => $curriculum->version, 'status' => $curriculum->status,
                'reference' => $curriculum->approval_reference, 'program' => $curriculum->program->name_ar,
                'department' => $curriculum->program->department->name_ar, 'college' => $curriculum->program->department->college->name_ar,
                'system' => $curriculum->program->study_system, 'stages_max' => $curriculum->program->duration_years,
                'has_draft' => CurriculumVersion::where('program_id', $curriculum->program_id)->where('status', 'draft')->exists(),
            ],
            'canManage' => Gate::allows('manage-structure'),
            'allCourses' => $curriculum->status === 'draft'
                ? Course::where('is_active', true)->whereHas('department', fn ($d) => $d->where('college_id', $curriculum->program->department->college_id))
                    ->orderBy('code')->get(['id', 'code', 'name_ar', 'credit_hours', 'ects'])
                : [],
            'years' => AcademicYear::orderByDesc('starts_on')->get(['id', 'name']),
            'stages' => $curriculum->courses->sortBy([['stage', 'asc'], ['term_no', 'asc']])->groupBy('stage')->map(fn ($rows, $stage) => [
                'stage' => $stage,
                'credits' => $rows->sum('credit_hours'),
                'ects' => $rows->sum('ects'),
                'courses' => $rows->map(fn ($cc) => [
                    'id' => $cc->id, 'code' => $cc->course->code, 'name' => $cc->course->name_ar, 'name_en' => $cc->course->name_en,
                    'term' => $cc->term_no, 'mandatory' => $cc->is_mandatory, 'credits' => $cc->credit_hours, 'ects' => $cc->ects, 'specialty_rank' => $cc->specialty_rank, 'evaluative' => $cc->is_evaluative,
                    'prerequisites' => $cc->prerequisites->pluck('code'),
                ])->values(),
            ])->values(),
        ]);
    }
}
