<?php

namespace App\Http\Controllers;

use App\Models\Program;
use App\Models\Student;
use App\Services\Documents\Pdf;
use App\Services\StudentAccountService;
use App\Services\WorkflowException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/** Issuing student portal accounts. The temporary password is shown once and never stored in clear. */
class StudentAccountController extends Controller
{
    public function __construct(private readonly StudentAccountService $accounts) {}

    public function issue(Request $request, Student $student): RedirectResponse
    {
        Gate::authorize('admit-students');
        abort_unless(Student::visibleTo($request->user())->whereKey($student->id)->exists(), 403);
        $password = $this->accounts->issue($student, $request->user());

        return back()->with('credentials', [['name' => $student->name_ar, 'number' => $student->university_number, 'password' => $password]]);
    }

    /** Accounts for every active student of a program stage who has none yet, as printable slips. */
    public function bulk(Request $request, Pdf $pdf): Response
    {
        Gate::authorize('admit-students');
        $data = $request->validate(['program' => ['required', 'exists:programs,id'], 'stage' => ['required', 'integer', 'min:1']]);
        $program = Program::visibleTo($request->user())->with('department.college')->findOrFail($data['program']);
        $students = Student::where('status', 'active')->whereNull('user_id')
            ->whereHas('currentProgram', fn ($q) => $q->where('program_id', $program->id)->where('stage', $data['stage']))
            ->orderBy('name_ar')->limit(500)->get();
        if ($students->isEmpty()) {
            throw new WorkflowException('كل طلبة هذه المرحلة المستمرين لديهم حسابات. لإعادة كلمة مرور طالب افتح صفحته.');
        }
        $slips = $students->map(fn ($s) => ['name' => $s->name_ar, 'number' => $s->university_number, 'password' => $this->accounts->issue($s, $request->user())]);

        return $pdf->response('pdf.portal-slips', [
            'slips' => $slips->all(), 'program' => $program, 'stage' => (int) $data['stage'], 'url' => url('/login'),
            'title' => 'حسابات بوابة الطالب', 'unit' => $program->department->college->name_ar.' — '.$program->name_ar, 'ref' => 'المرحلة '.$data['stage'],
            'university' => config('sis.university_name'), 'printedBy' => $request->user()->name, 'printedAt' => now()->timezone('Asia/Baghdad')->format('Y-m-d H:i'),
        ], 'portal-accounts-'.$program->code.'-'.$data['stage'].'.pdf');
    }
}
