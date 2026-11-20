<?php

namespace App\Http\Controllers;

use App\Models\DocumentRequest;
use App\Models\Student;
use App\Services\DocumentRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Online document requests: students ask from the portal, the registrar issues or refuses. */
class DocumentRequestController extends Controller
{
    public function __construct(private readonly DocumentRequestService $requests) {}

    public function index(Request $request): Response
    {
        Gate::authorize('handle-document-requests');
        $f = $request->validate(['status' => ['nullable', Rule::in(array_keys(DocumentRequest::STATUSES))], 'type' => ['nullable', Rule::in(array_keys(DocumentRequest::TYPES))], 'q' => ['nullable', 'string', 'max:100']]);
        $status = $f['status'] ?? 'submitted';
        $visible = Student::visibleTo($request->user())->select('students.id');

        $rows = DocumentRequest::whereIn('student_id', $visible)
            ->where('status', $status)
            ->when($f['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($f['q'] ?? null, fn ($q, $s) => $q->whereHas('student', fn ($st) => $st->where('name_ar', 'like', "%{$s}%")->orWhere('university_number', 'like', "{$s}%")))
            ->with(['student:id,name_ar,university_number,status', 'student.currentProgram.program:id,name_ar', 'handler:id,name', 'document:id,code'])
            ->orderBy($status === 'submitted' ? 'created_at' : 'handled_at', $status === 'submitted' ? 'asc' : 'desc')
            ->paginate(30)->withQueryString()
            ->through(fn (DocumentRequest $r) => [
                'id' => $r->id, 'type' => $r->type, 'type_label' => DocumentRequest::TYPES[$r->type], 'status' => $r->status,
                'student_id' => $r->student_id, 'name' => $r->student->name_ar, 'number' => $r->student->university_number,
                'program' => $r->student->currentProgram?->program?->name_ar, 'stage' => $r->student->currentProgram?->stage,
                'addressed_to' => $r->addressed_to, 'purpose' => $r->purpose, 'notes' => $r->notes, 'response' => $r->response,
                'created_at' => $r->created_at->timezone('Asia/Baghdad')->format('Y-m-d H:i'), 'handler' => $r->handler?->name,
                'handled_at' => $r->handled_at?->timezone('Asia/Baghdad')->format('Y-m-d H:i'), 'code' => $r->document?->code,
            ]);

        $library = \App\Models\Connector::active('library', 'clearance')
            ? \App\Models\LibraryStatus::whereIn('student_id', collect($rows->items())->pluck('student_id'))->get()->keyBy('student_id')->map->summary()
            : null;

        return Inertia::render('Requests/Index', [
            'library' => $library,
            'rows' => $rows, 'filters' => ['status' => $status] + $f, 'types' => DocumentRequest::TYPES, 'statuses' => DocumentRequest::STATUSES,
            'counts' => DocumentRequest::whereIn('student_id', $visible)->where('status', 'submitted')->count(),
        ]);
    }

    public function issue(Request $request, DocumentRequest $documentRequest): RedirectResponse
    {
        $this->authorizeHandle($request, $documentRequest);
        $this->requests->issue($documentRequest, $request->user(), $request->validate(['note' => ['nullable', 'string', 'max:300']])['note'] ?? null);

        return back()->with('success', 'صدرت الوثيقة وأُبلغ الطالب.');
    }

    public function reject(Request $request, DocumentRequest $documentRequest): RedirectResponse
    {
        $this->authorizeHandle($request, $documentRequest);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:300']], [], ['reason' => 'السبب'])['reason'];
        $this->requests->reject($documentRequest, $request->user(), $reason);

        return back()->with('success', 'رُفض الطلب وأُبلغ الطالب بالسبب.');
    }

    public function deliver(Request $request, DocumentRequest $documentRequest): RedirectResponse
    {
        $this->authorizeHandle($request, $documentRequest);
        $this->requests->deliver($documentRequest, $request->user());

        return back()->with('success', 'سُجّل تسليم الوثيقة.');
    }

    // ---- the student ----

    public function store(Request $request): RedirectResponse
    {
        $student = Student::where('user_id', $request->user()->id)->firstOrFail();
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(DocumentRequest::TYPES))],
            'addressed_to' => ['nullable', 'string', 'max:150'], 'purpose' => ['nullable', 'string', 'max:200'], 'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['type' => 'نوع الوثيقة', 'addressed_to' => 'الجهة', 'purpose' => 'الغرض']);
        $this->requests->submit($student, $data, $request->user());

        return back()->with('success', 'أُرسل طلبك. ستصلك إشعار عند جاهزية الوثيقة.');
    }

    public function cancel(Request $request, DocumentRequest $documentRequest): RedirectResponse
    {
        abort_unless($documentRequest->student->user_id === $request->user()->id, 403);
        $this->requests->cancel($documentRequest, $request->user());

        return back()->with('success', 'أُلغي الطلب.');
    }

    /** The issued PDF: the student who asked, or the staff who handle requests for that college. */
    public function file(Request $request, DocumentRequest $documentRequest): HttpResponse
    {
        $own = $documentRequest->student->user_id === $request->user()->id;
        if (! $own) {
            $this->authorizeHandle($request, $documentRequest);
        }
        abort_unless($documentRequest->file_path && Storage::disk('local')->exists($documentRequest->file_path) && in_array($documentRequest->status, ['ready', 'delivered'], true), 404);

        return response(Storage::disk('local')->get($documentRequest->file_path), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$documentRequest->type.'-'.$documentRequest->student->university_number.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function authorizeHandle(Request $request, DocumentRequest $r): void
    {
        Gate::authorize('handle-document-requests');
        abort_unless(Student::visibleTo($request->user())->whereKey($r->student_id)->exists(), 403);
    }
}
