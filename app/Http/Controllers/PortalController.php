<?php

namespace App\Http\Controllers;

use App\Models\DocumentRequest;
use App\Models\Enrollment;
use App\Models\OnlinePayment;
use App\Models\Student;
use App\Models\StudentInvoice;
use App\Models\TimetableSlot;
use App\Services\AttendanceService;
use App\Services\Documents\TranscriptService;
use App\Services\Payments\Gateways;
use App\Services\Payments\OnlinePaymentService;
use App\Services\TimetableService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** What a student sees: their record, locked results by year, current courses and their transcript. */
class PortalController extends Controller
{
    public function home(Request $request, TranscriptService $transcripts, AttendanceService $attendance, Gateways $gateways, OnlinePaymentService $online): Response|RedirectResponse
    {
        $student = Student::where('user_id', $request->user()->id)->first();
        if (! $student) {
            return redirect()->route('dashboard');
        }
        $t = $transcripts->build($student);
        $student->load(['statusChanges' => fn ($q) => $q->latest('effective_on'), 'statusChanges.order']);

        $current = Enrollment::where('student_id', $student->id)->where('status', '!=', 'withdrawn')
            ->whereHas('offering.term.year', fn ($y) => $y->where('is_current', true))
            ->with(['offering.course', 'offering.term', 'offering.teacher:id,name', 'results' => fn ($q) => $q->where('is_current', true)->where('status', 'locked')])
            ->get()->sortBy(fn ($e) => [$e->offering->term->code, $e->offering->course->code])->values();
        $absence = $attendance->forEnrollments($current);
        $current = $current->map(function ($e) use ($absence) {
            $r = $e->results->sortByDesc('exam_round')->first();

            return [
                'code' => $e->offering->course->code, 'name' => $e->offering->course->name_ar, 'term' => $e->offering->term->name_ar,
                'teacher' => $e->offering->teacher?->name, 'credits' => (float) $e->offering->course->credit_hours, 'carried' => (bool) $e->is_carried,
                'result' => $r ? ['mark' => (float) $r->rounded_mark, 'grade' => $r->grade_name_ar, 'outcome' => $r->outcome, 'round' => $r->exam_round] : null,
                'absence' => $absence[$e->id]['sessions'] ? $absence[$e->id] : null,
            ];
        });

        return Inertia::render('Portal/Home', [
            'student' => [
                'id' => $student->id, 'name' => $student->name_ar, 'name_en' => $student->name_en, 'number' => $student->university_number,
                'status' => $student->status, 'status_label' => $t['status'], 'stage' => $t['stage'], 'system' => $t['system'],
                'program' => $t['program']?->name_ar, 'department' => $t['program']?->department->name_ar, 'college' => $t['program']?->department->college->name_ar,
                'admission_year' => $student->admissionYear?->name,
            ],
            'current' => $current,
            'years' => collect($t['years'])->reverse()->values(),
            'official' => $t['official'],
            'fees' => StudentInvoice::where('student_id', $student->id)->where('status', '!=', 'cancelled')->with(['year:id,name', 'installments', 'payments'])->latest('id')->get()
                ->map(fn (StudentInvoice $i) => [
                    'id' => $i->id, 'year' => $i->year->name, 'net' => $i->net(), 'discount' => $i->discount, 'paid' => $i->paid(), 'balance' => $i->balance(),
                    'status' => $i->status, 'lines' => $i->lines(),
                    'payments' => $i->payments->whereNull('voided_at')->map(fn ($p) => ['id' => $p->id, 'receipt' => $p->receipt_number, 'amount' => $p->amount, 'paid_on' => $p->paid_on->toDateString()])->values(),
                    'next' => $i->status === 'open' ? $online->amountFor($i, 'installment') : 0,
                    'online' => OnlinePayment::where('student_invoice_id', $i->id)->whereIn('status', ['pending', 'review'])->latest('id')->get()
                        ->map(fn (OnlinePayment $p) => ['uuid' => $p->uuid, 'gateway' => config("payments.gateways.{$p->gateway}.label"), 'amount' => $p->amount, 'status' => $p->status]),
                ]),
            'gateways' => $gateways->available(),
            'minimumOnline' => (int) config('payments.minimum'),
            'timetable' => app(TimetableService::class)->present(app(TimetableService::class)->forStudent($student, TimetableService::currentTerms()->pluck('id')->all())),
            'days' => TimetableSlot::DAYS,
            'requests' => DocumentRequest::where('student_id', $student->id)->latest()->limit(20)->get()->map(fn (DocumentRequest $r) => [
                'id' => $r->id, 'type' => DocumentRequest::TYPES[$r->type], 'status' => $r->status, 'status_label' => DocumentRequest::STATUSES[$r->status],
                'addressed_to' => $r->addressed_to, 'response' => $r->response, 'at' => $r->created_at->timezone('Asia/Baghdad')->format('Y-m-d'),
                'file' => in_array($r->status, ['ready', 'delivered'], true) && $r->file_path ? route('requests.file', $r) : null,
            ]),
            'requestTypes' => collect(DocumentRequest::TYPES)->only(array_filter(['transcript', $student->status === 'active' ? 'enrollment' : null, $student->status === 'graduated' ? 'graduation' : null])),
            'history' => $student->statusChanges->map(fn ($c) => [
                'to' => Student::STATUSES[$c->to_status] ?? $c->to_status, 'on' => $c->effective_on?->toDateString(), 'order' => $c->order?->reference(), 'notes' => $c->notes,
            ]),
        ]);
    }

    public function password(): Response
    {
        return Inertia::render('Portal/Password');
    }
}
