<?php

namespace App\Providers;

use App\Models\CourseOffering;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use App\Services\PolicyResolver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PolicyResolver::class);
    }

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // super_admin may do anything except enter marks for someone else's course.
        Gate::before(fn (User $u, string $ability) => $u->hasRole('super_admin') && $ability !== 'enter-marks' ? true : null);

        Gate::define('manage-structure', fn (User $u) => $u->hasRole('registrar'));
        Gate::define('manage-policies', fn (User $u) => $u->hasRole('rules_admin'));
        Gate::define('view-audit', fn (User $u) => $u->hasRole('auditor', 'registrar'));
        Gate::define('manage-users', fn (User $u) => false);

        Gate::define('view-students', fn (User $u) => $u->hasRole('registrar', 'college_registrar', 'dean', 'head', 'committee', 'auditor'));
        Gate::define('admit-students', fn (User $u) => $u->hasRole('registrar', 'college_registrar'));
        Gate::define('change-student-status', fn (User $u, Student $s) => $u->hasRole('registrar')
            || ($s->currentProgram && $u->hasRoleIn($s->currentProgram->program->collegeId(), 'college_registrar')));
        Gate::define('issue-orders', fn (User $u) => $u->hasRole('registrar', 'college_registrar', 'dean', 'committee'));

        Gate::define('manage-offerings', fn (User $u) => $u->hasRole('registrar', 'college_registrar', 'head'));
        Gate::define('view-offering', fn (User $u, CourseOffering $o) => $o->teacher_id === $u->id
            || CourseOffering::whereKey($o->id)->visibleTo($u)->exists());
        Gate::define('enter-marks', fn (User $u, CourseOffering $o) => $o->teacher_id === $u->id
            || ($u->hasRole('super_admin') && $o->teacher_id === null));
        Gate::define('approve-results', fn (User $u, CourseOffering $o) => $u->hasRoleIn($o->collegeId(), 'committee', 'dean'));
        Gate::define('view-progression', fn (User $u) => $u->hasRole('registrar', 'college_registrar', 'dean', 'committee', 'head', 'auditor'));
        Gate::define('decide-progression', fn (User $u, Program $p) => $u->hasRole('registrar') || $u->hasRoleIn($p->collegeId(), 'committee', 'dean'));
        Gate::define('correct-results', fn (User $u, CourseOffering $o) => $u->hasRoleIn($o->collegeId(), 'committee', 'dean', 'registrar'));
    }
}
