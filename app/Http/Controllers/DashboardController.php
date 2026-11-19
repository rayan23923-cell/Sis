<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\College;
use App\Models\CourseOffering;
use App\Models\FinalResult;
use App\Models\Student;
use App\Models\TimetableSlot;
use App\Services\TimetableService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $students = Student::visibleTo($user);
        $offerings = CourseOffering::visibleTo($user);

        $myCourses = CourseOffering::where('teacher_id', $user->id)
            ->with(['course:id,code,name_ar', 'term:id,name_ar,academic_year_id', 'term.year:id,name'])
            ->withCount(['enrollments' => fn ($q) => $q->where('status', '!=', 'withdrawn')])
            ->orderByRaw("case status when 'open' then 0 when 'submitted' then 1 else 2 end")->limit(8)->get()
            ->map(fn ($o) => $this->offeringCard($o));

        $toApprove = $user->hasRole('committee', 'dean', 'super_admin')
            ? (clone $offerings)->whereIn('status', ['submitted', 'approved'])->with(['course:id,code,name_ar', 'teacher:id,name'])
                ->withCount('enrollments')->limit(8)->get()->map(fn ($o) => $this->offeringCard($o))
            : collect();

        $resultsQuery = FinalResult::where('is_current', true)
            ->whereHas('enrollment.offering', fn ($o) => $o->visibleTo($user));

        $mySlots = app(TimetableService::class)->forTeacher($request->user(), TimetableService::currentTerms()->pluck('id')->all());

        return Inertia::render('Dashboard', [
            'myTimetable' => app(TimetableService::class)->present($mySlots),
            'days' => TimetableSlot::DAYS,
            'year' => AcademicYear::where('is_current', true)->value('name'),
            'terms' => AcademicTerm::whereHas('year', fn ($y) => $y->where('is_current', true))->get(['id', 'name_ar', 'status']),
            'stats' => [
                'students' => (clone $students)->count(),
                'active' => (clone $students)->where('status', 'active')->count(),
                'colleges' => College::visibleTo($user)->count(),
                'offerings' => (clone $offerings)->count(),
                'byStatus' => (clone $offerings)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status'),
                'outcomes' => (clone $resultsQuery)->where('status', 'locked')->selectRaw('outcome, count(*) as c')->groupBy('outcome')->pluck('c', 'outcome'),
                'byStudentStatus' => (clone $students)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status'),
            ],
            'myCourses' => $myCourses,
            'toApprove' => $toApprove,
            'statusLabels' => Student::STATUSES,
        ]);
    }

    private function offeringCard(CourseOffering $o): array
    {
        return [
            'id' => $o->id,
            'code' => $o->course->code,
            'name' => $o->course->name_ar,
            'section' => $o->section,
            'status' => $o->status,
            'round' => $o->current_round,
            'students' => $o->enrollments_count,
            'teacher' => $o->teacher?->name,
        ];
    }
}
