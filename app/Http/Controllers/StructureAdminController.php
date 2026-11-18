<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\College;
use App\Models\Course;
use App\Models\CurriculumCourse;
use App\Models\CurriculumVersion;
use App\Models\Department;
use App\Models\Program;
use App\Models\University;
use App\Services\WorkflowException;
use App\Support\AuditContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Editing the academic structure. Units are deactivated, never deleted. An approved curriculum is
 * frozen: a change starts a new draft version copied from it, and students keep their version.
 */
class StructureAdminController extends Controller
{
    private const NAMES = ['code' => 'الرمز', 'name_ar' => 'الاسم', 'name_en' => 'الاسم بالإنكليزية'];

    public function saveCollege(Request $request, ?College $college = null): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('colleges')->ignore($college?->id)],
            'name_ar' => ['required', 'string', 'max:190'], 'name_en' => ['nullable', 'string', 'max:190'], 'is_active' => ['boolean'],
        ], [], self::NAMES);
        $college ? $college->update($data) : College::create($data + ['university_id' => University::firstOrCreate(['code' => 'UNI'], ['name_ar' => config('sis.university_name')])->id]);

        return back()->with('success', 'حُفظت الكلية.');
    }

    public function saveDepartment(Request $request, ?Department $department = null): RedirectResponse
    {
        $data = $request->validate([
            'college_id' => ['required', 'exists:colleges,id'],
            'code' => ['required', 'string', 'max:20', Rule::unique('departments')->where('college_id', $request->input('college_id'))->ignore($department?->id)],
            'name_ar' => ['required', 'string', 'max:190'], 'name_en' => ['nullable', 'string', 'max:190'], 'is_active' => ['boolean'],
        ], [], self::NAMES);
        $department ? $department->update($data) : Department::create($data);

        return back()->with('success', 'حُفظ القسم.');
    }

    public function saveProgram(Request $request, ?Program $program = null): RedirectResponse
    {
        $data = $request->validate([
            'department_id' => ['required', 'exists:departments,id'],
            'code' => ['required', 'string', 'max:30', Rule::unique('programs')->ignore($program?->id)],
            'name_ar' => ['required', 'string', 'max:190'], 'name_en' => ['nullable', 'string', 'max:190'],
            'study_system' => ['required', Rule::in(['bologna', 'semester', 'annual'])],
            'degree' => ['required', 'string', 'max:30'], 'duration_years' => ['required', 'integer', 'min:1', 'max:7'], 'is_active' => ['boolean'],
        ], [], self::NAMES + ['study_system' => 'نظام الدراسة']);
        if ($program && $program->study_system !== $data['study_system'] && $program->studentPrograms()->exists()) {
            throw new WorkflowException('لا يمكن تغيير نظام الدراسة لبرنامج فيه طلبة. أنشئ برنامجاً جديداً.');
        }
        DB::transaction(function () use ($program, $data) {
            if ($program) {
                $program->update($data);
            } else {
                $p = Program::create($data);
                CurriculumVersion::create(['program_id' => $p->id, 'version' => 1, 'name' => 'منهج '.$p->name_ar, 'status' => 'draft']);
            }
        });

        return back()->with('success', $program ? 'حُفظ البرنامج.' : 'أُنشئ البرنامج مع مسودة منهج فارغة.');
    }

    public function courses(Request $request): Response
    {
        $filters = $request->only(['q', 'department']);

        return Inertia::render('Structure/Courses', [
            'courses' => Course::with('department:id,name_ar')
                ->when($filters['department'] ?? null, fn ($q, $d) => $q->where('department_id', $d))
                ->when($filters['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('name_ar', 'like', "%{$s}%")->orWhere('code', 'like', "{$s}%")))
                ->orderBy('code')->paginate(30)->withQueryString()
                ->through(fn (Course $c) => $c->only(['id', 'department_id', 'code', 'name_ar', 'name_en', 'credit_hours', 'ects', 'theory_hours', 'practical_hours', 'is_active']) + ['department' => $c->department->name_ar]),
            'filters' => $filters,
            'departments' => $this->departments(),
        ]);
    }

    public function saveCourse(Request $request, ?Course $course = null): RedirectResponse
    {
        $data = $request->validate([
            'department_id' => ['required', 'exists:departments,id'],
            'code' => ['required', 'string', 'max:30', Rule::unique('courses')->ignore($course?->id)],
            'name_ar' => ['required', 'string', 'max:190'], 'name_en' => ['nullable', 'string', 'max:190'],
            'credit_hours' => ['required', 'numeric', 'min:0', 'max:30'], 'ects' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'theory_hours' => ['nullable', 'integer', 'min:0', 'max:40'], 'practical_hours' => ['nullable', 'integer', 'min:0', 'max:40'],
            'is_active' => ['boolean'],
        ], [], self::NAMES + ['credit_hours' => 'الوحدات']);
        $data['theory_hours'] ??= 0;
        $data['practical_hours'] ??= 0;
        $course ? $course->update($data) : Course::create($data);

        return back()->with('success', 'حُفظت المادة.');
    }

    // ---------- curricula ----------

    public function newCurriculumVersion(Request $request, CurriculumVersion $curriculum): RedirectResponse
    {
        if (CurriculumVersion::where('program_id', $curriculum->program_id)->where('status', 'draft')->exists()) {
            throw new WorkflowException('توجد مسودة منهج لهذا البرنامج. أكملها أو اعتمدها أولاً.');
        }
        $new = DB::transaction(function () use ($curriculum) {
            $next = CurriculumVersion::where('program_id', $curriculum->program_id)->max('version') + 1;
            $copy = CurriculumVersion::create(['program_id' => $curriculum->program_id, 'version' => $next, 'name' => preg_replace('/\s*\(إصدار \d+\)$/u', '', $curriculum->name).' (إصدار '.$next.')', 'status' => 'draft']);
            foreach ($curriculum->courses()->with('prerequisites')->get() as $cc) {
                $c = $copy->courses()->create($cc->only(['course_id', 'stage', 'term_no', 'is_mandatory', 'credit_hours', 'ects']));
                $c->prerequisites()->sync($cc->prerequisites->pluck('id'));
            }

            return $copy;
        });

        return redirect()->route('curricula.show', $new)->with('success', 'أُنشئت مسودة الإصدار '.$new->version.' منسوخة من الإصدار '.$curriculum->version.'.');
    }

    public function addCurriculumCourse(Request $request, CurriculumVersion $curriculum): RedirectResponse
    {
        $this->requireDraft($curriculum);
        $data = $request->validate([
            'course_id' => ['required', 'exists:courses,id', Rule::unique('curriculum_courses')->where('curriculum_version_id', $curriculum->id)],
            'stage' => ['required', 'integer', 'min:1', 'max:7'], 'term_no' => ['nullable', 'integer', 'in:1,2'],
            'is_mandatory' => ['boolean'], 'prerequisites' => ['array'], 'prerequisites.*' => ['exists:courses,id'],
        ], ['course_id.unique' => 'المادة موجودة في المنهج.'], ['course_id' => 'المادة', 'stage' => 'المرحلة']);
        $course = Course::findOrFail($data['course_id']);
        $cc = $curriculum->courses()->create([
            'course_id' => $course->id, 'stage' => $data['stage'], 'term_no' => $data['term_no'] ?? null,
            'is_mandatory' => $data['is_mandatory'] ?? true, 'credit_hours' => $course->credit_hours, 'ects' => $course->ects,
        ]);
        $cc->prerequisites()->sync(array_diff($data['prerequisites'] ?? [], [$course->id]));

        return back()->with('success', 'أُضيفت '.$course->name_ar.' إلى المرحلة '.$data['stage'].'.');
    }

    public function removeCurriculumCourse(CurriculumVersion $curriculum, CurriculumCourse $item): RedirectResponse
    {
        $this->requireDraft($curriculum);
        abort_unless($item->curriculum_version_id === $curriculum->id, 404);
        $item->prerequisites()->detach();
        $item->delete();

        return back()->with('success', 'أُزيلت المادة من المسودة.');
    }

    public function approveCurriculum(Request $request, CurriculumVersion $curriculum): RedirectResponse
    {
        $this->requireDraft($curriculum);
        $data = $request->validate(['approval_reference' => ['required', 'string', 'max:190'], 'effective_from_year_id' => ['nullable', 'exists:academic_years,id']], [], ['approval_reference' => 'مرجع المصادقة']);
        if (! $curriculum->courses()->exists()) {
            throw new WorkflowException('لا يمكن اعتماد منهج بلا مواد.');
        }
        AuditContext::as($request->user()->id, 'اعتماد منهج', fn () => $curriculum->update($data + ['status' => 'approved', 'approved_at' => now(), 'approved_by' => $request->user()->id]));

        return back()->with('success', 'اعتُمد المنهج. الطلبة الجدد يُقبلون عليه، والحاليون يبقون على إصداراتهم.');
    }

    // ---------- calendar ----------

    public function saveYear(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'regex:/^\d{4}-\d{4}$/', 'unique:academic_years,name'],
            'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after:starts_on'],
            'terms' => ['required', Rule::in(['semesters', 'annual', 'both'])],
        ], ['name.regex' => 'اكتب السنة بالشكل 2026-2027.'], ['name' => 'السنة']);
        DB::transaction(function () use ($data) {
            $y = AcademicYear::create(['name' => $data['name'], 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on']]);
            if ($data['terms'] !== 'annual') {
                AcademicTerm::create(['academic_year_id' => $y->id, 'code' => 'S1', 'name_ar' => 'الفصل الأول', 'name_en' => 'Semester 1']);
                AcademicTerm::create(['academic_year_id' => $y->id, 'code' => 'S2', 'name_ar' => 'الفصل الثاني', 'name_en' => 'Semester 2']);
            }
            if ($data['terms'] !== 'semesters') {
                AcademicTerm::create(['academic_year_id' => $y->id, 'code' => 'Y', 'name_ar' => 'السنة الدراسية', 'name_en' => 'Academic year']);
            }
        });

        return back()->with('success', 'أُنشئت السنة '.$data['name'].'.');
    }

    public function makeYearCurrent(AcademicYear $year): RedirectResponse
    {
        DB::transaction(function () use ($year) {
            AcademicYear::where('is_current', true)->update(['is_current' => false]);
            $year->update(['is_current' => true]);
        });

        return back()->with('success', 'أصبحت '.$year->name.' السنة الحالية.');
    }

    public function saveTerm(Request $request, AcademicTerm $term): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(AcademicTerm::STATUSES)],
            'starts_on' => ['nullable', 'date'], 'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ]);
        $term->update($data);

        return back()->with('success', 'حُفظ '.$term->name_ar.'.');
    }

    private function requireDraft(CurriculumVersion $c): void
    {
        if ($c->status !== 'draft') {
            throw new WorkflowException('المنهج المعتمد لا يُعدَّل. أنشئ إصداراً جديداً منه.');
        }
    }

    private function departments()
    {
        return Department::with('college:id,name_ar')->orderBy('college_id')->orderBy('name_ar')->get()
            ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name_ar, 'college_id' => $d->college_id, 'college' => $d->college->name_ar]);
    }
}
