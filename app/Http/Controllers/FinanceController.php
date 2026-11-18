<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\College;
use App\Models\FeeSchedule;
use App\Models\Payment;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentInvoice;
use App\Services\Documents\Pdf;
use App\Services\FeeService;
use App\Services\Imports\Sheet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceController extends Controller
{
    public function __construct(private readonly FeeService $fees) {}

    public function index(Request $request): Response
    {
        Gate::authorize('view-finance');
        $user = $request->user();
        $year = $this->year($request);
        $filters = $request->only(['q', 'college', 'program', 'status', 'channel', 'shift']);

        $all = $year ? $this->query($request, $year, $filters)->with(['payments', 'installments'])->get() : collect();
        if (($filters['status'] ?? null) === 'overdue') {
            $all = $all->filter(fn ($i) => collect($i->lines())->contains('status', 'overdue'))->values();
        }
        $page = max(1, $request->integer('page', 1));
        $perPage = 30;
        $rows = $all->sortBy(fn ($i) => $i->student->name_ar)->values()->slice(($page - 1) * $perPage, $perPage)->values()
            ->map(fn (StudentInvoice $i) => $this->row($i));

        return Inertia::render('Finance/Index', [
            'years' => AcademicYear::orderByDesc('starts_on')->get(['id', 'name']),
            'year' => $year?->id,
            'filters' => $filters,
            'totals' => $this->fees->totals($all),
            'rows' => $rows,
            'pagination' => ['page' => $page, 'pages' => max(1, (int) ceil($all->count() / $perPage)), 'total' => $all->count()],
            'colleges' => College::visibleTo($user)->orderBy('name_ar')->get(['id', 'name_ar']),
            'programs' => Program::visibleTo($user)->with('department:id,name_ar')->orderBy('name_ar')->get()->map(fn ($p) => ['id' => $p->id, 'name' => $p->name_ar, 'department' => $p->department->name_ar]),
            'channels' => Student::CHANNELS,
            'shifts' => Student::SHIFTS,
            'statuses' => StudentInvoice::STATUSES,
            'schedules' => $year ? FeeSchedule::where('academic_year_id', $year->id)->where('is_active', true)->count() : 0,
            'perm' => ['collect' => Gate::allows('collect-fees'), 'schedules' => Gate::allows('manage-fee-schedules')],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('view-finance');
        $year = $this->year($request) ?? abort(404);
        $filters = $request->only(['q', 'college', 'program', 'status', 'channel', 'shift']);
        $all = $this->query($request, $year, $filters)->with(['payments', 'installments'])->get()->sortBy(fn ($i) => $i->student->name_ar);
        if (($filters['status'] ?? null) === 'overdue') {
            $all = $all->filter(fn ($i) => collect($i->lines())->contains('status', 'overdue'));
        }
        $rows = $all->map(fn ($i) => array_values(array_intersect_key($this->row($i), array_flip(['number', 'name', 'program', 'stage', 'channel_label', 'shift_label', 'amount', 'discount', 'paid', 'balance', 'overdue', 'status_label']))))->values()->all();

        return Sheet::download("fees-{$year->name}.xlsx", 'الرسوم', ['الرقم الجامعي', 'الاسم', 'البرنامج', 'المرحلة', 'قناة القبول', 'الدراسة', 'المبلغ', 'التخفيض', 'المدفوع', 'المتبقي', 'متأخر السداد', 'الحالة'], $rows, [],
            ['الرسوم والأقساط — '.$year->name, 'أُعدّ في '.now()->timezone('Asia/Baghdad')->format('Y-m-d H:i').' بواسطة '.$request->user()->name]);
    }

    public function invoiceAll(Request $request): RedirectResponse
    {
        Gate::authorize('collect-fees');
        $data = $request->validate(['year' => ['required', 'exists:academic_years,id'], 'program' => ['nullable', 'exists:programs,id']]);
        $program = isset($data['program']) ? Program::find($data['program']) : null;
        if ($program) {
            abort_unless(Program::visibleTo($request->user())->whereKey($program->id)->exists(), 403);
        }
        $r = $this->fees->invoiceAll(AcademicYear::find($data['year']), $request->user(), $program);

        return back()->with($r['created'] ? 'success' : 'error', $r['created']
            ? "صدرت {$r['created']} مطالبة. لم تنطبق أي تعرفة على {$r['skipped']} طالباً (معفى أو بلا تعرفة)."
            : "لم تصدر أي مطالبة. لا توجد تعرفة تنطبق على {$r['skipped']} طالباً بلا مطالبة.");
    }

    // ---------- fee schedules ----------

    public function schedules(Request $request): Response
    {
        Gate::authorize('view-finance');
        $year = $this->year($request);

        return Inertia::render('Finance/Schedules', [
            'years' => AcademicYear::orderByDesc('starts_on')->get(['id', 'name']),
            'year' => $year?->id,
            'schedules' => $year ? FeeSchedule::where('academic_year_id', $year->id)->with(['college:id,name_ar', 'program:id,name_ar'])->withCount(['invoices' => fn ($q) => $q->where('status', '!=', 'cancelled')])
                ->orderByDesc('is_active')->orderBy('name')->get()->map(fn (FeeSchedule $f) => [
                    'id' => $f->id, 'name' => $f->name, 'college_id' => $f->college_id, 'program_id' => $f->program_id, 'college' => $f->college?->name_ar, 'program' => $f->program?->name_ar,
                    'admission_channel' => $f->admission_channel, 'study_shift' => $f->study_shift, 'stage' => $f->stage, 'amount' => $f->amount,
                    'installments' => $f->installments, 'is_active' => $f->is_active, 'source_reference' => $f->source_reference, 'invoices' => $f->invoices_count,
                ]) : [],
            'colleges' => College::orderBy('name_ar')->get(['id', 'name_ar']),
            'programs' => Program::with('department:id,name_ar,college_id')->orderBy('name_ar')->get()->map(fn ($p) => ['id' => $p->id, 'name' => $p->name_ar, 'department' => $p->department->name_ar, 'college_id' => $p->department->college_id]),
            'channels' => Student::CHANNELS,
            'shifts' => Student::SHIFTS,
            'canManage' => Gate::allows('manage-fee-schedules'),
        ]);
    }

    public function storeSchedule(Request $request): RedirectResponse
    {
        Gate::authorize('manage-fee-schedules');
        FeeSchedule::create($this->validatedSchedule($request) + ['created_by' => $request->user()->id]);

        return back()->with('success', 'أُضيفت التعرفة.');
    }

    public function updateSchedule(Request $request, FeeSchedule $schedule): RedirectResponse
    {
        Gate::authorize('manage-fee-schedules');
        $data = $this->validatedSchedule($request);
        if ($schedule->invoices()->where('status', '!=', 'cancelled')->exists()) {
            // invoices already carry the old amount; only the name, reference and active flag may change
            $schedule->update(array_intersect_key($data, array_flip(['name', 'source_reference', 'is_active'])));

            return back()->with('success', 'عُدّل الاسم والحالة فقط، لأن مطالبات صدرت بهذه التعرفة. لتغيير المبلغ أوقفها وأضف تعرفة جديدة.');
        }
        $schedule->update($data);

        return back()->with('success', 'عُدّلت التعرفة.');
    }

    public function destroySchedule(FeeSchedule $schedule): RedirectResponse
    {
        Gate::authorize('manage-fee-schedules');
        if ($schedule->invoices()->exists()) {
            return back()->with('error', 'صدرت مطالبات بهذه التعرفة، لا يمكن حذفها. يمكنك إيقافها.');
        }
        $schedule->delete();

        return back()->with('success', 'حُذفت التعرفة.');
    }

    // ---------- one student ----------

    public function student(Request $request, Student $student): Response
    {
        Gate::authorize('view-finance');
        $this->assertVisible($request, $student);
        $student->load(['currentProgram.program.department.college', 'invoices.year', 'invoices.payments.receiver:id,name', 'invoices.installments', 'invoices.program:id,name_ar']);
        $current = AcademicYear::where('is_current', true)->first();
        $schedule = $current ? $this->fees->scheduleFor($student, $current) : null;

        return Inertia::render('Finance/Student', [
            'student' => [
                'id' => $student->id, 'name' => $student->name_ar, 'number' => $student->university_number, 'status' => $student->status,
                'program' => $student->currentProgram?->program->name_ar, 'college' => $student->currentProgram?->program->department->college->name_ar,
                'stage' => $student->currentProgram?->stage, 'channel' => Student::CHANNELS[$student->admission_channel] ?? $student->admission_channel,
                'shift' => Student::SHIFTS[$student->study_shift ?? 'morning'],
            ],
            'invoices' => $student->invoices->map(fn (StudentInvoice $i) => $this->invoiceDetail($i)),
            'currentYear' => $current ? ['id' => $current->id, 'name' => $current->name, 'has_invoice' => $student->invoices->where('academic_year_id', $current->id)->where('status', '!=', 'cancelled')->isNotEmpty()] : null,
            'schedule' => $schedule ? ['name' => $schedule->name, 'amount' => $schedule->amount, 'installments' => count($schedule->installments)] : null,
            'methods' => Payment::METHODS,
            'today' => today()->toDateString(),
            'perm' => ['collect' => Gate::allows('collect-fees')],
        ]);
    }

    public function invoiceStudent(Request $request, Student $student): RedirectResponse
    {
        Gate::authorize('collect-fees');
        $this->assertVisible($request, $student);
        $year = AcademicYear::findOrFail($request->validate(['year' => ['required', 'exists:academic_years,id']])['year']);
        $this->fees->invoice($student, $year, $request->user());

        return back()->with('success', 'صدرت المطالبة.');
    }

    public function pay(Request $request, StudentInvoice $invoice): RedirectResponse
    {
        Gate::authorize('collect-fees');
        $this->assertVisible($request, $invoice->student);
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'paid_on' => ['required', 'date'],
            'method' => ['required', Rule::in(array_keys(Payment::METHODS))],
            'reference' => ['required_unless:method,cash', 'nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['amount' => 'المبلغ', 'paid_on' => 'تاريخ الدفع', 'method' => 'طريقة الدفع', 'reference' => 'رقم الإيصال المصرفي أو المعاملة']);
        $payment = $this->fees->pay($invoice->load('payments'), $data, $request->user());

        return back()->with('success', "سُجّلت الدفعة بالوصل {$payment->receipt_number}.")->with('receipt_id', $payment->id);
    }

    public function discount(Request $request, StudentInvoice $invoice): RedirectResponse
    {
        Gate::authorize('collect-fees');
        $this->assertVisible($request, $invoice->student);
        $data = $request->validate(['discount' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:255']], [], ['discount' => 'التخفيض', 'reason' => 'سبب التخفيض']);
        $this->fees->discount($invoice->load('payments'), $data['discount'], $data['reason'], $request->user());

        return back()->with('success', 'حُدّث التخفيض وأُعيد توزيع الأقساط.');
    }

    public function cancel(Request $request, StudentInvoice $invoice): RedirectResponse
    {
        Gate::authorize('collect-fees');
        $this->assertVisible($request, $invoice->student);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'سبب الإلغاء']);
        $this->fees->cancel($invoice->load('payments'), $data['reason'], $request->user());

        return back()->with('success', 'أُلغيت المطالبة.');
    }

    public function void(Request $request, Payment $payment): RedirectResponse
    {
        Gate::authorize('collect-fees');
        $this->assertVisible($request, $payment->student);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'سبب الإلغاء']);
        $this->fees->void($payment, $data['reason'], $request->user());

        return back()->with('success', "أُلغي الوصل {$payment->receipt_number}.");
    }

    public function receipt(Request $request, Payment $payment, Pdf $pdf): HttpResponse
    {
        $user = $request->user();
        $own = $payment->student->user_id === $user->id;
        if (! $own) {
            Gate::authorize('view-finance');
            $this->assertVisible($request, $payment->student);
        }
        $payment->load(['invoice.year', 'invoice.payments', 'invoice.installments', 'student.currentProgram.program.department.college', 'receiver:id,name']);
        $inv = $payment->invoice;

        return $pdf->response('pdf.receipt', [
            'title' => 'وصل قبض', 'ref' => $payment->receipt_number,
            'university' => config('sis.university_name'), 'unit' => $payment->student->currentProgram?->program->department->college->name_ar,
            'printedBy' => $user->name, 'printedAt' => now()->timezone('Asia/Baghdad')->format('Y-m-d H:i'),
            'payment' => $payment, 'student' => $payment->student, 'invoice' => $inv,
            'method' => Payment::METHODS[$payment->method] ?? $payment->method,
            'balance' => $inv->balance(),
        ], 'receipt-'.$payment->receipt_number.'.pdf');
    }

    // ---------- helpers ----------

    private function query(Request $request, AcademicYear $year, array $f): Builder
    {
        return StudentInvoice::query()->where('academic_year_id', $year->id)
            ->whereHas('student', fn ($s) => $s->visibleTo($request->user()))
            ->with(['student:id,name_ar,university_number', 'program:id,name_ar,department_id', 'program.department:id,college_id'])
            ->when($f['status'] ?? null, fn ($q, $s) => $s === 'overdue' ? $q->where('status', 'open') : $q->where('status', $s), fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->when($f['program'] ?? null, fn ($q, $p) => $q->where('program_id', $p))
            ->when($f['college'] ?? null, fn ($q, $c) => $q->whereHas('program.department', fn ($d) => $d->where('college_id', $c)))
            ->when($f['channel'] ?? null, fn ($q, $c) => $q->where('admission_channel', $c))
            ->when($f['shift'] ?? null, fn ($q, $s) => $q->where('study_shift', $s))
            ->when($f['q'] ?? null, fn ($q, $s) => $q->whereHas('student', fn ($st) => $st->where('name_ar', 'like', "%{$s}%")->orWhere('university_number', 'like', "{$s}%")));
    }

    private function row(StudentInvoice $i): array
    {
        $lines = collect($i->lines());

        return [
            'id' => $i->id, 'student_id' => $i->student_id, 'number' => $i->student->university_number, 'name' => $i->student->name_ar,
            'program' => $i->program?->name_ar, 'stage' => $i->stage,
            'channel' => $i->admission_channel, 'channel_label' => Student::CHANNELS[$i->admission_channel] ?? $i->admission_channel,
            'shift' => $i->study_shift, 'shift_label' => Student::SHIFTS[$i->study_shift ?? 'morning'] ?? '',
            'amount' => $i->amount, 'discount' => $i->discount, 'paid' => $i->paid(), 'balance' => $i->balance(),
            'overdue' => (int) $lines->where('status', 'overdue')->sum(fn ($l) => $l['amount'] - $l['paid']),
            'next_due' => $lines->first(fn ($l) => in_array($l['status'], ['due', 'partial', 'overdue'], true))['due_on'] ?? null,
            'status' => $i->status, 'status_label' => StudentInvoice::STATUSES[$i->status],
        ];
    }

    private function invoiceDetail(StudentInvoice $i): array
    {
        return [
            'id' => $i->id, 'year' => $i->year->name, 'program' => $i->program?->name_ar, 'stage' => $i->stage,
            'channel' => Student::CHANNELS[$i->admission_channel] ?? $i->admission_channel, 'shift' => Student::SHIFTS[$i->study_shift ?? 'morning'] ?? '',
            'amount' => $i->amount, 'discount' => $i->discount, 'discount_reason' => $i->discount_reason, 'net' => $i->net(),
            'paid' => $i->paid(), 'balance' => $i->balance(), 'status' => $i->status, 'cancel_reason' => $i->cancel_reason,
            'lines' => $i->lines(),
            'payments' => $i->payments->map(fn (Payment $p) => [
                'id' => $p->id, 'receipt' => $p->receipt_number, 'amount' => $p->amount, 'paid_on' => $p->paid_on->toDateString(),
                'method' => Payment::METHODS[$p->method] ?? $p->method, 'reference' => $p->reference, 'by' => $p->receiver?->name,
                'voided' => $p->voided_at !== null, 'void_reason' => $p->void_reason,
            ])->values(),
        ];
    }

    private function assertVisible(Request $request, Student $student): void
    {
        abort_unless(Student::visibleTo($request->user())->whereKey($student->id)->exists(), 404);
    }

    private function year(Request $request): ?AcademicYear
    {
        return AcademicYear::find($request->integer('year')) ?? AcademicYear::where('is_current', true)->first() ?? AcademicYear::latest('starts_on')->first();
    }

    private function validatedSchedule(Request $request): array
    {
        $data = $request->validate([
            'academic_year_id' => ['required', 'exists:academic_years,id'],
            'name' => ['required', 'string', 'max:150'],
            'college_id' => ['nullable', 'exists:colleges,id'],
            'program_id' => ['nullable', 'exists:programs,id'],
            'admission_channel' => ['nullable', Rule::in(array_keys(Student::CHANNELS))],
            'study_shift' => ['nullable', Rule::in(array_keys(Student::SHIFTS))],
            'stage' => ['nullable', 'integer', 'min:1', 'max:7'],
            'amount' => ['required', 'integer', 'min:1000'],
            'installments' => ['required', 'array', 'min:1', 'max:12'],
            'installments.*.percent' => ['required', 'numeric', 'min:1', 'max:100'],
            'installments.*.due_on' => ['required', 'date'],
            'is_active' => ['boolean'],
            'source_reference' => ['nullable', 'string', 'max:255'],
        ], [], ['name' => 'اسم التعرفة', 'amount' => 'المبلغ', 'installments' => 'الأقساط', 'installments.*.percent' => 'نسبة القسط', 'installments.*.due_on' => 'تاريخ الاستحقاق']);

        if (abs(collect($data['installments'])->sum('percent') - 100) > 0.01) {
            throw ValidationException::withMessages(['installments' => 'مجموع نسب الأقساط يجب أن يساوي 100%.']);
        }
        if (! empty($data['program_id'])) {
            $data['college_id'] = Program::with('department')->find($data['program_id'])->department->college_id;
        }
        $data['installments'] = collect($data['installments'])->sortBy('due_on')->values()->map(fn ($l) => ['percent' => (float) $l['percent'], 'due_on' => $l['due_on']])->all();

        return $data;
    }
}
