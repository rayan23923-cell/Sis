<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\AdministrativeOrder;
use App\Models\College;
use App\Models\IssuedDocument;
use App\Models\Program;
use App\Models\Student;
use App\Services\StudentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StudentController extends Controller
{
    public function __construct(private readonly StudentService $service) {}

    public function index(Request $request): Response
    {
        Gate::authorize('view-students');
        $user = $request->user();
        $filters = $request->only(['q', 'college', 'program', 'status', 'stage', 'shift']);

        $students = Student::visibleTo($user)
            ->with(['currentProgram:id,student_id,program_id,stage', 'currentProgram.program:id,name_ar,department_id,study_system', 'currentProgram.program.department:id,name_ar,college_id'])
            ->when($filters['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('name_ar', 'like', "%{$s}%")
                ->orWhere('university_number', 'like', "{$s}%")->orWhere('national_id', $s)))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['shift'] ?? null, fn ($q, $s) => $q->where('study_shift', $s))
            ->when($filters['program'] ?? null, fn ($q, $p) => $q->whereHas('currentProgram', fn ($c) => $c->where('program_id', $p)))
            ->when($filters['stage'] ?? null, fn ($q, $p) => $q->whereHas('currentProgram', fn ($c) => $c->where('stage', $p)))
            ->when($filters['college'] ?? null, fn ($q, $c) => $q->whereHas('currentProgram.program.department', fn ($d) => $d->where('college_id', $c)))
            ->orderBy('name_ar')
            ->paginate(25)->withQueryString()
            ->through(fn (Student $s) => [
                'id' => $s->id,
                'number' => $s->university_number,
                'name' => $s->name_ar,
                'gender' => $s->gender,
                'status' => $s->status,
                'program' => $s->currentProgram?->program?->name_ar,
                'department' => $s->currentProgram?->program?->department?->name_ar,
                'stage' => $s->currentProgram?->stage,
                'shift' => $s->study_shift,
            ]);

        return Inertia::render('Students/Index', [
            'students' => $students,
            'filters' => $filters,
            'colleges' => College::visibleTo($user)->orderBy('name_ar')->get(['id', 'name_ar']),
            'programs' => Program::visibleTo($user)->with('department:id,college_id')->orderBy('name_ar')->get(['id', 'name_ar', 'department_id'])
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name_ar, 'college_id' => $p->department->college_id]),
            'statuses' => Student::STATUSES,
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('admit-students');

        return Inertia::render('Students/Create', [
            'programs' => Program::visibleTo($request->user())->where('is_active', true)
                ->with(['department:id,name_ar,college_id', 'department.college:id,name_ar'])->orderBy('name_ar')->get()
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name_ar, 'department' => $p->department->name_ar, 'college' => $p->department->college->name_ar, 'system' => $p->study_system, 'years' => $p->duration_years]),
            'years' => AcademicYear::orderByDesc('starts_on')->get(['id', 'name', 'is_current']),
            'orders' => AdministrativeOrder::where('type', 'admission')->latest('issued_on')->limit(30)->get(['id', 'number', 'issued_on', 'title']),
        ]);
    }

    public function duplicates(Request $request)
    {
        Gate::authorize('admit-students');

        return $this->service->possibleDuplicates($request->only(['national_id', 'name_ar', 'birth_date', 'mother_name_ar']))
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name_ar, 'number' => $s->university_number, 'mother' => $s->mother_name_ar, 'birth_date' => $s->birth_date?->toDateString(), 'status' => Student::STATUSES[$s->status]]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('admit-students');
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
            'mother_name_ar' => ['required', 'string', 'max:150'],
            'national_id' => ['nullable', 'string', 'max:30', Rule::unique('students', 'national_id')],
            'birth_date' => ['required', 'date', 'before:today'],
            'gender' => ['required', Rule::in(['m', 'f'])],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email'],
            'admission_channel' => ['nullable', Rule::in(array_keys(Student::CHANNELS))],
            'study_shift' => ['nullable', Rule::in(array_keys(Student::SHIFTS))],
            'program_id' => ['required', 'exists:programs,id'],
            'year_id' => ['required', 'exists:academic_years,id'],
            'stage' => ['required', 'integer', 'min:1', 'max:6'],
            'order_id' => ['nullable', 'exists:administrative_orders,id'],
            'allow_duplicate' => ['boolean'],
        ], [], ['name_ar' => 'الاسم الرباعي', 'mother_name_ar' => 'اسم الأم', 'birth_date' => 'تاريخ الميلاد', 'national_id' => 'الرقم الوطني', 'program_id' => 'البرنامج', 'gender' => 'الجنس']);

        $program = Program::visibleTo($request->user())->findOrFail($data['program_id']);
        $student = $this->service->admit($data, $program, null, AcademicYear::findOrFail($data['year_id']), $request->user(),
            isset($data['order_id']) ? AdministrativeOrder::find($data['order_id']) : null);

        return redirect()->route('students.show', $student)->with('success', 'تم قبول الطالب برقم جامعي '.$student->university_number);
    }

    public function show(Request $request, Student $student): Response
    {
        Gate::authorize('view-students');
        abort_unless(Student::visibleTo($request->user())->whereKey($student->id)->exists(), 403);

        $student->load([
            'admissionYear:id,name',
            'programs.program.department.college', 'programs.curriculum:id,name,version',
            'statusChanges.order', 'statusChanges.creator:id,name',
            'enrollments.offering.course', 'enrollments.offering.term.year', 'enrollments.currentResult',
        ]);

        $transitions = collect(StudentService::TRANSITIONS[$student->status] ?? [])
            ->map(fn ($orderType, $to) => ['to' => $to, 'label' => Student::STATUSES[$to], 'order_type' => $orderType])->values();
        if ($student->status === 'active') {
            $transitions->push(['to' => 'program_change', 'label' => 'تغيير قسم/برنامج', 'order_type' => 'program_change']);
        }
        $currentProgramId = $student->programs->firstWhere('is_current', true)?->program_id;

        return Inertia::render('Students/Show', [
            'student' => [
                'id' => $student->id,
                'number' => $student->university_number,
                'name' => $student->name_ar,
                'name_en' => $student->name_en,
                'mother' => $student->mother_name_ar,
                'national_id' => $student->national_id,
                'birth_date' => $student->birth_date?->toDateString(),
                'gender' => $student->gender,
                'phone' => $student->phone,
                'email' => $student->email,
                'status' => $student->status,
                'channel' => $student->admission_channel,
                'shift' => $student->study_shift,
                'admission_year' => $student->admissionYear?->name,
                'has_account' => $student->user_id !== null,
                'can_issue_account' => Gate::allows('admit-students') && ! in_array($student->status, ['dismissed', 'withdrawn', 'transferred_out'], true),
            ],
            'programs' => $student->programs->map(fn ($sp) => [
                'id' => $sp->id, 'current' => $sp->is_current, 'stage' => $sp->stage,
                'program' => $sp->program->name_ar, 'department' => $sp->program->department->name_ar,
                'college' => $sp->program->department->college->name_ar, 'system' => $sp->program->study_system,
                'curriculum' => $sp->curriculum?->name.' (إصدار '.$sp->curriculum?->version.')',
            ]),
            'history' => $student->statusChanges->map(fn ($c) => [
                'id' => $c->id, 'from' => $c->from_status, 'to' => $c->to_status, 'on' => $c->effective_on->toDateString(),
                'order' => $c->order?->reference(), 'order_title' => $c->order?->title, 'notes' => $c->notes, 'by' => $c->creator?->name,
            ]),
            'courses' => $student->enrollments->sortByDesc('id')->values()->map(fn ($e) => [
                'id' => $e->id, 'offering_id' => $e->course_offering_id,
                'code' => $e->offering->course->code, 'name' => $e->offering->course->name_ar,
                'term' => $e->offering->term->label(), 'attempt' => $e->attempt_no, 'carried' => $e->is_carried,
                'result' => $e->currentResult ? [
                    'mark' => $e->currentResult->rounded_mark, 'grade' => $e->currentResult->grade_name_ar,
                    'code' => $e->currentResult->grade_code, 'outcome' => $e->currentResult->outcome,
                    'status' => $e->currentResult->status, 'round' => $e->currentResult->exam_round,
                    'official' => $e->currentResult->isOfficial(),
                ] : null,
            ]),
            'statuses' => Student::STATUSES,
            'transitions' => Gate::allows('change-student-status', $student) ? $transitions : [],
            'orders' => Gate::allows('change-student-status', $student)
                ? AdministrativeOrder::whereIn('type', $transitions->pluck('order_type')->push('other')->unique())->latest('issued_on')->limit(50)
                    ->get()->map(fn ($o) => ['id' => $o->id, 'type' => $o->type, 'label' => $o->reference().' — '.$o->title])
                : [],
            'orderTypes' => AdministrativeOrder::TYPES,
            'documents' => IssuedDocument::where('student_id', $student->id)->with('issuer:id,name')->latest()->limit(30)->get()
                ->map(fn ($d) => ['id' => $d->id, 'code' => $d->code, 'type' => IssuedDocument::TYPES[$d->type] ?? $d->type, 'at' => $d->created_at->timezone('Asia/Baghdad')->format('Y-m-d H:i'),
                    'by' => $d->issuer?->name, 'valid' => $d->isValid(), 'reason' => $d->revoke_reason, 'url' => $d->verifyUrl()]),
            'programOptions' => Gate::allows('change-student-status', $student) && $student->status === 'active'
                ? Program::visibleTo($request->user())->where('is_active', true)->where('id', '!=', $currentProgramId)
                    ->whereHas('curricula', fn ($c) => $c->where('status', 'approved'))
                    ->with('department:id,name_ar')->orderBy('name_ar')->get()
                    ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name_ar, 'department' => $p->department->name_ar, 'years' => $p->duration_years])
                : [],
        ]);
    }

    public function changeStatus(Request $request, Student $student): RedirectResponse
    {
        Gate::authorize('change-student-status', $student);
        $data = $request->validate([
            'to' => ['required', Rule::in([...array_keys(Student::STATUSES), 'program_change'])],
            'program_id' => ['required_if:to,program_change', 'nullable', 'exists:programs,id'],
            'stage' => ['required_if:to,program_change', 'nullable', 'integer', 'min:1', 'max:6'],
            'order_id' => ['required', 'exists:administrative_orders,id'],
            'effective_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['order_id' => 'الأمر الإداري', 'program_id' => 'البرنامج', 'stage' => 'المرحلة']);

        if ($data['to'] === 'program_change') {
            $program = Program::visibleTo($request->user())->findOrFail($data['program_id']);
            $this->service->changeProgram($student, $program, (int) $data['stage'], AdministrativeOrder::findOrFail($data['order_id']), $request->user(), $data['notes'] ?? null);

            return back()->with('success', 'نُقل الطالب إلى «'.$program->name_ar.'». بقي رقمه الجامعي وسجله السابق كما هو.');
        }

        $this->service->changeStatus($student, $data['to'], AdministrativeOrder::findOrFail($data['order_id']), $request->user(), $data['notes'] ?? null, $data['effective_on'] ?? null);

        return back()->with('success', 'تم تغيير حالة الطالب إلى «'.Student::STATUSES[$data['to']].'».');
    }
}
