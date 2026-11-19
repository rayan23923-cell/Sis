<?php

namespace App\Http\Controllers;

use App\Models\OnlinePayment;
use App\Models\Student;
use App\Models\StudentInvoice;
use App\Services\Payments\Gateways;
use App\Services\Payments\Gateways\SandboxGateway;
use App\Services\Payments\OnlinePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class OnlinePaymentController extends Controller
{
    public function __construct(private readonly OnlinePaymentService $online, private readonly Gateways $gateways) {}

    // ---- the student ----

    public function start(Request $request, StudentInvoice $invoice): HttpResponse
    {
        $student = Student::where('user_id', $request->user()->id)->firstOrFail();
        abort_unless($invoice->student_id === $student->id, 403);
        $data = $request->validate([
            'gateway' => ['required', Rule::in(array_keys($this->gateways->available()))],
            'what' => ['required', Rule::in(['installment', 'full'])],
        ]);
        $payment = $this->online->start($invoice, $data['gateway'], $data['what'], $request->user());

        return $payment->redirect_url
            ? Inertia::location($payment->redirect_url)
            : redirect()->route('payments.show', $payment);
    }

    public function show(Request $request, OnlinePayment $onlinePayment): Response
    {
        $this->authorizeView($request, $onlinePayment);
        $p = $onlinePayment->load(['invoice.year:id,name', 'student:id,name_ar,university_number,user_id', 'payment:id,receipt_number']);
        $own = $p->student->user_id === $request->user()->id;

        return Inertia::render('Payments/Show', [
            'payment' => $this->present($p) + ['display' => $p->status === 'pending' ? $p->display : null, 'resume' => $p->status === 'pending' ? $p->redirect_url : null],
            'own' => $own,
            'statuses' => OnlinePayment::STATUSES,
        ]);
    }

    public function check(Request $request, OnlinePayment $onlinePayment): JsonResponse|RedirectResponse
    {
        $this->authorizeView($request, $onlinePayment);
        $p = $this->online->refresh($onlinePayment);
        if ($request->wantsJson()) {
            return response()->json(['status' => $p->status]);
        }

        return back();
    }

    public function cancel(Request $request, OnlinePayment $onlinePayment): RedirectResponse
    {
        abort_unless($onlinePayment->student->user_id === $request->user()->id, 403);
        $this->online->cancel($onlinePayment, $request->user());

        return redirect()->route('portal.home', ['tab' => 'fees']);
    }

    // ---- the gateway ----

    /** The payer comes back from the gateway. Nothing here is trusted: it only prompts a status check. */
    public function back(Request $request, OnlinePayment $onlinePayment): RedirectResponse
    {
        if (blank($onlinePayment->external_id) && ($id = $this->gateways->get($onlinePayment->gateway)->externalIdFrom($request))) {
            $onlinePayment->update(['external_id' => $id]);
        }
        $this->online->refresh($onlinePayment);

        return redirect()->route('payments.show', $onlinePayment);
    }

    /** The gateway's server call. It only names a payment; its state is read back from the gateway. */
    public function notify(Request $request, string $gateway): JsonResponse
    {
        $id = $this->gateways->get($gateway)->externalIdFrom($request);
        $payment = $id ? OnlinePayment::where('gateway', $gateway)->where('external_id', $id)->first() : null;
        if ($payment) {
            $this->online->refresh($payment);
        }

        return response()->json(['ok' => true]);
    }

    // ---- the pretend gateway ----

    public function sandbox(Request $request, OnlinePayment $onlinePayment): Response
    {
        $this->authorizeSandbox($request, $onlinePayment);

        return Inertia::render('Payments/Sandbox', ['payment' => $this->present($onlinePayment->load('invoice.year:id,name', 'student:id,name_ar,university_number'))]);
    }

    public function sandboxDecide(Request $request, OnlinePayment $onlinePayment): RedirectResponse
    {
        $this->authorizeSandbox($request, $onlinePayment);
        app(SandboxGateway::class)->decide($onlinePayment, $request->boolean('approve'));

        return redirect()->route('payments.return', $onlinePayment);
    }

    // ---- the accountant ----

    public function reviewed(Request $request, OnlinePayment $onlinePayment): RedirectResponse
    {
        Gate::authorize('collect-fees');
        abort_unless(Student::visibleTo($request->user())->whereKey($onlinePayment->student_id)->exists(), 403);
        $data = $request->validate(['note' => ['required', 'string', 'max:150']]);
        $this->online->markReviewed($onlinePayment, $data['note'], $request->user());

        return back()->with('success', 'سُجّلت المراجعة.');
    }

    // ----

    /** @return array<string, mixed> */
    public static function present(OnlinePayment $p): array
    {
        return [
            'uuid' => $p->uuid, 'gateway' => config("payments.gateways.{$p->gateway}.label", $p->gateway), 'gateway_key' => $p->gateway,
            'amount' => $p->amount, 'status' => $p->status, 'message' => $p->message, 'created_at' => $p->created_at?->format('Y-m-d H:i'),
            'expires_at' => $p->expires_at?->toIso8601String(), 'year' => $p->invoice?->year?->name,
            'student' => $p->student ? ['id' => $p->student->id, 'name' => $p->student->name_ar, 'number' => $p->student->university_number] : null,
            'receipt' => $p->payment ? ['id' => $p->payment->id, 'number' => $p->payment->receipt_number] : null,
            'reviewed' => (bool) $p->reviewed_by, 'reference' => $p->external_id,
        ];
    }

    private function authorizeView(Request $request, OnlinePayment $p): void
    {
        $own = $p->student()->value('user_id') === $request->user()->id;
        abort_unless($own || (Gate::allows('view-finance') && Student::visibleTo($request->user())->whereKey($p->student_id)->exists()), 403);
    }

    private function authorizeSandbox(Request $request, OnlinePayment $p): void
    {
        abort_unless($p->gateway === 'sandbox' && app(SandboxGateway::class)->enabled() && $p->status === 'pending', 404);
        abort_unless($p->student()->value('user_id') === $request->user()->id, 403);
    }
}
