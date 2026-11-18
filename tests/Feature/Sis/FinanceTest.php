<?php

namespace Tests\Feature\Sis;

use App\Models\AcademicYear;
use App\Models\FeeSchedule;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentInvoice;
use App\Models\User;
use App\Services\StudentAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use RefreshDatabase, SisFixture;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildUniversity();
        $this->accountant = $this->user('acc@t', 'accountant');
    }

    private function schedule(array $over = []): FeeSchedule
    {
        $this->actingAs($this->accountant)->post(route('finance.schedules.store'), $over + [
            'academic_year_id' => AcademicYear::first()->id, 'name' => 'موازي', 'admission_channel' => 'parallel', 'amount' => 1000000,
            'installments' => [['percent' => 50, 'due_on' => '2025-10-01'], ['percent' => 50, 'due_on' => '2099-02-01']], 'is_active' => true,
        ])->assertSessionHasNoErrors();

        return FeeSchedule::latest('id')->first();
    }

    public function test_only_students_a_schedule_applies_to_are_invoiced(): void
    {
        $parallel = Student::orderBy('id')->take(2)->get();
        $parallel->each->update(['admission_channel' => 'parallel']);
        $this->schedule();

        $this->actingAs($this->accountant)->post(route('finance.invoice-all'), ['year' => AcademicYear::first()->id])->assertSessionHas('success');
        $this->assertSame(2, StudentInvoice::count());
        $this->assertEqualsCanonicalizing($parallel->pluck('id')->all(), StudentInvoice::pluck('student_id')->all());

        $inv = StudentInvoice::with('installments')->first();
        $this->assertSame([500000, 500000], $inv->installments->pluck('amount')->all());
        // running again creates nothing new
        $this->actingAs($this->accountant)->post(route('finance.invoice-all'), ['year' => AcademicYear::first()->id])->assertSessionHas('error');
        $this->assertSame(2, StudentInvoice::count());
    }

    public function test_most_specific_schedule_wins_and_installment_rounding_sums(): void
    {
        $s = Student::first();
        $s->update(['admission_channel' => 'parallel']);
        $this->schedule();
        $program = $s->currentProgram->program_id;
        $this->schedule(['name' => 'برنامج', 'program_id' => $program, 'amount' => 1250000, 'installments' => [
            ['percent' => 33.3, 'due_on' => '2025-10-01'], ['percent' => 33.3, 'due_on' => '2026-01-01'], ['percent' => 33.4, 'due_on' => '2026-04-01'],
        ]]);

        $this->actingAs($this->accountant)->post(route('finance.student.invoice', $s), ['year' => AcademicYear::first()->id])->assertSessionHas('success');
        $inv = StudentInvoice::with('installments')->first();
        $this->assertSame(1250000, $inv->amount);
        $this->assertSame(1250000, $inv->installments->sum('amount'));
        $this->assertSame(0, $inv->installments->first()->amount % 1000);
    }

    public function test_payments_receipts_void_and_discount(): void
    {
        $s = Student::first();
        $s->update(['admission_channel' => 'parallel']);
        $this->schedule();
        $this->actingAs($this->accountant)->post(route('finance.student.invoice', $s), ['year' => AcademicYear::first()->id]);
        $inv = StudentInvoice::first();

        // overdue: the first installment was due in 2025
        $this->assertSame('overdue', $inv->fresh()->lines()[0]['status']);

        $this->actingAs($this->accountant)->post(route('finance.pay', $inv), ['amount' => 600000, 'paid_on' => now()->toDateString(), 'method' => 'cash'])->assertSessionHas('receipt_id');
        $this->actingAs($this->accountant)->post(route('finance.pay', $inv), ['amount' => 500000, 'paid_on' => now()->toDateString(), 'method' => 'cash'])->assertSessionHas('error');
        $this->actingAs($this->accountant)->post(route('finance.pay', $inv), ['amount' => 100, 'paid_on' => now()->toDateString(), 'method' => 'bank'])->assertSessionHasErrors('reference');

        $lines = $inv->fresh()->lines();
        $this->assertSame(['paid', 'partial'], array_column($lines, 'status'));

        $this->actingAs($this->accountant)->post(route('finance.discount', $inv), ['discount' => 400000, 'reason' => 'ذوو الشهداء'])->assertSessionHas('success');
        $this->assertSame('paid', $inv->fresh()->status);

        $p = Payment::first();
        $this->assertMatchesRegularExpression('/^R\d{2}-000001$/', $p->receipt_number);
        $this->actingAs($this->accountant)->post(route('finance.void', $p), ['reason' => 'خطأ في المبلغ'])->assertSessionHas('success');
        $this->assertSame('open', $inv->fresh()->status);
        $this->assertSame(600000, $inv->fresh()->load('payments')->balance());

        $this->expectException(\LogicException::class);
        $p->fresh()->update(['amount' => 1]);
    }

    public function test_receipt_pdf_and_access_rules(): void
    {
        $s = Student::first();
        $s->update(['admission_channel' => 'parallel']);
        $this->schedule();
        $this->actingAs($this->accountant)->post(route('finance.student.invoice', $s), ['year' => AcademicYear::first()->id]);
        $this->actingAs($this->accountant)->post(route('finance.pay', StudentInvoice::first()), ['amount' => 250000, 'paid_on' => now()->toDateString(), 'method' => 'cash']);
        $p = Payment::first();

        $this->actingAs($this->accountant)->get(route('finance.receipt', $p))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->accountant)->get(route('finance.index'))->assertOk()->assertInertia(fn ($pg) => $pg->component('Finance/Index')->where('totals.collected', 250000)->where('totals.invoices', 1));
        $this->actingAs($this->accountant)->get(route('finance.student', $s))->assertOk();

        // a teacher has no finance access; a registrar of another college cannot see this student
        $this->actingAs($this->teacherA)->get(route('finance.index'))->assertForbidden();
        $this->actingAs($this->registrarB)->post(route('finance.schedules.store'), [])->assertForbidden();
        $this->actingAs($this->accountant)->get(route('finance.receipt', $p))->assertOk();

        // the student sees their own fees and receipt in the portal
        app(StudentAccountService::class)->issue($s, $this->admin);
        $user = User::find($s->fresh()->user_id);
        $user->update(['must_change_password' => false]);
        $this->actingAs($user)->get(route('portal.home'))->assertInertia(fn ($pg) => $pg->where('fees.0.paid', 250000));
        $this->actingAs($user)->get(route('finance.receipt', $p))->assertOk();
        $other = Student::where('id', '!=', $s->id)->first();
        $this->actingAs($user)->get(route('finance.student', $other))->assertRedirect();
    }
}
