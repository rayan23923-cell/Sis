<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\College;
use App\Models\CourseOffering;
use App\Models\Program;
use App\Models\Room;
use App\Models\Student;
use App\Models\TimetableSlot;
use App\Models\User;
use App\Services\Documents\Pdf;
use App\Services\TimetableService;
use App\Support\AuditContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The weekly timetable by cohort, room or teacher, and the rooms of each college. */
class TimetableController extends Controller
{
    public function __construct(private readonly TimetableService $timetable) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $f = $this->filters($request);
        $term = $f['term'] ? AcademicTerm::find($f['term']) : $this->defaultTerm($f);
        $programs = Program::visibleTo($user)->with('department:id,name_ar,college_id')->orderBy('name_ar')->get();
        $colleges = College::visibleTo($user)->orderBy('name_ar')->get(['id', 'name_ar']);

        [$slots, $offerings, $collegeId] = $this->select($user, $f, $term, $programs);
        $canEdit = $collegeId !== null && $this->canManage($user, $collegeId);

        return Inertia::render('Timetable/Index', [
            'filters' => array_merge($f, ['term' => $term?->id]),
            'terms' => AcademicTerm::with('year:id,name')->orderByDesc('starts_on')->get()->map(fn ($t) => ['id' => $t->id, 'name' => $t->label()]),
            'programs' => $programs->map(fn ($p) => ['id' => $p->id, 'name' => $p->name_ar, 'department' => $p->department->name_ar, 'college_id' => $p->department->college_id, 'years' => $p->duration_years]),
            'colleges' => $colleges,
            'rooms' => Room::whereIn('college_id', $colleges->pluck('id'))->where('is_active', true)->orderBy('code')->get(['id', 'college_id', 'code', 'name_ar', 'capacity', 'type']),
            'teachers' => $f['view'] === 'teacher' ? $this->teachers($user) : [],
            'slots' => $this->timetable->present($slots),
            'offerings' => $offerings,
            'days' => TimetableSlot::DAYS,
            'kinds' => TimetableSlot::KINDS,
            'canEdit' => $canEdit,
            'canManageRooms' => Gate::allows('manage-offerings'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $offering = CourseOffering::findOrFail($data['course_offering_id']);
        abort_unless($this->canManage($request->user(), $offering->collegeId()), 403);
        $warnings = $this->timetable->place($offering, $data, $request->user());

        return back()->with('success', 'أُضيف الموعد.')->with('warning', $warnings ? implode(' ', $warnings) : null);
    }

    public function update(Request $request, TimetableSlot $slot): RedirectResponse
    {
        abort_unless($this->canManage($request->user(), $slot->offering->collegeId()), 403);
        $data = $this->validated($request, false);
        $warnings = $this->timetable->place($slot->offering, $data, $request->user(), $slot);

        return back()->with('success', 'عُدّل الموعد.')->with('warning', $warnings ? implode(' ', $warnings) : null);
    }

    public function destroy(Request $request, TimetableSlot $slot): RedirectResponse
    {
        abort_unless($this->canManage($request->user(), $slot->offering->collegeId()), 403);
        $this->timetable->remove($slot, $request->user());

        return back()->with('success', 'حُذف الموعد.');
    }

    public function pdf(Request $request, Pdf $pdf)
    {
        $user = $request->user();
        $f = $this->filters($request);
        $term = $f['term'] ? AcademicTerm::findOrFail($f['term']) : $this->defaultTerm($f);
        abort_unless($term, 404);
        [$slots, , , $title] = $this->select($user, $f, $term, Program::visibleTo($user)->with('department')->get());

        return $pdf->response('pdf.timetable', $this->pdfData($request, $title, $term, $slots), 'timetable.pdf', true);
    }

    /** The teacher's own week and the student's own week (dashboard and portal). */
    public function mine(Request $request, Pdf $pdf)
    {
        $user = $request->user();
        $terms = TimetableService::currentTerms();
        abort_if($terms->isEmpty(), 404);
        $term = $terms->first();
        $student = Student::where('user_id', $user->id)->first();
        $slots = $student ? $this->timetable->forStudent($student, $terms->pluck('id')->all()) : $this->timetable->forTeacher($user, $terms->pluck('id')->all());

        return $pdf->response('pdf.timetable', $this->pdfData($request, $student?->name_ar ?? $user->name, $term, $slots), 'my-timetable.pdf', true);
    }

    // ---- rooms ----

    public function rooms(Request $request): Response
    {
        Gate::authorize('manage-offerings');
        $colleges = College::visibleTo($request->user())->orderBy('name_ar')->get(['id', 'name_ar']);
        $current = TimetableService::currentTerms()->pluck('id')->all();

        return Inertia::render('Timetable/Rooms', [
            'colleges' => $colleges,
            'rooms' => Room::whereIn('college_id', $colleges->pluck('id'))->with('college:id,name_ar')->orderBy('college_id')->orderBy('code')->get()
                ->map(fn ($r) => ['id' => $r->id, 'college_id' => $r->college_id, 'college' => $r->college->name_ar, 'code' => $r->code, 'name_ar' => $r->name_ar,
                    'capacity' => $r->capacity, 'type' => $r->type, 'is_active' => $r->is_active,
                    'hours' => round(TimetableSlot::where('room_id', $r->id)->whereHas('offering', fn ($o) => $o->whereIn('academic_term_id', $current))->get()->sum(fn ($s) => $s->minutes()) / 60, 1)]),
            'types' => Room::TYPES,
        ]);
    }

    public function storeRoom(Request $request): RedirectResponse
    {
        $data = $this->roomData($request);
        abort_unless($this->canManage($request->user(), (int) $data['college_id']), 403);
        AuditContext::as($request->user()->id, 'إضافة قاعة', fn () => Room::create($data));

        return back()->with('success', 'أُضيفت القاعة '.$data['name_ar'].'.');
    }

    public function updateRoom(Request $request, Room $room): RedirectResponse
    {
        abort_unless($this->canManage($request->user(), $room->college_id), 403);
        $data = $this->roomData($request, $room);
        abort_unless($this->canManage($request->user(), (int) $data['college_id']), 403);
        AuditContext::as($request->user()->id, 'تعديل قاعة', fn () => $room->update($data));

        return back()->with('success', 'حُفظت القاعة.');
    }

    // ---- helpers ----

    /** Of the terms in session, the one where this cohort (or room, or teacher) has sections. */
    private function defaultTerm(array $f): ?AcademicTerm
    {
        $terms = TimetableService::currentTerms();
        $has = fn (AcademicTerm $t) => CourseOffering::where('academic_term_id', $t->id)
            ->when($f['view'] === 'cohort' && $f['program'], fn ($q) => $q->where('program_id', $f['program'])->where('stage', $f['stage']))
            ->when($f['view'] === 'teacher' && $f['teacher'], fn ($q) => $q->where('teacher_id', $f['teacher']))
            ->when($f['view'] === 'room' && $f['room'], fn ($q) => $q->whereHas('slots', fn ($s) => $s->where('room_id', $f['room'])))
            ->exists();

        return $terms->first($has) ?? $terms->first();
    }

    private function pdfData(Request $request, string $heading, AcademicTerm $term, $slots): array
    {
        return [
            'title' => 'الجدول الأسبوعي', 'ref' => $term->label(), 'university' => config('sis.university_name'), 'unit' => $heading,
            'printedBy' => $request->user()->name, 'printedAt' => now()->timezone('Asia/Baghdad')->format('Y-m-d H:i'),
            'heading' => $heading, 'term' => $term, 'days' => TimetableSlot::DAYS, 'slots' => $this->timetable->present($slots),
        ];
    }

    private function canManage(User $user, int $collegeId): bool
    {
        return $user->hasRole('super_admin') || $user->hasRoleIn($collegeId, 'registrar', 'college_registrar', 'head');
    }

    private function filters(Request $request): array
    {
        $f = $request->validate([
            'term' => ['nullable', 'integer'], 'view' => ['nullable', Rule::in(['cohort', 'room', 'teacher'])],
            'program' => ['nullable', 'integer'], 'stage' => ['nullable', 'integer', 'min:1', 'max:7'], 'section' => ['nullable', 'string', 'max:10'],
            'shift' => ['nullable', Rule::in(['morning', 'evening'])], 'room' => ['nullable', 'integer'], 'teacher' => ['nullable', 'integer'],
        ]);

        return ['term' => $f['term'] ?? null, 'view' => $f['view'] ?? 'cohort', 'program' => $f['program'] ?? null, 'stage' => $f['stage'] ?? 1,
            'section' => $f['section'] ?? 'A', 'shift' => $f['shift'] ?? 'morning', 'room' => $f['room'] ?? null, 'teacher' => $f['teacher'] ?? null];
    }

    /** @return array{0: Collection, 1: array, 2: ?int, 3: string} slots, offerings to place, college, title */
    private function select(User $user, array $f, ?AcademicTerm $term, $programs): array
    {
        if (! $term) {
            return [collect(), [], null, ''];
        }
        $base = TimetableSlot::with(TimetableService::WITH)->whereHas('offering', fn ($o) => $o->where('academic_term_id', $term->id))->orderBy('day')->orderBy('starts_at');

        if ($f['view'] === 'room' && $f['room']) {
            $room = Room::findOrFail($f['room']);
            abort_unless(College::visibleTo($user)->whereKey($room->college_id)->exists(), 403);

            return [$base->where('room_id', $room->id)->get(), [], null, 'جدول القاعة '.$room->name_ar];
        }
        if ($f['view'] === 'teacher' && $f['teacher']) {
            $teacher = User::findOrFail($f['teacher']);
            $slots = $base->whereHas('offering', fn ($o) => $o->where('teacher_id', $teacher->id)->visibleTo($user))->get();

            return [$slots, [], null, 'جدول التدريسي '.$teacher->name];
        }
        $program = $f['program'] ? $programs->firstWhere('id', (int) $f['program']) : null;
        if (! $program) {
            return [collect(), [], null, ''];
        }
        $cohort = fn ($q) => $q->where('academic_term_id', $term->id)->where('program_id', $program->id)->where('stage', $f['stage'])
            ->where('section', $f['section'])->where(fn ($s) => $s->where('shift', $f['shift'])->when($f['shift'] === 'morning', fn ($m) => $m->orWhereNull('shift')));
        $slots = $base->whereHas('offering', $cohort)->get();
        $offerings = CourseOffering::where($cohort)->with(['course:id,code,name_ar,credit_hours', 'teacher:id,name', 'slots'])->get()
            ->map(fn ($o) => ['id' => $o->id, 'code' => $o->course->code, 'name' => $o->course->name_ar, 'teacher' => $o->teacher?->name,
                'credits' => (float) $o->course->credit_hours, 'placed' => round($o->slots->sum(fn ($s) => $s->minutes()) / 60, 1)])->values()->all();
        $shift = $f['shift'] === 'evening' ? ' — مسائي' : '';

        return [$slots, $offerings, $program->department->college_id, $program->name_ar.' — المرحلة '.$f['stage'].' — شعبة '.$f['section'].$shift];
    }

    private function teachers(User $viewer): array
    {
        return CourseOffering::visibleTo($viewer)->whereNotNull('teacher_id')->with('teacher:id,name')->get()->pluck('teacher')->unique('id')
            ->sortBy('name')->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values()->all();
    }

    private function validated(Request $request, bool $withOffering = true): array
    {
        return $request->validate(array_filter([
            'course_offering_id' => $withOffering ? ['required', 'exists:course_offerings,id'] : null,
            'day' => ['required', 'integer', Rule::in(array_keys(TimetableSlot::DAYS))],
            'starts_at' => ['required', 'date_format:H:i'], 'ends_at' => ['required', 'date_format:H:i'],
            'room_id' => ['nullable', 'exists:rooms,id'], 'kind' => ['required', Rule::in(array_keys(TimetableSlot::KINDS))],
        ]), [], ['day' => 'اليوم', 'starts_at' => 'البدء', 'ends_at' => 'الانتهاء', 'room_id' => 'القاعة']) + ['room_id' => null];
    }

    private function roomData(Request $request, ?Room $room = null): array
    {
        return $request->validate([
            'college_id' => ['required', 'exists:colleges,id'],
            'code' => ['required', 'string', 'max:30', Rule::unique('rooms')->where('college_id', $request->input('college_id'))->ignore($room?->id)],
            'name_ar' => ['required', 'string', 'max:100'], 'capacity' => ['required', 'integer', 'min:0', 'max:2000'],
            'type' => ['required', Rule::in(array_keys(Room::TYPES))], 'is_active' => ['boolean'],
        ], ['code.unique' => 'الرمز مستخدم لقاعة أخرى في الكلية.'], ['code' => 'الرمز', 'name_ar' => 'الاسم', 'capacity' => 'السعة']);
    }
}
