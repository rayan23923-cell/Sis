<?php

namespace App\Services;

use App\Models\DocumentRequest;
use App\Models\Student;
use App\Models\User;
use App\Notifications\Notice;
use App\Services\Documents\DocumentIssuer;
use App\Support\AuditContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Students ask for documents online; the registrar issues them (QR-verifiable) or refuses with a reason. */
class DocumentRequestService
{
    public function __construct(private readonly DocumentIssuer $issuer, private readonly Notifier $notifier) {}

    public function submit(Student $student, array $data, User $by): DocumentRequest
    {
        if ($data['type'] === 'graduation' && $student->status !== 'graduated') {
            throw new WorkflowException('وثيقة التخرج تُطلب بعد صدور أمر التخرج.');
        }
        if ($data['type'] === 'enrollment' && $student->status !== 'active') {
            throw new WorkflowException('تأييد الاستمرار بالدراسة للطالب المستمر فقط.');
        }
        if (DocumentRequest::where('student_id', $student->id)->where('status', 'submitted')->count() >= 3) {
            throw new WorkflowException('لديك 3 طلبات قيد المراجعة. انتظر إنجازها قبل طلب جديد.');
        }
        $request = AuditContext::as($by->id, 'طلب وثيقة إلكتروني', fn () => DocumentRequest::create([
            'student_id' => $student->id, 'type' => $data['type'], 'addressed_to' => $data['addressed_to'] ?? null,
            'purpose' => $data['purpose'] ?? null, 'notes' => $data['notes'] ?? null, 'status' => 'submitted',
        ]));
        if ($college = $student->currentProgram?->program?->collegeId()) {
            $this->notifier->send($this->notifier->staffOf($college, 'registrar', 'college_registrar'),
                new Notice('طلب وثيقة جديد', $student->name_ar.' — '.DocumentRequest::TYPES[$data['type']], route('requests.index'), 'info'));
        }

        return $request;
    }

    public function issue(DocumentRequest $request, User $by, ?string $note = null): void
    {
        $this->requireStatus($request, 'submitted');
        DB::transaction(function () use ($request, $by, $note) {
            $out = $this->issuer->issue($request->type, $request->student, $by, ['addressed_to' => $request->addressed_to, 'purpose' => $request->purpose]);
            $path = 'documents/'.$out['doc']->code.'.pdf';
            Storage::disk('local')->put($path, $out['pdf']);
            AuditContext::as($by->id, 'إصدار وثيقة مطلوبة', fn () => $request->update([
                'status' => 'ready', 'issued_document_id' => $out['doc']->id, 'file_path' => $path, 'response' => $note,
                'handled_by' => $by->id, 'handled_at' => now(),
            ]));
        });
        $this->tellStudent($request, 'وثيقتك جاهزة', DocumentRequest::TYPES[$request->type].' جاهزة للتنزيل من البوابة.', 'success');
    }

    public function reject(DocumentRequest $request, User $by, string $reason): void
    {
        $this->requireStatus($request, 'submitted');
        AuditContext::as($by->id, 'رفض طلب وثيقة', fn () => $request->update(['status' => 'rejected', 'response' => $reason, 'handled_by' => $by->id, 'handled_at' => now()]));
        $this->tellStudent($request, 'لم يُقبل طلبك', DocumentRequest::TYPES[$request->type].': '.$reason, 'danger');
    }

    public function deliver(DocumentRequest $request, User $by): void
    {
        $this->requireStatus($request, 'ready');
        AuditContext::as($by->id, 'تسليم وثيقة ورقية', fn () => $request->update(['status' => 'delivered']));
    }

    public function cancel(DocumentRequest $request, User $by): void
    {
        $this->requireStatus($request, 'submitted');
        AuditContext::as($by->id, 'إلغاء طلب وثيقة', fn () => $request->update(['status' => 'cancelled']));
    }

    private function tellStudent(DocumentRequest $request, string $title, string $body, string $kind): void
    {
        $user = $request->student->user;
        if ($user) {
            $this->notifier->send($user, new Notice($title, $body, route('portal.home', ['tab' => 'requests']), $kind));
        }
    }

    private function requireStatus(DocumentRequest $request, string $status): void
    {
        if ($request->status !== $status) {
            throw new WorkflowException('حالة الطلب الآن «'.DocumentRequest::STATUSES[$request->status].'».');
        }
    }
}
