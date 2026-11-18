<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\AdministrativeOrder;
use App\Models\CourseOffering;
use App\Models\Program;
use App\Services\Imports\MarksImport;
use App\Services\Imports\StudentImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    private const FILE = ['required', 'file', 'max:5120', 'mimes:xlsx,xls,csv,txt'];

    public function studentsForm(Request $request, StudentImport $import): Response
    {
        Gate::authorize('admit-students');
        $user = $request->user();
        $preview = $request->filled('token') ? $import->get($request->string('token'), $user) : null;

        return Inertia::render('Students/Import', [
            'programs' => Program::visibleTo($user)->where('is_active', true)->with('department:id,name_ar')->orderBy('name_ar')->get()
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name_ar, 'department' => $p->department->name_ar, 'years' => $p->duration_years]),
            'years' => AcademicYear::orderByDesc('starts_on')->get(['id', 'name', 'is_current']),
            'orders' => AdministrativeOrder::where('type', 'admission')->latest('issued_on')->limit(30)->get()->map(fn ($o) => ['id' => $o->id, 'label' => $o->reference().' — '.$o->title]),
            'orderTypes' => AdministrativeOrder::TYPES,
            'token' => $preview ? $request->string('token')->toString() : null,
            'preview' => $preview ? [
                'program' => Program::find($preview['program_id'])?->name_ar,
                'year' => AcademicYear::find($preview['year_id'])?->name,
                'stage' => $preview['stage'],
                'rows' => $preview['rows'],
            ] : null,
        ]);
    }

    public function studentsTemplate(StudentImport $import): StreamedResponse
    {
        Gate::authorize('admit-students');

        return $import->template();
    }

    public function studentsPreview(Request $request, StudentImport $import): RedirectResponse
    {
        Gate::authorize('admit-students');
        $data = $request->validate([
            'file' => self::FILE,
            'program_id' => ['required', 'exists:programs,id'],
            'year_id' => ['required', 'exists:academic_years,id'],
            'stage' => ['required', 'integer', 'min:1', 'max:6'],
        ], [], ['file' => 'الملف', 'program_id' => 'البرنامج', 'year_id' => 'العام الدراسي']);
        $program = Program::visibleTo($request->user())->findOrFail($data['program_id']);
        $token = $import->preview($request->file('file')->getRealPath(), $program, AcademicYear::findOrFail($data['year_id']), (int) $data['stage'], $request->user());

        return redirect()->route('students.import', ['token' => $token]);
    }

    public function studentsCommit(Request $request, StudentImport $import): RedirectResponse
    {
        Gate::authorize('admit-students');
        $data = $request->validate([
            'token' => ['required', 'string'],
            'include_duplicates' => ['boolean'],
            'order_id' => ['nullable', 'exists:administrative_orders,id'],
        ]);
        $numbers = $import->commit($data['token'], $request->user(), (bool) ($data['include_duplicates'] ?? false),
            isset($data['order_id']) ? AdministrativeOrder::find($data['order_id']) : null);

        return redirect()->route('students.index')->with('success', 'قُبل '.count($numbers).' طالباً. الأرقام الجامعية من '.reset($numbers).' إلى '.end($numbers).'.');
    }

    public function marksTemplate(CourseOffering $offering, MarksImport $import): StreamedResponse
    {
        Gate::authorize('enter-marks', $offering);

        return $import->template($offering);
    }

    public function marksImport(Request $request, CourseOffering $offering, MarksImport $import): RedirectResponse
    {
        Gate::authorize('enter-marks', $offering);
        $request->validate(['file' => self::FILE], [], ['file' => 'الملف']);
        $r = $import->import($offering, $request->user(), $request->file('file')->getRealPath());

        return back()->with('success', "قُرئ {$r['rows']} طالباً وحُفظت {$r['saved']} درجة كمسودة. راجعها ثم اضغط «حفظ واحتساب».");
    }
}
