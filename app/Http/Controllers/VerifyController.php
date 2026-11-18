<?php

namespace App\Http\Controllers;

use App\Models\IssuedDocument;
use App\Models\Student;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Public check of a printed document by its code; revocation by staff. */
class VerifyController extends Controller
{
    public function form(Request $request): Response|RedirectResponse
    {
        if ($request->filled('code')) {
            return redirect()->route('verify', IssuedDocument::normalise($request->string('code')));
        }

        return Inertia::render('Verify', ['code' => null, 'document' => null]);
    }

    public function show(string $code): Response
    {
        $doc = IssuedDocument::where('code', IssuedDocument::normalise($code))->first();

        return Inertia::render('Verify', [
            'code' => $code,
            'document' => $doc ? [
                'type' => IssuedDocument::TYPES[$doc->type] ?? $doc->type,
                'valid' => $doc->isValid(),
                'official' => $doc->official,
                'issued_at' => $doc->created_at->timezone('Asia/Baghdad')->format('Y-m-d H:i'),
                'revoked_at' => $doc->revoked_at?->timezone('Asia/Baghdad')->format('Y-m-d'),
                'revoke_reason' => $doc->revoke_reason,
                'data' => $doc->snapshot,
                'university' => config('sis.university_name'),
            ] : null,
        ]);
    }

    public function revoke(Request $request, IssuedDocument $document): RedirectResponse
    {
        Gate::authorize('admit-students');
        abort_unless(Student::visibleTo($request->user())->whereKey($document->student_id)->exists(), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'السبب']);
        if (! $document->isValid()) {
            return back()->with('error', 'الوثيقة ملغاة مسبقاً.');
        }
        $document->update(['revoked_at' => now(), 'revoked_by' => $request->user()->id, 'revoke_reason' => $data['reason']]);

        return back()->with('success', 'أُلغيت الوثيقة '.$document->code.'. ستظهر «ملغاة» عند التحقق منها.');
    }
}
