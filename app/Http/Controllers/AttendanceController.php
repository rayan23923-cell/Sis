<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\CourseOffering;
use App\Services\AttendanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $attendance) {}

    public function show(Request $request, CourseOffering $offering): Response
    {
        Gate::authorize('view-offering', $offering);
        $offering->load(['course:id,code,name_ar', 'term.year', 'teacher:id,name', 'program.department']);
        $summary = $this->attendance->summary($offering);
        $students = $offering->enrollments()->where('status', '!=', 'withdrawn')->with('student:id,name_ar,university_number')->get()
            ->sortBy(fn ($e) => $e->student->name_ar)->values()
            ->map(fn ($e) => ['enrollment_id' => $e->id, 'student_id' => $e->student_id, 'name' => $e->student->name_ar, 'number' => $e->student->university_number, 'carried' => $e->is_carried] + $summary[$e->id]);

        $sessions = $offering->attendanceSessions()->with('records:id,attendance_session_id,enrollment_id,status')->get()->map(fn (AttendanceSession $s) => [
            'id' => $s->id, 'held_on' => $s->held_on->toDateString(), 'period' => $s->period, 'hours' => $s->hours, 'kind' => $s->kind, 'topic' => $s->topic,
            'absent' => $s->records->where('status', 'absent')->count(),
            'excused' => $s->records->where('status', 'excused')->count(),
            'late' => $s->records->where('status', 'late')->count(),
            'statuses' => $s->records->where('status', '!=', 'present')->pluck('status', 'enrollment_id'),
        ])->reverse()->values();

        return Inertia::render('Offerings/Attendance', [
            'offering' => [
                'id' => $offering->id, 'code' => $offering->course->code, 'name' => $offering->course->name_ar,
                'term' => $offering->term->label(), 'teacher' => $offering->teacher?->name, 'program' => $offering->program?->name_ar,
                'stage' => $offering->stage, 'section' => $offering->section, 'shift' => $offering->shift, 'status' => $offering->status,
            ],
            'students' => $students,
            'sessions' => $sessions,
            'policy' => $this->attendance->policyInfo($offering),
            'statuses' => AttendanceRecord::STATUSES,
            'kinds' => AttendanceSession::KINDS,
            'canEdit' => Gate::allows('take-attendance', $offering) && $offering->status !== 'locked',
            'today' => today()->toDateString(),
        ]);
    }

    public function store(Request $request, CourseOffering $offering): RedirectResponse
    {
        Gate::authorize('take-attendance', $offering);
        $data = $this->validated($request);
        $this->attendance->record($offering, $request->user(), $data, $data['statuses'] ?? []);

        return back()->with('success', 'سُجّل الحضور.');
    }

    public function update(Request $request, CourseOffering $offering, AttendanceSession $session): RedirectResponse
    {
        Gate::authorize('take-attendance', $offering);
        abort_unless($session->course_offering_id === $offering->id, 404);
        $data = $this->validated($request);
        $this->attendance->record($offering, $request->user(), $data, $data['statuses'] ?? [], $session);

        return back()->with('success', 'عُدّل سجل المحاضرة.');
    }

    public function destroy(Request $request, CourseOffering $offering, AttendanceSession $session): RedirectResponse
    {
        Gate::authorize('take-attendance', $offering);
        abort_unless($session->course_offering_id === $offering->id, 404);
        $this->attendance->delete($session);

        return back()->with('success', 'حُذفت المحاضرة من سجل الحضور.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'held_on' => ['required', 'date'],
            'period' => ['required', 'integer', 'min:1', 'max:12'],
            'hours' => ['required', 'numeric', 'min:0.5', 'max:8'],
            'kind' => ['required', Rule::in(array_keys(AttendanceSession::KINDS))],
            'topic' => ['nullable', 'string', 'max:255'],
            'statuses' => ['array'],
            'statuses.*' => [Rule::in(array_keys(AttendanceRecord::STATUSES))],
        ], [], ['held_on' => 'تاريخ المحاضرة', 'period' => 'رقم المحاضرة', 'hours' => 'عدد الساعات', 'topic' => 'الموضوع']);
    }
}
