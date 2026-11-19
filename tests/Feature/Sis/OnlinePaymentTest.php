<?php

namespace Tests\Feature\Sis;

use App\Models\AcademicYear;
use App\Models\OnlinePayment;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentInvoice;
use App\Models\User;
use App\Services\Payments\Gateways\ZainCashGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OnlinePaymentTest extends TestCase
{
    use RefreshDatabase, SisFixture;

    private User $accountant;

    private User $studentUser;

    private StudentInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildUniversity();
        $this->accountant = $this->user('acc@t', 'accountant');
        $student = Student::first();
        $student->update(['admission_channel' => 'parallel']);
        $this->studentUser = User::create(['name' => $student->name_ar, 'email' => 'st@x.test', 'password' => 'student-pass-2026', 'is_active' => true]);
        $student->update(['user_id' => $this->studentUser->id]);
        $this->actingAs($this->accountant)->post(route('finance.schedules.store'), [
            'academic_year_id' => AcademicYear::first()->id, 'name' => 'موازي', 'admission_channel' => 'parallel', 'amount' => 1000000,
            'installments' => [['percent' => 50, 'due_on' => '2025-10-01'], ['percent' => 50, 'due_on' => '2099-02-01']], 'is_active' => true,
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->accountant)->post(route('finance.student.invoice', $student), ['year' => AcademicYear::first()->id])->assertSessionHas('success');
        $this->invoice = StudentInvoice::firstOrFail();
    }

    public function test_student_pays_an_installment_once_and_gets_a_receipt(): void
    {
        $this->actingAs($this->studentUser)->get(route('portal.home', ['tab' => 'fees']))->assertOk()
            ->assertInertia(fn ($p) => $p->where('fees.0.next', 500000)->has('gateways.sandbox'));

        $res = $this->actingAs($this->studentUser)->post(route('payments.start', $this->invoice), ['gateway' => 'sandbox', 'what' => 'installment']);
        $op = OnlinePayment::firstOrFail();
        $res->assertRedirect(route('payments.sandbox', $op));
        $this->assertSame(500000, $op->amount);
        $this->assertSame('pending', $op->status);

        // coming back before paying changes nothing
        $this->get(route('payments.return', $op))->assertRedirect(route('payments.show', $op));
        $this->assertSame('pending', $op->fresh()->status);

        // nobody else sees it or pays it
        $other = $this->user('other@t', 'teacher');
        $this->actingAs($other)->get(route('payments.show', $op))->assertForbidden();
        $this->actingAs($other)->get(route('payments.sandbox', $op))->assertForbidden();

        $this->actingAs($this->studentUser)->post(route('payments.sandbox.decide', $op), ['approve' => true])->assertRedirect(route('payments.return', $op));
        $this->get(route('payments.return', $op));
        $op->refresh();
        $this->assertSame('paid', $op->status);
        $receipt = Payment::firstOrFail();
        $this->assertSame([500000, 'electronic', null], [$receipt->amount, $receipt->method, $receipt->received_by]);
        $this->assertSame($receipt->id, $op->payment_id);
        $this->assertSame(500000, $this->invoice->fresh()->balance());
        $this->assertSame(1, $this->studentUser->unreadNotifications()->count());

        // a repeated return, server call or check never pays twice
        $this->get(route('payments.return', $op));
        $this->post(route('payments.notify', 'sandbox'), ['id' => $op->external_id])->assertOk();
        $this->artisan('sis:payments-reconcile')->assertSuccessful();
        $this->assertSame(1, Payment::count());
    }

    public function test_payment_that_no_longer_fits_the_balance_waits_for_the_accountant(): void
    {
        $this->actingAs($this->studentUser)->post(route('payments.start', $this->invoice), ['gateway' => 'sandbox', 'what' => 'full']);
        $op = OnlinePayment::firstOrFail();
        $this->assertSame(1000000, $op->amount);
        // meanwhile the student paid half at the cashier
        $this->actingAs($this->accountant)->post(route('finance.pay', $this->invoice), ['amount' => 500000, 'paid_on' => today()->toDateString(), 'method' => 'cash'])->assertSessionHas('success');

        $this->actingAs($this->studentUser)->post(route('payments.sandbox.decide', $op), ['approve' => true]);
        $this->get(route('payments.return', $op));
        $op->refresh();
        $this->assertSame('review', $op->status);
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, $this->accountant->unreadNotifications()->count());

        $this->actingAs($this->accountant)->get(route('finance.student', $op->student_id))->assertInertia(fn ($p) => $p->where('invoices.0.online.0.status', 'review'));
        $this->actingAs($this->studentUser)->post(route('payments.reviewed', $op), ['note' => 'x'])->assertForbidden();
        $this->actingAs($this->accountant)->post(route('payments.reviewed', $op), ['note' => 'أعيد المبلغ للطالب'])->assertSessionHas('success');
        $this->assertNotNull($op->fresh()->reviewed_by);
    }

    public function test_declined_and_expired_payments_and_one_open_payment_at_a_time(): void
    {
        $this->actingAs($this->studentUser)->post(route('payments.start', $this->invoice), ['gateway' => 'sandbox', 'what' => 'installment']);
        $this->actingAs($this->studentUser)->post(route('payments.start', $this->invoice), ['gateway' => 'sandbox', 'what' => 'full']);
        $this->assertSame(1, OnlinePayment::count());
        $op = OnlinePayment::first();

        $this->actingAs($this->studentUser)->post(route('payments.sandbox.decide', $op), ['approve' => false]);
        $this->get(route('payments.return', $op));
        $this->assertSame('failed', $op->fresh()->status);

        $this->actingAs($this->studentUser)->post(route('payments.start', $this->invoice), ['gateway' => 'sandbox', 'what' => 'installment']);
        $second = OnlinePayment::latest('id')->first();
        $second->update(['expires_at' => now()->subMinute()]);
        $this->artisan('sis:payments-reconcile')->assertSuccessful();
        $this->assertSame('expired', $second->fresh()->status);
        $this->assertSame(0, Payment::count());

        // unknown gateway or a gateway that is not switched on is refused
        $this->actingAs($this->studentUser)->post(route('payments.start', $this->invoice), ['gateway' => 'fib', 'what' => 'full'])->assertSessionHasErrors('gateway');
    }

    public function test_fib_is_confirmed_by_asking_the_bank_not_by_the_callback(): void
    {
        config()->set('payments.gateways.fib', ['label' => 'FIB', 'base_url' => 'https://fib.test', 'client_id' => 'id', 'client_secret' => 'secret']);
        $paid = false;
        Http::fake([
            'fib.test/auth/*' => Http::response(['access_token' => 'tok', 'expires_in' => 300]),
            'fib.test/protected/v1/payments' => Http::response(['paymentId' => 'fib-1', 'readableCode' => 'ABCD-1234', 'qrCode' => 'data:image/png;base64,AAAA', 'personalAppLink' => 'https://fib.test/app']),
            'fib.test/protected/v1/payments/fib-1/status' => function () use (&$paid) {
                return Http::response(['paymentId' => 'fib-1', 'status' => $paid ? 'PAID' : 'UNPAID', 'amount' => ['amount' => 500000, 'currency' => 'IQD']]);
            },
        ]);

        $this->actingAs($this->studentUser)->post(route('payments.start', $this->invoice), ['gateway' => 'fib', 'what' => 'installment']);
        $op = OnlinePayment::firstOrFail();
        $this->assertSame('fib-1', $op->external_id);
        $this->actingAs($this->studentUser)->get(route('payments.show', $op))->assertInertia(fn ($p) => $p->where('payment.display.code', 'ABCD-1234')->where('own', true));

        // a forged "paid" callback does nothing while the bank says unpaid
        $this->post(route('payments.notify', 'fib'), ['id' => 'fib-1', 'status' => 'PAID'])->assertOk();
        $this->assertSame('pending', $op->fresh()->status);

        $paid = true;
        $this->post(route('payments.notify', 'fib'), ['id' => 'fib-1', 'status' => 'PAID'])->assertOk();
        $this->assertSame('paid', $op->fresh()->status);
        $this->assertSame(1, Payment::count());
        Http::assertSent(fn ($r) => $r->url() === 'https://fib.test/protected/v1/payments' && $r['monetaryValue']['amount'] === '500000');
    }

    public function test_zaincash_return_token_must_carry_a_valid_signature(): void
    {
        $b64 = fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $head = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = $b64(json_encode(['data' => ['transactionId' => 'zc-9'], 'exp' => time() + 600]));
        $sig = $b64(hash_hmac('sha256', "$head.$body", 'secret', true));

        $this->assertSame('zc-9', ZainCashGateway::decodeHs256("$head.$body.$sig", 'secret')['data']['transactionId']);
        $this->assertNull(ZainCashGateway::decodeHs256("$head.$body.$sig", 'other'));
        $this->assertNull(ZainCashGateway::decodeHs256("$head.$body.x$sig", 'secret'));
        $none = $b64(json_encode(['alg' => 'none']));
        $this->assertNull(ZainCashGateway::decodeHs256("$none.$body.", 'secret'));
    }
}
