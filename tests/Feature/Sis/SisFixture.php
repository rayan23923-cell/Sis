<?php

namespace Tests\Feature\Sis;

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
use Database\Seeders\PolicySeeder;

/** Two colleges, one program each, a few students and one offering per college. */
trait SisFixture
{
    protected User $admin;

    protected User $teacherA;

    protected User $committeeA;

    protected User $registrarB;

    protected CourseOffering $offeringA;

    protected CourseOffering $offeringB;

    protected function buildUniversity(int $studentsPerProgram = 4): void
    {
        $this->seed(PolicySeeder::class);
        $this->admin = $this->user('admin@t', 'super_admin');
        $uni = University::create(['code' => 'U', 'name_ar' => 'جامعة']);
        $year = AcademicYear::create(['name' => '2025-2026', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31', 'is_current' => true]);
        $term = AcademicTerm::create(['academic_year_id' => $year->id, 'code' => 'S1', 'name_ar' => 'الأول']);

        $offerings = [];
        foreach (['A' => 'bologna', 'B' => 'annual'] as $code => $system) {
            $college = College::create(['university_id' => $uni->id, 'code' => '0'.ord($code) % 10, 'name_ar' => 'كلية '.$code]);
            $dept = Department::create(['college_id' => $college->id, 'code' => $code, 'name_ar' => 'قسم '.$code]);
            $program = Program::create(['department_id' => $dept->id, 'code' => 'P'.$code, 'name_ar' => 'برنامج '.$code, 'study_system' => $system]);
            $cv = CurriculumVersion::create(['program_id' => $program->id, 'version' => 1, 'name' => 'v1', 'status' => 'approved']);
            $course = Course::create(['department_id' => $dept->id, 'code' => 'C'.$code, 'name_ar' => 'مادة '.$code, 'credit_hours' => 3, 'ects' => 6]);
            CurriculumCourse::create(['curriculum_version_id' => $cv->id, 'course_id' => $course->id, 'stage' => 1, 'credit_hours' => 3, 'ects' => 6]);
            for ($i = 0; $i < $studentsPerProgram; $i++) {
                app(StudentService::class)->admit(['name_ar' => "طالب {$code}{$i}", 'birth_date' => '2006-01-0'.($i + 1), 'allow_duplicate' => true], $program, $cv, $year, $this->admin);
            }
            $teacher = $this->user("teacher{$code}@t", 'teacher', null, $dept->id);
            $offering = CourseOffering::create(['course_id' => $course->id, 'academic_term_id' => $term->id, 'program_id' => $program->id, 'stage' => 1, 'teacher_id' => $teacher->id]);
            app(GradebookService::class)->ensureComponents($offering);
            app(EnrollmentService::class)->enrollCohort($offering, $this->admin);
            $offerings[$code] = [$offering, $teacher, $college];
        }

        [$this->offeringA, $this->teacherA, $collegeA] = $offerings['A'];
        [$this->offeringB, , $collegeB] = $offerings['B'];
        $this->committeeA = $this->user('committee@t', 'committee', $collegeA->id);
        $this->registrarB = $this->user('registrarB@t', 'college_registrar', $collegeB->id);
    }

    protected function user(string $email, string $role, ?int $college = null, ?int $dept = null): User
    {
        $u = User::factory()->create(['email' => $email]);
        RoleAssignment::create(['user_id' => $u->id, 'role' => $role, 'college_id' => $college, 'department_id' => $dept]);

        return $u;
    }

    /** Marks for every student: coursework and final given as fractions of the max. */
    protected function marksFor(CourseOffering $o, array $finals): array
    {
        $c = $o->components()->where('exam_round', 1)->get()->keyBy('code');
        $rows = [];
        foreach ($o->enrollments()->orderBy('id')->get()->values() as $i => $e) {
            $rows[$e->id] = [
                $c['coursework']->id => ['mark' => 30],
                $c['final']->id => ['mark' => $finals[$i] ?? 40],
            ];
        }

        return $rows;
    }
}
