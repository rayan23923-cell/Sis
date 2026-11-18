<?php

namespace Database\Seeders;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\College;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\CurriculumCourse;
use App\Models\CurriculumVersion;
use App\Models\Department;
use App\Models\Program;
use App\Models\RoleAssignment;
use App\Models\University;
use App\Models\User;
use App\Services\EnrollmentService;
use App\Services\GradebookService;
use App\Services\StudentService;
use App\Support\AuditContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A small demonstration university: two colleges (one Bologna, one annual), four programs,
 * 120 students and course offerings at every step of the grading workflow. Runs once.
 */
class DemoSeeder extends Seeder
{
    private const FIRST = ['محمد', 'علي', 'حسين', 'أحمد', 'مصطفى', 'حيدر', 'زينب', 'فاطمة', 'مريم', 'نور', 'سارة', 'رقية', 'عباس', 'يوسف', 'زهراء', 'آية', 'عمر', 'كرار', 'هدى', 'دعاء', 'سجاد', 'مرتضى', 'بنين', 'رسل'];

    private const FATHER = ['جاسم', 'كاظم', 'عبد الله', 'حسن', 'صالح', 'إبراهيم', 'خالد', 'سعد', 'جواد', 'هادي', 'ناصر', 'كريم', 'فاضل', 'رحيم', 'عادل', 'ماجد'];

    private const GRAND = ['محمد', 'علي', 'حمزة', 'عبد الحسين', 'جبار', 'سلمان', 'طه', 'نوري', 'شاكر', 'عبود'];

    private const MOTHER = ['خديجة', 'أمل', 'سعاد', 'نضال', 'وفاء', 'هناء', 'إيمان', 'بشرى', 'سهام', 'ليلى'];

    private const FEMALE = ['زينب', 'فاطمة', 'مريم', 'نور', 'سارة', 'رقية', 'زهراء', 'آية', 'هدى', 'دعاء', 'بنين', 'رسل'];

    public function run(): void
    {
        if (University::exists()) {
            return;
        }
        mt_srand(2025);
        $admin = User::whereHas('roleAssignments', fn ($q) => $q->where('role', 'super_admin'))->first();

        AuditContext::as($admin?->id, 'بيانات تجريبية', function () use ($admin) {
            $this->build($admin);
        });
    }

    private function build(User $admin): void
    {
        $uni = University::create(['code' => 'UNI', 'name_ar' => config('sis.university_name'), 'name_en' => 'University']);
        $year = AcademicYear::create(['name' => '2025-2026', 'starts_on' => '2025-09-15', 'ends_on' => '2026-08-31', 'is_current' => true]);
        $s1 = AcademicTerm::create(['academic_year_id' => $year->id, 'code' => 'S1', 'name_ar' => 'الفصل الأول', 'name_en' => 'Semester 1', 'starts_on' => '2025-09-15', 'ends_on' => '2026-01-31', 'status' => 'exams']);
        AcademicTerm::create(['academic_year_id' => $year->id, 'code' => 'S2', 'name_ar' => 'الفصل الثاني', 'name_en' => 'Semester 2', 'starts_on' => '2026-02-15', 'ends_on' => '2026-06-30', 'status' => 'planning']);
        $annual = AcademicTerm::create(['academic_year_id' => $year->id, 'code' => 'Y', 'name_ar' => 'السنة الدراسية', 'name_en' => 'Academic year', 'starts_on' => '2025-09-15', 'ends_on' => '2026-06-30', 'status' => 'exams']);

        $eng = College::create(['university_id' => $uni->id, 'code' => '03', 'name_ar' => 'كلية الهندسة', 'name_en' => 'College of Engineering']);
        $eco = College::create(['university_id' => $uni->id, 'code' => '07', 'name_ar' => 'كلية الإدارة والاقتصاد', 'name_en' => 'College of Administration and Economics']);

        $setup = [
            [$eng, 'CE', 'هندسة الحاسوب', 'Computer Engineering', 'bologna', $s1, [
                ['CE101', 'الرياضيات الهندسية 1', 'Engineering Mathematics I', 6],
                ['CE102', 'أساسيات البرمجة', 'Programming Fundamentals', 7],
                ['CE103', 'الدوائر الكهربائية', 'Electric Circuits', 6],
                ['CE104', 'الرسم الهندسي', 'Engineering Drawing', 4],
                ['CE105', 'اللغة العربية', 'Arabic Language', 3],
                ['CE106', 'حقوق الإنسان والديمقراطية', 'Human Rights and Democracy', 2],
            ]],
            [$eng, 'CIV', 'الهندسة المدنية', 'Civil Engineering', 'bologna', $s1, [
                ['CIV101', 'الرياضيات الهندسية 1', 'Engineering Mathematics I', 6],
                ['CIV102', 'الميكانيك الهندسي', 'Engineering Mechanics', 6],
                ['CIV103', 'مواد البناء', 'Building Materials', 5],
                ['CIV104', 'المساحة', 'Surveying', 5],
                ['CIV105', 'اللغة الإنكليزية', 'English Language', 3],
            ]],
            [$eco, 'ACC', 'المحاسبة', 'Accounting', 'annual', $annual, [
                ['ACC101', 'مبادئ المحاسبة', 'Principles of Accounting', 3],
                ['ACC102', 'مبادئ الاقتصاد', 'Principles of Economics', 3],
                ['ACC103', 'مبادئ الإدارة', 'Principles of Management', 3],
                ['ACC104', 'الرياضيات للعلوم الإدارية', 'Business Mathematics', 2],
                ['ACC105', 'الحاسوب', 'Computer Skills', 2],
            ]],
            [$eco, 'BA', 'إدارة الأعمال', 'Business Administration', 'annual', $annual, [
                ['BA101', 'مبادئ الإدارة', 'Principles of Management', 3],
                ['BA102', 'مبادئ الاقتصاد', 'Principles of Economics', 3],
                ['BA103', 'السلوك التنظيمي', 'Organizational Behavior', 3],
                ['BA104', 'الإحصاء', 'Statistics', 2],
            ]],
        ];

        $password = AdminSeeder::$password ?? config('sis.admin.password');
        if (! $password) {
            $password = Str::password(16, symbols: false);
            Storage::disk('local')->append('admin-password.txt', "demo accounts password: {$password}\n");
        }
        $mkUser = function (string $email, string $name, string $role, ?int $college = null, ?int $dept = null) use ($password) {
            $u = User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => $password, 'locale' => 'ar']);
            $u->forceFill(['email_verified_at' => now()])->save();
            RoleAssignment::firstOrCreate(['user_id' => $u->id, 'role' => $role, 'college_id' => $college, 'department_id' => $dept]);

            return $u;
        };

        $mkUser('registrar@sis.local', 'مسجل الجامعة', 'registrar');
        $mkUser('rules@sis.local', 'مسؤول القواعد الأكاديمية', 'rules_admin');
        $mkUser('auditor@sis.local', 'المدقق الداخلي', 'auditor');
        $mkUser('dean.eng@sis.local', 'عميد كلية الهندسة', 'dean', $eng->id);
        $mkUser('committee.eng@sis.local', 'اللجنة الامتحانية — الهندسة', 'committee', $eng->id);
        $mkUser('registrar.eng@sis.local', 'مسجل كلية الهندسة', 'college_registrar', $eng->id);
        $mkUser('committee.eco@sis.local', 'اللجنة الامتحانية — الإدارة والاقتصاد', 'committee', $eco->id);
        $mkUser('registrar.eco@sis.local', 'مسجل كلية الإدارة والاقتصاد', 'college_registrar', $eco->id);

        $students = app(StudentService::class);
        $enrollments = app(EnrollmentService::class);
        $gradebook = app(GradebookService::class);
        $teacherNo = 0;
        $offeringNo = 0;

        foreach ($setup as [$college, $code, $nameAr, $nameEn, $system, $term, $courses]) {
            $dept = Department::create(['college_id' => $college->id, 'code' => $code, 'name_ar' => 'قسم '.$nameAr, 'name_en' => $nameEn]);
            $program = Program::create(['department_id' => $dept->id, 'code' => $code.'-BSC', 'name_ar' => 'بكالوريوس '.$nameAr, 'name_en' => 'B.Sc. '.$nameEn, 'study_system' => $system, 'duration_years' => $college->is($eng) ? 5 : 4]);
            $curriculum = CurriculumVersion::create(['program_id' => $program->id, 'version' => 1, 'name' => 'منهج '.$nameAr.' 2025', 'effective_from_year_id' => $year->id, 'status' => 'approved', 'approval_reference' => 'منهج تجريبي', 'approved_at' => now()]);
            $mkUser('head.'.strtolower($code).'@sis.local', 'رئيس قسم '.$nameAr, 'head', null, $dept->id);

            $courseModels = [];
            foreach ($courses as [$cCode, $cAr, $cEn, $units]) {
                $course = Course::create([
                    'department_id' => $dept->id, 'code' => $cCode, 'name_ar' => $cAr, 'name_en' => $cEn,
                    'credit_hours' => $system === 'bologna' ? max(2, intdiv($units, 2)) : $units,
                    'ects' => $system === 'bologna' ? $units : null,
                    'theory_hours' => 2, 'practical_hours' => $units > 4 ? 2 : 0,
                ]);
                CurriculumCourse::create(['curriculum_version_id' => $curriculum->id, 'course_id' => $course->id, 'stage' => 1, 'term_no' => $system === 'bologna' ? 1 : null, 'credit_hours' => $course->credit_hours, 'ects' => $course->ects]);
                $courseModels[] = $course;
            }

            for ($i = 0; $i < 30; $i++) {
                $first = self::FIRST[mt_rand(0, count(self::FIRST) - 1)];
                $students->admit([
                    'name_ar' => $first.' '.self::FATHER[mt_rand(0, count(self::FATHER) - 1)].' '.self::GRAND[mt_rand(0, count(self::GRAND) - 1)],
                    'mother_name_ar' => self::MOTHER[mt_rand(0, count(self::MOTHER) - 1)].' '.self::GRAND[mt_rand(0, count(self::GRAND) - 1)],
                    'birth_date' => sprintf('%d-%02d-%02d', mt_rand(2005, 2007), mt_rand(1, 12), mt_rand(1, 28)),
                    'gender' => in_array($first, self::FEMALE, true) ? 'f' : 'm',
                    'national_id' => (string) mt_rand(100000000000, 199999999999),
                    'phone' => '07'.mt_rand(70, 80).mt_rand(1000000, 9999999),
                    'admission_channel' => ['central', 'central', 'central', 'parallel', 'evening'][mt_rand(0, 4)],
                    'allow_duplicate' => true,
                ], $program, $curriculum, $year, $admin);
            }

            foreach ($courseModels as $course) {
                $teacherNo++;
                $teacher = $mkUser("teacher{$teacherNo}@sis.local", 'م. '.self::FIRST[$teacherNo % 6].' '.self::FATHER[$teacherNo % 16], 'teacher', null, $dept->id);
                $offering = CourseOffering::create(['course_id' => $course->id, 'academic_term_id' => $term->id, 'program_id' => $program->id, 'stage' => 1, 'section' => 'A', 'teacher_id' => $teacher->id, 'capacity' => 40]);
                $gradebook->ensureComponents($offering);
                $enrollments->enrollCohort($offering, $admin);
                $this->driveWorkflow($offering->fresh(), $teacher, $admin, $offeringNo++, $gradebook);
            }
        }
    }

    /** Leave offerings at different steps so every screen has something to show. */
    private function driveWorkflow(CourseOffering $offering, User $teacher, User $admin, int $n, GradebookService $gb): void
    {
        $stage = $n % 5; // 0 = no marks, 1 = coursework only, 2 = computed, 3 = submitted, 4 = locked
        if ($stage === 0) {
            return;
        }
        $components = $gb->editableComponents($offering, 1)->keyBy('code');
        $rows = [];
        foreach ($gb->eligible($offering, 1) as $e) {
            $ability = mt_rand(35, 98) / 100;
            $cw = $components['coursework'];
            $fi = $components['final'];
            $rows[$e->id][$cw->id] = ['mark' => round(min(1, $ability + mt_rand(-8, 8) / 100) * $cw->max_mark)];
            if ($stage >= 2) {
                $absent = mt_rand(1, 40) === 1;
                $rows[$e->id][$fi->id] = $absent ? ['absent' => true] : ['mark' => round(max(0, min(1, $ability + mt_rand(-15, 10) / 100)) * $fi->max_mark)];
            }
        }
        AuditContext::as($teacher->id, null, fn () => $gb->saveMarks($offering, $teacher, $rows));
        if ($stage >= 2) {
            $gb->compute($offering, $teacher);
        }
        if ($stage >= 3) {
            $gb->submit($offering, $teacher);
        }
        if ($stage >= 4) {
            $gb->approve($offering, $admin);
            $gb->lock($offering->fresh(), $admin);
        }
    }
}
